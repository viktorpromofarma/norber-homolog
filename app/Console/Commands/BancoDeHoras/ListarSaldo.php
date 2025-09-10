<?php

namespace App\Console\Commands\BancoDeHoras;

use App\Http\Headers;
use GuzzleHttp\Client;
use App\Console\UrlBase;
use App\Http\BodyRequisition;
use Illuminate\Console\Command;
use App\Models\BancoHorasPeriodo;

class ListarSaldo extends Command
{
    protected $signature = "norber:listar-saldo  
                            {--MesAnoReferencia= : Data de referência (formato: YYYY-MM)}
                            {--Conceito= : Conceito (formato: inteiro)}
                            {--CodigoExterno= : Código externo (formato: string)}";

    protected $description = "Listar saldo de horas";

    protected function urlBaseApi()
    {
        return (new UrlBase())->getUrlbase();
    }

    public function handle()
    {
        $MesAnoReferencia = $this->option('MesAnoReferencia');
        $conceito = $this->option('Conceito');
        $codigoExterno = $this->option('CodigoExterno');

        $client = new Client();
        $headers = Headers::getHeaders();
        $url_base = $this->urlBaseApi();
        $command = 'banco-de-horas/listar-saldo-v2';


        $ultimaPaginaProcessada = BancoHorasPeriodo::where('MES_ANO_REFERENCIA', $MesAnoReferencia)
            ->max('PAGINA') ?? 0;


        for ($pagina = $ultimaPaginaProcessada + 1;; $pagina++) {

            $body = BodyRequisition::getBodySaldo($MesAnoReferencia, $conceito, $codigoExterno, $pagina);

            try {
                $response = $client->post($url_base . $command, [
                    'headers' => $headers,
                    'body'    => json_encode($body, JSON_UNESCAPED_UNICODE)
                ]);

                $data = json_decode($response->getBody()->getContents(), true);


                if (empty($data['ListaDeFiltro'])) break;

                $this->processarPagina($data, $pagina);


                if ($pagina % 10 === 0) sleep(1);


                if (isset($data['TotalPaginas']) && $pagina >= $data['TotalPaginas']) break;
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $this->error("Falha na página $pagina: " . $e->getMessage());
                break;
            }
        }

        return 0;
    }

    private function processarPagina(array $data, int $pagina)
    {
        $registros = [];

        foreach ($data['ListaDeFiltro'] as $item) {
            $registros[] = [
                'MATRICULA'          => $item['Matricula'],
                'SALDO_BANCO'        => $item['SaldoBanco'],
                'MES_ANO_REFERENCIA' => $data['MesAnoReferencia'],
                'PAGINA'             => $pagina
            ];
        }

        BancoHorasPeriodo::upsert(
            $registros,
            ['MATRICULA', 'MES_ANO_REFERENCIA'], // chave única
            ['SALDO_BANCO', 'PAGINA']            // campos que serão atualizados
        );
    }
}
