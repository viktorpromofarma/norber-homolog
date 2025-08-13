<?php

namespace App\Http;

use Illuminate\Support\Env;

class BodyToken
{
    /**
     * Retorna os headers padrão para requisições HTTP.
     *
     * @return array
     */
    public static function getBody()
    {
        return [
            'senha' => env('API_PASSWORD'),
            'Usuario' => env('API_USER'),
            'SessionID' => 'string'
        ];
    }
}
