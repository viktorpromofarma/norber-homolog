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

class RetornaSalariosDemitidos extends Command
{
    protected $signature = 'lg:consultar-demitidos-salario
                            {--Pagina=}
                            {--Empresa=}
                            {--Concorrencia=5}
                            {--TamanhoLote=50}';

    protected $description = 'Consulta colaboradores desligados na API LG via SOAP (em lote, com requisições concorrentes)';

    protected $pagina;
    protected $empresa;
    protected $concorrencia;
    protected $tamanhoLote;

    /** @var Client */
    protected $client;

    /** @var string XML dos headers SOAP (montado uma única vez) */
    protected $headers;

    public function handle()
    {
        $this->pagina = $this->option('Pagina');
        $this->empresa = $this->option('Empresa');
        $this->concorrencia = (int) $this->option('Concorrencia');
        $this->tamanhoLote = (int) $this->option('TamanhoLote');

        $this->headers = (new LGheaders())->getHeaders();
        $this->client = new Client([
            'verify' => false,
            'timeout' => 90,
        ]);

        $this->info('Iniciando processamento. Hora de início: ' . date('H:i:s'));



        $matriculas = DB::connection('promofarma')
            ->table('dbo.lg_importa_funcionarios_demitidos')
            ->orderBy('MATRICULA')
            ->select(
                'MATRICULA',
                'DATA_ADMISSAO',
                DB::RAW('MAX(DATA_RESCISAO) AS DATA_RESCISAO'),
                DB::RAW('MONTH(MAX(DATA_RESCISAO)) AS MES'),
                DB::RAW('YEAR(MAX(DATA_RESCISAO)) AS ANO'),
            )
            ->groupBy('MATRICULA', 'DATA_ADMISSAO')
            ->where('DATA_RESCISAO', '>=', '2026-01-01')
            ->where('DATA_RESCISAO', '<', date('Y-m-01'))
            ->where('EMPRESA', $this->empresa)
            ->whereNotIn('CARGO', [
                16,
                236,
                259
            ])
            ->orderBy('DATA_RESCISAO', 'asc')
            ->get();

        $totalMatriculas = $matriculas->count();
        $this->info("Total de matrículas encontradas: {$totalMatriculas}");

        $matriculasArray = $matriculas->pluck('MATRICULA')->all();

        $existentes = DB::connection('sqlsrv')
            ->table('RH.LG_COLABORADORES_FINANCEIROS')
            ->whereIn('MATRICULA', $matriculasArray)
            ->where('TIPO_PAGINA', $this->pagina)
            ->select('MATRICULA', 'MES', 'ANO')
            ->distinct()
            ->get()
            ->map(fn($r) => "{$r->MATRICULA}_{$r->MES}_{$r->ANO}")
            ->flip();

        $pendentes = $matriculas->reject(function ($item) use ($existentes) {
            return $existentes->has("{$item->MATRICULA}_{$item->MES}_{$item->ANO}");
        })->values();



        $totalPuladas = $totalMatriculas - $pendentes->count();
        $this->info("Pendentes: {$pendentes->count()} de {$totalMatriculas} (já processadas: {$totalPuladas})");

        $totalProcessadas = 0;
        $totalErros = 0;

        foreach ($pendentes->chunk($this->tamanhoLote) as $lote) {
            $this->info("\nProcessando lote de {$lote->count()} matrículas...");
            $this->processarLote($lote, $totalProcessadas, $totalErros);
        }

        $this->info("\nProcesso concluído. " . date('H:i:s'));
        $this->info("Total processadas com sucesso: {$totalProcessadas} | Total com erro: {$totalErros} | Total puladas (já existiam): {$totalPuladas}");
    }


    protected function processarLote($matriculas, &$totalProcessadas, &$totalErros)
    {
        $matriculasIndexadas = $matriculas->values();

        $requestsGenerator = function ($matriculasIndexadas) {
            foreach ($matriculasIndexadas as $item) {
                $soapBody = $this->montarSoapBody($item->MATRICULA, $item->MES, $item->ANO);

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
                $item = $matriculasIndexadas[$index];
                try {
                    $body = $response->getBody()->getContents();
                    $registrosInseridos = $this->processarResposta($body, $item->MES, $item->ANO);

                    if ($registrosInseridos > 0) {
                        $this->info(" [Matrícula {$item->MATRICULA} - {$item->MES}/{$item->ANO}: {$registrosInseridos} registros]");
                    }
                    $totalProcessadas++;
                } catch (\Throwable $e) {
                    $this->error("Erro ao processar resposta da matrícula {$item->MATRICULA}: " . $e->getMessage());
                }
            },

            'rejected' => function ($reason, $index) use ($matriculasIndexadas, &$totalErros) {
                $item = $matriculasIndexadas[$index];
                $mensagem = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                $this->error("Erro na requisição da matrícula {$item->MATRICULA}: {$mensagem}");
                $totalErros++;
            },
        ]);

        $pool->promise()->wait();
    }

    protected function montarSoapBody(string $matricula, $mes, $ano): string
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
                                <v11:Ano>{$ano}</v11:Ano>
                                <v11:Mes>{$mes}</v11:Mes>
                            </v11:Referencia>
                        </v1:filtro>
                    </v1:ConsultarReciboDePagamentoDetalhado>
                </soapenv:Body>
            </soapenv:Envelope>
        XML;
    }


    protected function processarResposta(string $body, $mes, $ano): int
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

            $chaveUnica = "{$matriculaNode}_{$descricao}_{$mes}_{$ano}";
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
                    'MES' => $mes,
                    'ANO' => $ano,
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
