<?php

namespace Tests\Support;

use App\Mensageria\PublicadorEventos;
use RuntimeException;

class PublicadorFake implements PublicadorEventos
{
    public array $eventos = [];

    public int $falhas = 0;

    public function publicar(string $routingKey, array $envelope, ?string $traceparent, ?string $tracestate = null): void
    {
        if ($this->falhas > 0) {
            $this->falhas--;

            throw new RuntimeException('broker indisponível');
        }

        $this->eventos[] = [
            'routingKey' => $routingKey,
            'envelope' => $envelope,
            'traceparent' => $traceparent,
            'tracestate' => $tracestate,
        ];
    }
}
