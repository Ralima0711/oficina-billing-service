<?php

namespace App\Mensageria;

interface PublicadorEventos
{
    /**
     * @param  array{header: array<string, mixed>, body: array<string, mixed>}  $envelope
     */
    public function publicar(string $routingKey, array $envelope, ?string $traceparent, ?string $tracestate = null): void;
}
