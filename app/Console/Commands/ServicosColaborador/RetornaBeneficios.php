<?php

namespace App\Console\Commands\ServicosColaborador;

use DOMXPath;
use DOMDocument;
use Carbon\Carbon;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use App\Http\LGheaders;
use GuzzleHttp\Psr7\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\FinanceiroColaboradores;
use App\Models\ParametrosDatasPagamentos;

class RetornaBeneficios extends Command
{
    protected $signature = 'lg:consultar-beneficios
                            {--Empresa=}
                            {--Mes=}
                            {--Ano=}
                            {--Concorrencia=5}
                            {--TamanhoLote=50}';

    protected $description = 'Consulta benefícios dos colaboradores na API LG via SOAP (em lote, com requisições concorrentes)';

    protected $pagina;
    protected $empresa;
    protected $mes;
    protected $ano;
    protected $concorrencia;
    protected $tamanhoLote;

    /** @var Client */
    protected $client;

    /** @var string XML dos headers SOAP (montado uma única vez) */
    protected $headers;

    public function handle()
    {
        $this->pagina = 29; // CÓDIGOS DE BENEFÍCIOS
        $this->empresa = $this->option('Empresa');
        $this->mes = $this->option('Mes');
        $this->ano = $this->option('Ano');
        $this->concorrencia = (int) $this->option('Concorrencia');
        $this->tamanhoLote = (int) $this->option('TamanhoLote');

        if (empty($this->mes) || empty($this->ano)) {
            $this->error('É necessário informar Mês e Ano de início');
            return 1;
        }

        $validacao = ParametrosDatasPagamentos::getValidacaoExecucao($this->mes, $this->ano);

        if ($validacao == false) {
            $this->error('Folha de pagamento ou quinzenal ainda não foi criada para execução da api');
            return 1;
        }

        $this->headers = (new LGheaders())->getHeaders();
        $this->client = new Client([
            'verify' => false,
            'timeout' => 90,
        ]);

        $this->info('Iniciando processamento de matrículas. Hora de início: ' . date('H:i:s'));

        $totalProcessadas = 0;
        $totalErros = 0;
        $totalPuladas = 0;

        // chunk() evita carregar 1500+ registros na memória de uma vez
        DB::connection('promofarma')
            ->table('dbo.LG_IMPORTA_FUNCIONARIOS')
            ->where('EMPRESA', $this->empresa)
            ->orderBy('MATRICULA')
            ->select('MATRICULA', 'DATA_ADMISSAO')
            ->chunk($this->tamanhoLote, function ($matriculas) use (&$totalProcessadas, &$totalErros, &$totalPuladas) {

                // Consulta em lote (1 query) quais matrículas já existem, ao invés de
                // uma query de "exists" por matrícula como no código original.
                $matriculasArray = $matriculas->pluck('MATRICULA')->all();

                $jaProcessadas = DB::connection('sqlsrv')
                    ->table('RH.LG_COLABORADORES_FINANCEIROS')
                    ->whereIn('MATRICULA', $matriculasArray)
                    ->where('mes', $this->mes)
                    ->where('ano', $this->ano)
                    ->where('TIPO_PAGINA', $this->pagina)
                    ->pluck('MATRICULA')
                    ->all();

                $jaProcessadasSet = array_flip($jaProcessadas);

                $pendentes = $matriculas->reject(function ($item) use ($jaProcessadasSet) {
                    return isset($jaProcessadasSet[$item->MATRICULA]);
                })->values();

                $totalPuladas += ($matriculas->count() - $pendentes->count());

                if ($pendentes->isEmpty()) {
                    $this->info("\nLote sem matrículas pendentes (todas já processadas). Pulando.");
                    return;
                }

                $this->info("\nProcessando lote: {$pendentes->count()} pendentes de {$matriculas->count()} (já processadas: " . ($matriculas->count() - $pendentes->count()) . ")");
                $this->processarLote($pendentes, $totalProcessadas, $totalErros);
            });

        $this->info("\nProcesso concluído. " . date('H:i:s'));
        $this->info("Total processadas com sucesso: {$totalProcessadas} | Total com erro: {$totalErros} | Total puladas (já existiam): {$totalPuladas}");
    }

    private function gerarPeriodos(): array
    {
        $periodos = [];
        $dataInicio = Carbon::createFromDate($this->ano, $this->mes, 1);
        $dataFim = Carbon::createFromDate($this->ano, $this->mes, 31);
        $current = $dataInicio->copy();

        while ($current->lessThanOrEqualTo($dataFim)) {
            $periodos[] = [
                'mes' => $current->month,
                'ano' => $current->year,
            ];
            $current->addMonth();
        }

        $this->info('Períodos a processar: ' . count($periodos));
        foreach ($periodos as $periodo) {
            $this->info(" - {$periodo['mes']}/{$periodo['ano']}");
        }

        return $periodos;
    }

