<?php

namespace App\Console\Commands\Feriados;


use App\Http\Headers;
use GuzzleHttp\Client;
use App\Console\UrlBase;
use Illuminate\Console\Command;
use App\Http\BodyRequisition;


class ConsultarFeriadoPorContrato extends Command
{
    protected $signature = "norber:consultar-feriado-por-contrato";

    protected $description = "listar feriado por contrato";

    protected function urlBaseApi()
    {
        $urlBase = new UrlBase();
        return $urlBase->getUrlbase();
    }

    public function handle()
    {
        $client = new Client();
        $headers = Headers::getHeaders();

        $body = BodyRequisition::getBody();
        $url_base = $this->urlBaseApi();

        $command = 'feriados/consultar-feriado-por-contrato';

        $response = $client->post($url_base . $command, [
            'headers' => $headers,
            'body'    => json_encode($body, JSON_UNESCAPED_UNICODE)
        ]);

        $response = $response->getBody()->getContents();
        $data = json_decode($response, true);

        // pega só a lista de itens
        $itens = $data['ListaDeFiltro'] ?? [];

        $dadosCorrigidos = [];

        foreach ($itens as $item) {
            $marcacao = str_replace(['–', '—'], '-', $item['Marcacoes']);
            $marcacao = trim($marcacao);

            if (strpos($marcacao, '-') !== false) {
                // limita o explode a 2 partes
                [$m1, $m2] = array_map('trim', explode('-', $marcacao, 2));

                dd($m1, $m2);

                $dadosCorrigidos[] = [
                    'Data'       => $item['Data'],
                    'Nome'       => $item['Nome'],
                    'Matricula'  => $item['Matricula'],
                    'Cpf'        => $item['Cpf'],
                    'Marcacoes1' => $m1,
                    'Marcacoes2' => $m2,
                ];
            }
        }

        dd($dadosCorrigidos);
    }
}
