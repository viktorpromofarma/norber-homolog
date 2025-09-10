<?php

namespace App\Console\Commands\Marcacoes;

use App\Http\Headers;
use GuzzleHttp\Client;
use App\Console\UrlBase;
use App\Http\BodyRequisition;
use Illuminate\Console\Command;
use App\Models\MarcacoesPontos;
use App\Models\Logs;

class RetornarMarcacoes extends Command
{
    // Modificado: Adicionar opções de data no signature
    protected $signature = 'norber:retornar-marcacoes 
                            {--start-date= : Data de início (formato: YYYY-MM-DD)}
                            {--end-date= : Data de fim (formato: YYYY-MM-DD)}
                            {--Conceito= : Conceito (formato: inteiro)}
                            {--CodigoExterno= : Código externo (formato: string)}';

    protected $description = "Listar marcações de pontos";

    protected function urlBaseApi()
    {
        $urlBase = new UrlBase();
        return $urlBase->getUrlbase();
    }

    public function handle()
    {
        // variaveis que serão atribuidas no comando
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $conceito = $this->option('Conceito');
        $codigoExterno = $this->option('CodigoExterno');


        // Validar se as datas foram fornecidas
        if (!$startDate || !$endDate) {
            $this->error('Por favor, forneça ambas as datas: --start-date e --end-date');
            return 1;
        }

        // Validar formato das datas
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
        ) {
            $this->error('Formato de data inválido. Use YYYY-MM-DD');
            return 1;
        }

        $client = new Client();
        $headers = Headers::getHeaders();
        $url_base = $this->urlBaseApi();
        $command = 'marcacao/RetornaMarcacoes';



        $ultimaPaginaProcessada = MarcacoesPontos::where('DATA', '>=', $startDate)
            ->where('DATA', '<=', $endDate)
            ->max('PAGINA') ?? 0;


        for ($pagina = $ultimaPaginaProcessada + 1;; $pagina++) {
            $body = BodyRequisition::getBody($startDate, $endDate, $conceito, $codigoExterno, $pagina);

            try {
                $response = $client->post($url_base . $command, [
                    'headers' => $headers,
                    'body'    => json_encode($body, JSON_UNESCAPED_UNICODE)
                ]);

                $responseContent = $response->getBody()->getContents();
                $data = json_decode($responseContent, true);

                $itens = $data['ListaDeFiltro'] ?? [];
                $dadosCorrigidos = [];

                foreach ($itens as $item) {
                    $marcacao = str_replace(['–', '—'], '-', $item['Marcacoes']);
                    $marcacao = trim($marcacao);

                    if (strpos($marcacao, '-') !== false) {
                        $marcacoesArray = array_map('trim', explode('-', $marcacao));

                        $dados = [
                            'Data'       => $item['Data'],
                            'Nome'       => $item['Nome'],
                            'Matricula'  => $item['Matricula'],
                            'Cpf'        => $item['Cpf'],
                        ];

                        foreach ($marcacoesArray as $index => $marcacoes) {
                            $dados['Marcacoes' . ($index + 1)] = $marcacoes;
                        }

                        $dadosCorrigidos[] = $dados;
                    } else {
                        // SEM MARCAÇÕES também precisa ir pro banco
                        $dadosCorrigidos[] = [
                            'Data'      => $item['Data'],
                            'Nome'      => $item['Nome'],
                            'Matricula' => $item['Matricula'],
                            'Cpf'       => $item['Cpf'],
                            'Marcacoes1' => $marcacao
                        ];
                    }
                }

                // Inserir no banco de dados
                foreach ($dadosCorrigidos as $dado) {
                    $marcacoes = [];
                    $i = 1;

                    while (isset($dado['Marcacoes' . $i]) && !empty($dado['Marcacoes' . $i])) {
                        $marcacoes[] = $dado['Marcacoes' . $i];
                        $i++;
                    }

                    foreach ($marcacoes as $marcacao) {
                        MarcacoesPontos::updateOrCreate([
                            'DATA' => $dado['Data'],
                            'MATRICULA' => $dado['Matricula'],
                            'NOME' => $dado['Nome'],
                            'CPF' => $dado['Cpf'],
                            'MARCACOES' => $marcacao,
                            'PAGINA' => $data['Pagina']
                        ]);
                    }
                }

                $this->info("Página {$pagina} processada com sucesso. Total registros: " . count($dadosCorrigidos));

                Logs::create([
                    'DATA_EXECUCAO' => now(),
                    'COMANDO_EXECUTADO' =>  $command . ' - ' . json_encode($body),
                    'STATUS_COMANDO' => $response->getStatusCode(),
                    'TOTAL_REGISTROS' => count($dadosCorrigidos)
                ]);


                if ($pagina % 10 === 0) {
                    sleep(1);
                }

                // quando chegar na última página, para o loop
                if (isset($data['TotalPaginas']) && $pagina >= $data['TotalPaginas']) {
                    break;
                }
            } catch (\Exception $e) {
                $this->error("Erro na página {$pagina}: " . $e->getMessage());
                break;
            }
        }

        return 0; // só aqui no final

    }
}
