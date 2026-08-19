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

class RetornaFolhasAuxiliaresDemitidos extends Command
{
    protected $signature = 'lg:consultar-outras-folhas-demitidos
                            {--Empresa=}
                            {--Mes=}
                            {--Ano=}
                            {--Concorrencia=5}
                            {--TamanhoLote=50}';

    protected $description = 'Consulta colaboradores demitidos na API LG via SOAP para páginas de folhas auxiliares (em lote, com requisições concorrentes)';

    protected $empresa;
    protected $mes;
    protected $ano;
    protected $concorrencia;
    protected $tamanhoLote;

    /** @var Client */
    protected $client;

    /** @var string XML dos headers SOAP (montado uma única vez) */
    protected $headers;

    protected const PAGINAS = [
        '4' => '13 SALARIO',
        '7' => 'RESCISAO COMP NO MES',
        '8' => 'RESCISAO COMP FORA DO MES',
        '12' => 'RESCISAO ESTAGIARIO',
    ];

    public function handle()
    {
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

        $this->info('Iniciando processamento. Hora de início: ' . date('H:i:s'));


        $matriculas = DB::connection('promofarma')
            ->table('dbo.LG_IMPORTA_FUNCIONARIOS_DEMITIDOS')
            ->where('EMPRESA', $this->empresa)
            ->whereMonth('DATA_RESCISAO', $this->mes)
            ->whereYear('DATA_RESCISAO', $this->ano)
            ->orderBy('MATRICULA')
            ->select('MATRICULA', 'DATA_ADMISSAO')
            ->get();

        $totalProcessadas = 0;
        $totalErros = 0;
        $totalPuladas = 0;

        foreach (self::PAGINAS as $pagina => $descricaoPagina) {
            $this->info("\n=== Página {$pagina} - {$descricaoPagina} ===");


            $matriculasFinanceiro = DB::connection('sqlsrv')
                ->table('RH.LG_COLABORADORES_FINANCEIROS')
                ->where('mes', $this->mes)
                ->where('ano', $this->ano)
                ->where('TIPO_PAGINA', $pagina)
                ->distinct()
                ->pluck('MATRICULA')
                ->all();

            $jaProcessadasSet = array_flip($matriculasFinanceiro);

            $pendentes = $matriculas->reject(function ($item) use ($jaProcessadasSet) {
                return isset($jaProcessadasSet[$item->MATRICULA]);
            })->values();

            $totalPuladas += ($matriculas->count() - $pendentes->count());

            if ($pendentes->isEmpty()) {
                $this->info('Nenhuma matrícula pendente para essa página. Pulando.');
                continue;
            }

            $this->info("Pendentes: {$pendentes->count()} de {$matriculas->count()} (já processadas: " . ($matriculas->count() - $pendentes->count()) . ")");

            // Processa as pendentes dessa página em lotes, via Guzzle Pool
            foreach ($pendentes->chunk($this->tamanhoLote) as $lote) {
                $this->info("Processando lote de {$lote->count()} matrículas...");
                $this->processarLote($lote, $pagina, $totalProcessadas, $totalErros);
            }
        }

        $this->info("\nProcesso concluído. " . date('H:i:s'));
        $this->info("Total processadas com sucesso: {$totalProcessadas} | Total com erro: {$totalErros} | Total puladas (já existiam): {$totalPuladas}");
    }


    protected function processarLote($matriculas, string $pagina, &$totalProcessadas, &$totalErros)
    {
        $matriculasIndexadas = $matriculas->values();

        $requestsGenerator = function ($matriculasIndexadas) use ($pagina) {
            foreach ($matriculasIndexadas as $item) {
                $soapBody = $this->montarSoapBody($item->MATRICULA, $pagina);

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

            'fulfilled' => function ($response, $index) use ($matriculasIndexadas, $pagina, &$totalProcessadas) {
                $matricula = $matriculasIndexadas[$index]->MATRICULA;
                try {
                    $body = $response->getBody()->getContents();
                    $registrosInseridos = $this->processarResposta($body, $pagina);

                    if ($registrosInseridos > 0) {
                        $this->info(" [Matrícula {$matricula} - {$this->mes}/{$this->ano} - Pág {$pagina}: {$registrosInseridos} registros]");
                    }
                    $totalProcessadas++;
                } catch (\Throwable $e) {
                    $this->error("Erro ao processar resposta da matrícula {$matricula} (página {$pagina}): " . $e->getMessage());
                }
            },

            'rejected' => function ($reason, $index) use ($matriculasIndexadas, $pagina, &$totalErros) {
                $matricula = $matriculasIndexadas[$index]->MATRICULA;
                $mensagem = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                $this->error("Erro na requisição da matrícula {$matricula} (página {$pagina}): {$mensagem}");
                $totalErros++;
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * Monta o envelope SOAP para uma matrícula e página específicas.
     */
    protected function montarSoapBody(string $matricula, string $pagina): string
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
                            <v11:FolhaDePagamentoCodigo>{$pagina}</v11:FolhaDePagamentoCodigo>
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
    protected function processarResposta(string $body, string $pagina): int
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
                    'TIPO_PAGINA' => $pagina,
                ],
                [
                    'NOME' => $nomeNode,
                    'VALOR' => $resultado['valor'],
                    'CODIGO_EVENTO' => $resultado['codigo_evento'],
                    'DATA_REGISTRO' => now()->format('d-m-Y'),
                    'EMPRESA' => $this->empresa,
                    'TIPO_PAGINA' => $pagina,
                ]
            );

            if ($registro->wasRecentlyCreated) {
                $registrosInseridos++;
            }
        }

        return $registrosInseridos;
    }
}