    /**
     * Dispara as requisições do lote em paralelo via Guzzle Pool,
     * respeitando o limite de concorrência configurado.
     */
    protected function processarLote($matriculas, &$totalProcessadas, &$totalErros)
    {
        $matriculasIndexadas = $matriculas->values();

        $requestsGenerator = function ($matriculasIndexadas) {
            foreach ($matriculasIndexadas as $item) {
                $soapBody = $this->montarSoapBody($item->MATRICULA);

                yield new Request(
                    'POST',
                    'https://prd-api1.lg.com.br/v1/servicoderecibodepagamento',
                    [
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"lg.com.br/api/v1/ServicoDeReciboDePagamento/ConsultarReciboDePagamentoDetalhado"',
                    ],
                    $soapBody
                );
            }
        };

        $pool = new Pool($this->client, $requestsGenerator($matriculasIndexadas), [
            'concurrency' => $this->concorrencia,

            'fulfilled' => function ($response, $index) use ($matriculasIndexadas, &$totalProcessadas) {
                $matricula = $matriculasIndexadas[$index]->MATRICULA;
                try {
                    $body = $response->getBody()->getContents();
                    $registrosInseridos = $this->processarResposta($body);

                    if ($registrosInseridos > 0) {
                        $this->info(" [Matrícula {$matricula} - {$this->mes}/{$this->ano}: {$registrosInseridos} registros]");
                    }
                    $totalProcessadas++;
                } catch (\Throwable $e) {
                    $this->error("Erro ao processar resposta da matrícula {$matricula}: " . $e->getMessage());
                }
            },

            'rejected' => function ($reason, $index) use ($matriculasIndexadas, &$totalErros) {
                $matricula = $matriculasIndexadas[$index]->MATRICULA;
                $mensagem = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                $this->error("Erro na requisição da matrícula {$matricula}: {$mensagem}");
                $totalErros++;
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * Monta o envelope SOAP para uma matrícula específica.
     */
    protected function montarSoapBody(string $matricula): string
    {
        return <<<XML
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:dto="lg.com.br/svc/dto" xmlns:v1="lg.com.br/api/v1" xmlns:v11="lg.com.br/api/dto/v1">
            {$this->headers}
                <soapenv:Body>
                    <v1:ConsultarReciboDePagamentoDetalhado>
                        <v1:filtro>
                            <v11:Colaborador>
                                <v11:Empresa>
                                    <v11:Codigo>{$this->empresa}</v11:Codigo>
                                </v11:Empresa>
                                <v11:Matricula>{$matricula}</v11:Matricula>
                            </v11:Colaborador>
                            <v11:FolhaDePagamentoCodigo>{$this->pagina}</v11:FolhaDePagamentoCodigo>
                            <v11:Referencia>
                                <v11:Ano>{$this->ano}</v11:Ano>
                                <v11:Mes>{$this->mes}</v11:Mes>
                            </v11:Referencia>
                        </v1:filtro>
                    </v1:ConsultarReciboDePagamentoDetalhado>
                </soapenv:Body>
            </soapenv:Envelope>
        XML;
    }

    /**
     * Faz o parse do XML de resposta e grava/atualiza os registros no banco.
     * Retorna a quantidade de registros efetivamente inseridos (novos).
     */
    protected function processarResposta(string $body): int
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadXML($body);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('a', 'lg.com.br/api/dto/v1');

        $matriculaNode = $xpath->query('//a:Matricula')->item(0)->nodeValue ?? '';
        $nomeNode = $xpath->query('//a:Nome')->item(0)->nodeValue ?? '';

        if (empty($matriculaNode) || empty($nomeNode)) {
            return 0;
        }

        $eventos = $xpath->query('//a:EventoCalculado');
        $resultados = [];

        foreach ($eventos as $evento) {
            $descricao = $xpath->query('a:Descricao', $evento)->item(0)->nodeValue ?? '';
            $valor = $xpath->query('a:Valor', $evento)->item(0)->nodeValue ?? '';
            $codigo = $xpath->query('a:Codigo', $evento)->item(0)->nodeValue ?? '';

            if (empty($descricao) || empty($valor)) {
                continue;
            }

            $chaveUnica = "{$matriculaNode}_{$descricao}_{$this->mes}_{$this->ano}";
            $resultados[$chaveUnica] = [
                'descricao' => $descricao,
                'valor' => $valor,
                'codigo_evento' => $codigo,
            ];
        }

        $registrosInseridos = 0;

        foreach ($resultados as $resultado) {
            $registro = FinanceiroColaboradores::updateOrCreate(
                [
                    'MATRICULA' => $matriculaNode,
                    'DESCRICAO' => $resultado['descricao'],
                    'MES' => $this->mes,
                    'ANO' => $this->ano,
                ],
                [
                    'NOME' => $nomeNode,
                    'VALOR' => $resultado['valor'],
                    'CODIGO_EVENTO' => $resultado['codigo_evento'],
                    'DATA_REGISTRO' => now()->format('d-m-Y'),
                    'EMPRESA' => $this->empresa,
                    'TIPO_PAGINA' => $this->pagina,
                ]
            );

            if ($registro->wasRecentlyCreated) {
                $registrosInseridos++;
            }
        }

        return $registrosInseridos;
    }
}
