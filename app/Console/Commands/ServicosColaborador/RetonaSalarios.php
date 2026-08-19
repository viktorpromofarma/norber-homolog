<?php

namespace App\Console\Commands\ServicosColaborador;

use DOMXPath;
use DOMDocument;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use App\Http\LGheaders;
use GuzzleHttp\Psr7\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\FinanceiroColaboradores;
use App\Models\ParametrosDatasPagamentos;

class RetonaSalarios extends Command
{
    protected $signature = 'lg:consultar-salario
                            {--Pagina=}
                            {--Empresa=}
                            {--Mes=}
                            {--Ano=}
                            {--Concorrencia=5}
                            {--TamanhoLote=50}';

    protected $description = 'Consulta colaboradores na API LG via SOAP (em lote, com requisições concorrentes)';

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
        $this->pagina = $this->option('Pagina');
        $this->empresa = $this->option('Empresa');
        $this->mes = $this->option('Mes');
        $this->ano = $this->option('Ano');
        $this->concorrencia = (int) $this->option('Concorrencia');
        $this->tamanhoLote = (int) $this->option('TamanhoLote');

        if (empty($this->mes) || empty($this->ano)) {
            $this->error('É necessário informar Mês e Ano de início');
            return 1;
        }

        $validacao =  ParametrosDatasPagamentos::getValidacaoExecucao($this->mes, $this->ano);


        if ($validacao == false) {
            $this->error('Folha de pagamento ou quinzenal ainda não foi criada para execução da api');
            exit;
        } else {

            $this->headers = (new LGheaders())->getHeaders();
            $this->client = new Client([
                'verify' => false,
                'timeout' => 90,
            ]);

            $this->info('Iniciando processamento de matrículas. Hora de início: ' . date('H:i:s'));

            $totalProcessadas = 0;
            $totalErros = 0;


            DB::connection('promofarma')
                ->table('dbo.LG_IMPORTA_FUNCIONARIOS')
                ->where('EMPRESA', $this->empresa)
                ->orderBy('MATRICULA')
                ->select('MATRICULA', 'DATA_ADMISSAO')
                ->chunk($this->tamanhoLote, function ($matriculas) use (&$totalProcessadas, &$totalErros) {
                    $this->info("\nProcessando lote de {$matriculas->count()} matrículas...");
                    $this->processarLote($matriculas, $totalProcessadas, $totalErros);
                });

            $this->info("\nProcesso concluído. " . date('H:i:s'));
            $this->info("Total processadas com sucesso: {$totalProcessadas} | Total com erro: {$totalErros}");
        }
    }


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
                        $this->info(" [Matrícula {$matricula} - {$this->mes}/{$this->ano}: {$registrosInseridos} registros inseridos]");
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
                    'CODIGO_EVENTO' => $resultado['codigo_evento'],
                    'TIPO_PAGINA' => $this->pagina,
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
