<?php

namespace App\Mensageria;

class Envelope
{
    /**
     * O traceparent do header AMQP prevalece sobre o do envelope (contrato v1.1).
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public static function priorizarTraceparent(array $envelope, ?string $traceAmqp): array
    {
        if (is_string($traceAmqp) && $traceAmqp !== '') {
            $envelope['header']['traceparent'] = $traceAmqp;
        }

        return $envelope;
    }
}
