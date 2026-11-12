<?php

namespace App\Mensageria;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class PublicadorRabbitMq implements PublicadorEventos
{
    public function __construct(
        private readonly AMQPChannel $canal,
        private readonly string $exchange,
    ) {}

    public function publicar(string $routingKey, array $envelope, ?string $traceparent, ?string $tracestate = null): void
    {
        $propriedades = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'type' => $routingKey,
            'message_id' => $envelope['header']['messageId'] ?? null,
        ];

        $amqp = [];

        if (is_string($traceparent) && $traceparent !== '') {
            $amqp['traceparent'] = $traceparent;
        }

        if (is_string($tracestate) && $tracestate !== '') {
            $amqp['tracestate'] = $tracestate;
        }

        if ($amqp !== []) {
            $propriedades['application_headers'] = new AMQPTable($amqp);
        }

        $this->canal->basic_publish(
            new AMQPMessage(json_encode($envelope, JSON_THROW_ON_ERROR), $propriedades),
            $this->exchange,
            $routingKey,
        );
    }
}
