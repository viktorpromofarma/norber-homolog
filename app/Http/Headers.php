<?php

namespace App\Http;

use Illuminate\Support\Env;

class Headers
{
    /**
     * Retorna os headers padrão para requisições HTTP.
     *
     * @return array
     */
    public static function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'token' => env('API_TOKEN'),
            'Cookie' => '_LOGGER_ID_AMBIENTE=640'

        ];
    }
}
