<?php

namespace App\Console\Commands\Marcacoes;

use App\Http\Headers;
use GuzzleHttp\Client;
use App\Console\UrlBase;
use App\Http\BodyRequisition;
use Illuminate\Console\Command;
use App\Models\MarcacoesPontos;
use Termwind\Components\Dd;

class RetornarMarcacoes extends Command
{
    // Modificado: Adicionar opções de data no signature
    protected $signature = 'norber:retornar-marcacoes 
                            {--start-date= : Data de início (formato: YYYY-MM-DD)}
                            {--end-date= : Data de fim (formato: YYYY-MM-DD)}
                            {--Conceito= : Conceito (formato: inteiro)}
                            {--CodigoExterno= : Código externo (formato: string)}';

    protected $description = "Listar saldo de horas";

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

        // Passar as datas para o BodyRequisition
        $body = BodyRequisition::getBody($startDate, $endDate, $conceito, $codigoExterno);
        $url_base = $this->urlBaseApi();

        $command = 'marcacao/RetornaMarcacoes';

        try {
            $response = $client->post($url_base . $command, [
                'headers' => $headers,
                'body'    => json_encode($body, JSON_UNESCAPED_UNICODE)
            ]);

            $responseContent = $response->getBody()->getContents();
            $data = json_decode($responseContent, true);


            // pega só a lista de itens
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

                // Insere cada marcação como registro separado
                foreach ($marcacoes as $marcacao) {
                    if (!empty($marcacao) && $marcacao !== '') {
                        MarcacoesPontos::create([
                            'DATA' => $dado['Data'],
                            'MATRICULA' => $dado['Matricula'],
                            'NOME' => $dado['Nome'],
                            'CPF' => $dado['Cpf'],
                            'MARCACOES' => $marcacao
                        ]);
                    }
                }
            }

            $this->info("Marcações inseridas com sucesso! Total de registros processados: " . count($dadosCorrigidos));
            return 0;
        } catch (\Exception $e) {
            $this->error('Erro: ' . $e->getMessage());
            return 1;
        }
    }
}
