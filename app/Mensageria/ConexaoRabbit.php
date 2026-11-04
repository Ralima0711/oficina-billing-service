<?php

namespace App\Mensageria;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class ConexaoRabbit
{
    public static function abrir(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            (string) config('mensageria.host'),
            (int) config('mensageria.port'),
            (string) config('mensageria.user'),
            (string) config('mensageria.password'),
            (string) config('mensageria.vhost'),
        );
    }
}
