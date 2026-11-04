<?php

namespace App\Mensageria;

use App\Models\MensagemProcessada;
use App\Models\MensagemSaida;
use Illuminate\Support\Str;

class CaixaDeSaida
{
    public function __construct(private readonly PublicadorEventos $publicador) {}

    public function jaProcessada(string $messageId): bool
    {
        return MensagemProcessada::query()->where('message_id', $messageId)->exists();
    }

    public function temSaida(string $messageId): bool
    {
        return MensagemSaida::query()->where('mensagem_entrada_id', $messageId)->exists();
    }

    public function registrar(string $messageId, string $tipo): void
    {
        MensagemProcessada::query()->create([
            'message_id' => $messageId,
            'tipo' => $tipo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $mensagem
     * @param  array<string, mixed>  $body
     */
    public function gravar(array $mensagem, string $routingKey, array $body): void
    {
        $headerEntrada = $mensagem['header'];
        $traceparent = $headerEntrada['traceparent'] ?? null;

        $header = [
            'messageId' => (string) Str::uuid(),
            'sagaId' => is_string($headerEntrada['sagaId'] ?? null) ? $headerEntrada['sagaId'] : null,
            'causationId' => $headerEntrada['messageId'],
            'type' => $routingKey,
            'occurredAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'version' => 1,
        ];

        if (is_string($traceparent) && $traceparent !== '') {
            $header['traceparent'] = $traceparent;
        }

        MensagemSaida::query()->create([
            'mensagem_entrada_id' => $headerEntrada['messageId'],
            'routing_key' => $routingKey,
            'envelope' => [
                'header' => $header,
                'body' => $body,
            ],
        ]);
    }

    public function publicarPendentes(string $messageId): void
    {
        $pendentes = MensagemSaida::query()
            ->where('mensagem_entrada_id', $messageId)
            ->whereNull('publicado_em')
            ->orderBy('created_at')
            ->get();

        foreach ($pendentes as $saida) {
            $envelope = $saida->envelope;
            $traceparent = $envelope['header']['traceparent'] ?? null;

            $this->publicador->publicar(
                $saida->routing_key,
                $envelope,
                is_string($traceparent) && $traceparent !== '' ? $traceparent : null,
            );

            $saida->forceFill(['publicado_em' => now()])->save();
        }
    }
}
