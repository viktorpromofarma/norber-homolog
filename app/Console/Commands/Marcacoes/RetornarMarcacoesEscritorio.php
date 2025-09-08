<?php

namespace App\Console\Commands\Marcacoes;

use App\Http\Headers;
use GuzzleHttp\Client;
use App\Console\UrlBase;
use App\Http\BodyRequisition;
use App\Models\MarcacoesPontos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetornarMarcacoesEscritorio extends Command
{
    protected $signature = 'norber:retornar-marcacoes-escritorio   
                            {--start-date= : Data de início (formato: YYYY-MM-DD)}
                            {--end-date= : Data de fim (formato: YYYY-MM-DD)}';
    protected $description = "Listar saldo de horas";

    protected function urlBaseApi()
    {
        $urlBase = new UrlBase();
        return $urlBase->getUrlbase();
    }

    public function handle()
    {
        $conceito = 3;
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');

        $funcionarios = [];

        $client = new Client();
        $headers = Headers::getHeaders();

        // busca funcionários por cada data
        while ($startDate < $endDate) {
            $funcionarios[] = DB::table('LG_IMPORTA_FUNCIONARIOS AS A')
                ->join('CENTROS_CUSTO AS B', function ($join) {
                    $join->on('A.CENTRO_CUSTO', '=', 'B.OBJETO_CONTROLE')
                        ->where('B.EMPRESA_USUARIA', 999);
                })
                ->whereNotIn('A.MATRICULA', function ($query) use ($startDate) {
                    $query->select('MATRICULA')
                        ->from('NORBER_MARCACOES_PONTOS')
                        ->where('DATA', $startDate);
                })
                ->select('MATRICULA', DB::raw("'$startDate' as DATA"))
                //   ->whereIn('MATRICULA', [3082, 1807])
                ->orderBy('MATRICULA')
                ->get()
                ->toArray();

            $startDate = date('Y-m-d', strtotime('+1 day', strtotime($startDate)));
        }

        $funcionarios = array_filter($funcionarios);
        $funcionariosAgrupados = array_merge(...$funcionarios);



        foreach ($funcionariosAgrupados as $funcionario) {
            $codigoExterno = $funcionario->MATRICULA;
            $dataMarcacao = $funcionario->DATA;



            $body = BodyRequisition::getBody($dataMarcacao, $dataMarcacao, $conceito, $codigoExterno);
            $url_base = $this->urlBaseApi();
            $command = 'marcacao/RetornaMarcacoes';


            try {
                $response = $client->post($url_base . $command, [
                    'headers' => $headers,
                    'body'    => json_encode($body, JSON_UNESCAPED_UNICODE)
                ]);

                $responseContent = $response->getBody()->getContents();
                $data = json_decode($responseContent, true);

                $itens = $data['ListaDeFiltro'] ?? [];

                foreach ($itens as $item) {
                    $marcacao = str_replace(['–', '—'], '-', $item['Marcacoes']);
                    $marcacao = trim($marcacao);

                    if ($marcacao === 'SEM MARCAÇÕES') {
                        MarcacoesPontos::create([
                            'DATA'      => \Carbon\Carbon::createFromFormat('d/m/Y', $item['Data'])->format('Y-m-d'),
                            'MATRICULA' => $item['Matricula'],
                            'NOME'      => $item['Nome'],
                            'CPF'       => $item['Cpf'],
                            'MARCACOES' => 'SEM MARCAÇÕES',
                        ]);
                    } elseif (strpos($marcacao, '-') !== false) {
                        $marcacoesArray = array_map('trim', explode('-', $marcacao));

                        foreach ($marcacoesArray as $m) {
                            if (!empty($m)) {
                                // Converte a marcação para formato yyyy-MM-dd HH:mm:ss
                                $mConvertida = \Carbon\Carbon::createFromFormat('d/m/Y H:i:s', $m)
                                    ->format('Y-m-d H:i:s');

                                MarcacoesPontos::create([
                                    'DATA'      => \Carbon\Carbon::createFromFormat('d/m/Y', $item['Data'])->format('Y-m-d'),
                                    'MATRICULA' => $item['Matricula'],
                                    'NOME'      => $item['Nome'],
                                    'CPF'       => $item['Cpf'],
                                    'MARCACOES' => $mConvertida,
                                ]);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error("Erro ao processar matrícula {$codigoExterno} na data {$dataMarcacao}: " . $e->getMessage());
            }
        }

        $this->info("Marcações inseridas");
    }
}
