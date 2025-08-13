<?php

namespace App\Console\Commands\BancoDeHoras;


use App\Http\Headers;
use GuzzleHttp\Client;
use App\Console\UrlBase;
use App\Http\BodyRequisition;
use Illuminate\Console\Command;


class ListarSaldo extends Command
{

    protected $signature = "norber:listar-saldo";
    protected $description = "Listar saldo de horas";


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

        $command = 'marcacao/RetornaMarcacoes';

        $response = $client->post($url_base . $command, [
            'headers' => $headers,
            'body'    => json_encode($body, JSON_UNESCAPED_UNICODE)
        ]);

        $response = $response->getBody()->getContents();
        $data = json_decode($response, true);

        dd($data);
    }
}
