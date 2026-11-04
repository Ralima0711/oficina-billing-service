<?php

return [

    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'exchange_eventos' => env('EXCHANGE_EVENTOS', 'oficina.eventos'),

    'filas' => [
        'gerar_orcamento' => env('FILA_GERAR_ORCAMENTO', 'billing.gerar-orcamento'),
    ],

];
