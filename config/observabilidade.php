<?php

return [

    /*
    | O nome separa este serviço dos outros dois no mesmo trace. A chave do
    | New Relic não fica aqui: ela vai em OTEL_EXPORTER_OTLP_HEADERS, vinda de
    | Secret. Sem endpoint o provider não exporta e o serviço segue de pé.
    */

    'service_name' => env('OTEL_SERVICE_NAME', 'oficina-billing-service'),

    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),

    'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),

];
