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

        dd($data);
    }
}
