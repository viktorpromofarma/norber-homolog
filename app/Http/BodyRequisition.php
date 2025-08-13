<?php

namespace App\Http;

use Illuminate\Support\Env;

class BodyRequisition
{
    /**
     * Retorna os headers padrão para requisições HTTP.
     *
     * @return array
     */
    public static function getBody()
    {
        return [
            "DataInicio"  => "2025-01-01",
            "DataFim"     => "2025-01-31",
            "ListaDeFiltros" => [
                [
                    "Conceito"      => 1,
                    "CodigoExterno" => "2"
                ]
            ],
            "Pagina" => 0
        ];
    }
}
