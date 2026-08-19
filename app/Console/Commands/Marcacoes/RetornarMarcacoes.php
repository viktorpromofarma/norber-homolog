<?php

namespace App\Console\Commands\Marcacoes;

use App\Console\UrlBaseNorber;
use App\Http\BodyRequisition;
use App\Http\Headers;
use App\Models\Logs;
use App\Models\MarcacoesPontos;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetornarMarcacoes extends Command
{
    // Numero de requisicoes simultaneas contra a API do Norber
    const CONCURRENCY = 15;

    // Quantidade de linhas por upsert em lote
    // SQL Server aceita no máximo 2100 parâmetros por statement; cada linha usa 6 colunas (2100/6=350)
    const UPSERT_CHUNK_SIZE = 300;

    // Modificado: Adicionar opções de data no signature
    protected $signature = 'norber:retornar-marcacoes
                            {--start-date= : Data de início (formato: YYYY-MM-DD)}
                            {--end-date= : Data de fim (formato: YYYY-MM-DD)} ';

    protected $description = "Listar marcações de pontos" . PHP_EOL .
        "Modo de Uso: Data Inicial = (formato: YYYY-MM-DD) | Data Final = (formato: YYYY-MM-DD) | Conceito = (1 para Empresa, 3 para Matrícula) | Codigo Externo= (Número com base no conceito)";


    protected function UrlBaseNorberApi()
    {
        $UrlBaseNorber = new UrlBaseNorber();
        return $UrlBaseNorber->getUrlBaseNorber();
    }

    public function handle()
    {
        // variaveis que serão atribuidas no comando
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $conceito = 3;



        // Validar se as datas foram fornecidas
        if (!$startDate || !$endDate) {
            $this->error('Por favor, forneça ambas as datas: --start-date e --end-date');
            return 1;
        }

        $client = new Client();
        $headers = Headers::getHeaders();
        $url_base = $this->UrlBaseNorberApi();
        $command = 'marcacao/RetornaMarcacoes';


        $matriculas = $this->getTodasMatriculas()->values();

        $linhasParaUpsert = [];
        $falhas = 0;

        $requests = function ($matriculas) use ($startDate, $endDate, $conceito, $url_base, $command, $headers) {
            foreach ($matriculas as $matricula) {
                $body = BodyRequisition::getBody($startDate, $endDate, $conceito, $matricula->MATRICULA);

                yield new Request(
                    'POST',
                    $url_base . $command,
                    $headers,
                    json_encode($body, JSON_UNESCAPED_UNICODE)
                );
            }
        };

        $pool = new Pool($client, $requests($matriculas), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => function ($response, $index) use (&$linhasParaUpsert, $matriculas, $command) {
                $data = json_decode($response->getBody()->getContents(), true);
                $linhas = $this->extrairLinhas($data);

                foreach ($linhas as $linha) {
                    $linhasParaUpsert[] = $linha;
                }

                $this->registrarLog(
                    $command . ' - Matricula ' . $matriculas[$index]->MATRICULA,
                    (string) $response->getStatusCode(),
                    count($linhas)
                );
            },
            'rejected' => function ($reason, $index) use (&$falhas, $matriculas, $command) {
                $falhas++;
                $this->error('Falha na requisição: ' . $reason->getMessage());

                $this->registrarLog(
                    $command . ' - Matricula ' . $matriculas[$index]->MATRICULA,
                    'ERRO: ' . $reason->getMessage(),
                    0
                );
            },
        ]);

        $pool->promise()->wait();

        foreach (array_chunk($linhasParaUpsert, self::UPSERT_CHUNK_SIZE) as $chunk) {
            MarcacoesPontos::upsert(
                $chunk,
                ['DATA', 'MATRICULA', 'NOME', 'CPF', 'MARCACOES'],
                ['PAGINA']
            );
        }

        $this->info("\nProcesso concluído. " . count($linhasParaUpsert) . " registros processados, $falhas falhas.");
    }

    /**
     * Grava o resultado de uma requisição no log, sem deixar uma falha de log abortar a rotina.
     */
    private function registrarLog(string $comandoExecutado, string $statusComando, int $totalRegistros): void
    {
        try {
            Logs::create([
                'DATA_EXECUCAO' => Carbon::now()->format('d-m-Y H:i:s.v'),
                'COMANDO_EXECUTADO' => substr($comandoExecutado, 0, 255),
                'STATUS_COMANDO' => substr($statusComando, 0, 50),
                'TOTAL_REGISTROS' => $totalRegistros,
            ]);
        } catch (\Throwable $e) {
            $this->error('Falha ao gravar log: ' . $e->getMessage());
        }
    }

    /**
     * Converte a resposta da API em linhas prontas para o upsert em lote.
     */
    private function extrairLinhas(array $data): array
    {
        $itens = $data['ListaDeFiltro'] ?? [];
        $pagina = $data['Pagina'] ?? null;
        $linhas = [];

        foreach ($itens as $item) {
            $marcacao = str_replace(['–', '—'], '-', $item['Marcacoes']);
            $marcacao = trim($marcacao);

            $marcacoesArray = strpos($marcacao, '-') !== false
                ? array_map('trim', explode('-', $marcacao))
                : [$marcacao];

            $data_formatada = Carbon::createFromFormat('d/m/Y', $item['Data'])->format('Y-m-d');

            foreach ($marcacoesArray as $marcacaoUnica) {
                if ($marcacaoUnica === '') {
                    continue;
                }

                $linhas[] = [
                    'DATA' => $data_formatada,
                    'NOME' => $item['Nome'],
                    'MATRICULA' => $item['Matricula'],
                    'CPF' => $item['Cpf'],
                    'MARCACOES' => $marcacaoUnica,
                    'PAGINA' => $pagina,
                ];
            }
        }

        return $linhas;
    }


    public function getTodasMatriculas()
    {


        $ativos = DB::connection('promofarma')
            ->table('dbo.LG_IMPORTA_FUNCIONARIOS as A')
            ->select('A.MATRICULA');

        $demitidos = DB::connection('promofarma')
            ->table('dbo.LG_IMPORTA_FUNCIONARIOS_DEMITIDOS as A')
            ->select('A.MATRICULA')
            ->whereRaw('A.DATA_RESCISAO >= DATEADD(DAY, -46, CAST(GETDATE() AS DATE))')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('dbo.LG_IMPORTA_FUNCIONARIOS as B')
                    ->whereColumn('B.MATRICULA', 'A.MATRICULA');
            });

        $matriculas = $ativos
            ->unionAll($demitidos)
            ->orderBy('MATRICULA')

            ->get();

        return $matriculas;
    }
}
