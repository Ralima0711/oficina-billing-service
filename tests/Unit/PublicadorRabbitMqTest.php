<?php

namespace Tests\Unit;

use App\Mensageria\PublicadorRabbitMq;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

class PublicadorRabbitMqTest extends TestCase
{
    private const TRACEPARENT = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    public function test_publica_mensagem_persistente_com_trace_nos_headers_amqp(): void
    {
        $publicada = $this->publicar(self::TRACEPARENT, 'nr=abc', 'oficina.eventos', 'orcamento.gerado');

        $this->assertSame('oficina.eventos', $publicada['exchange']);
        $this->assertSame('orcamento.gerado', $publicada['routingKey']);

        $mensagem = $publicada['mensagem'];
        $this->assertSame($this->envelope(), json_decode($mensagem->getBody(), true));
        $this->assertSame('application/json', $mensagem->get('content_type'));
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $mensagem->get('delivery_mode'));
        $this->assertSame('orcamento.gerado', $mensagem->get('type'));
        $this->assertSame('55555555-5555-5555-5555-555555555555', $mensagem->get('message_id'));

        $headers = $mensagem->get('application_headers');
        $this->assertInstanceOf(AMQPTable::class, $headers);
        $this->assertSame([
            'traceparent' => self::TRACEPARENT,
            'tracestate' => 'nr=abc',
        ], $headers->getNativeData());
    }

    public function test_sem_tracestate_so_leva_o_traceparent(): void
    {
        $publicada = $this->publicar(self::TRACEPARENT, '', 'oficina.eventos', 'pagamento.confirmado');

        $this->assertSame(
            ['traceparent' => self::TRACEPARENT],
            $publicada['mensagem']->get('application_headers')->getNativeData(),
        );
    }

    public function test_sem_contexto_de_trace_nao_envia_headers_amqp(): void
    {
        $publicada = $this->publicar(null, null, 'oficina.eventos', 'orcamento.falhou');

        $this->assertFalse($publicada['mensagem']->has('application_headers'));
        $this->assertSame('orcamento.falhou', $publicada['mensagem']->get('type'));
    }

    /**
     * @return array{mensagem: AMQPMessage, exchange: string, routingKey: string}
     */
    private function publicar(?string $traceparent, ?string $tracestate, string $exchange, string $routingKey): array
    {
        $capturada = null;
        $canal = Mockery::mock(AMQPChannel::class);
        $canal->shouldReceive('basic_publish')->once()->andReturnUsing(
            function (AMQPMessage $mensagem, string $exchange, string $routingKey) use (&$capturada): void {
                $capturada = compact('mensagem', 'exchange', 'routingKey');
            },
        );

        (new PublicadorRabbitMq($canal, $exchange))->publicar($routingKey, $this->envelope(), $traceparent, $tracestate);

        return $capturada;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'header' => [
                'messageId' => '55555555-5555-5555-5555-555555555555',
                'type' => 'orcamento.gerado',
            ],
            'body' => ['osId' => '11111111-1111-1111-1111-111111111111', 'valorTotal' => 241.8],
        ];
    }
}
