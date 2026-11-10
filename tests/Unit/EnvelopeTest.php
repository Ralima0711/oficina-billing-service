<?php

namespace Tests\Unit;

use App\Mensageria\Envelope;
use Tests\TestCase;

class EnvelopeTest extends TestCase
{
    public function test_trace_do_amqp_substitui_o_do_envelope(): void
    {
        $envelope = Envelope::priorizarTraceparent(
            ['header' => ['traceparent' => '00-antigo']],
            '00-amqp',
        );

        $this->assertSame('00-amqp', $envelope['header']['traceparent']);
    }

    public function test_sem_trace_amqp_mantem_o_do_envelope(): void
    {
        $envelope = Envelope::priorizarTraceparent(
            ['header' => ['traceparent' => '00-envelope']],
            null,
        );

        $this->assertSame('00-envelope', $envelope['header']['traceparent']);
    }
}
