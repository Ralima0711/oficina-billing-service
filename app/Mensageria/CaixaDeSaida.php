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

    /**
     * Ha saida gravada e ainda nao publicada para esta entrada.
     *
     * Serve ao caminho em que a transacao comitou e a publicacao nao
     * aconteceu: no consumo de fila o broker reentrega e o publicarPendentes
     * roda de novo, mas uma decisao que nasce no HTTP nao tem quem reentregue.
     */
    public function temSaidaPendente(string $messageId): bool
    {
        return MensagemSaida::query()
            ->where('mensagem_entrada_id', $messageId)
            ->whereNull('publicado_em')
            ->exists();
    }

    public function registrar(string $messageId, string $tipo): void
    {
        MensagemProcessada::query()->create([
            'message_id' => $messageId,
            'tipo' => $tipo,
        ]);
    }

    /**
     * Grava o traceparent do span que originou a intenção. Na publicação ele
     * vira o pai do span PRODUCER. $traceparentPai existe para a decisão do
     * cliente: o POST tem outro trace, e o pai certo é o que ficou no orçamento.
     *
     * @param  array<string, mixed>  $mensagem
     * @param  array<string, mixed>  $body
     */
    public function gravar(array $mensagem, string $routingKey, array $body, ?string $traceparentPai = null): void
    {
        $headerEntrada = $mensagem['header'];
        $traceparent = $this->traceparentDaIntencao($headerEntrada, $traceparentPai);

        $header = [
            'messageId' => (string) Str::uuid(),
            'sagaId' => is_string($headerEntrada['sagaId'] ?? null) ? $headerEntrada['sagaId'] : null,
            'causationId' => $headerEntrada['messageId'],
            'type' => $routingKey,
            'occurredAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'version' => 1,
        ];

        if ($traceparent !== null) {
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
            $pai = $envelope['header']['traceparent'] ?? null;
            $contextoPai = is_string($pai) && $pai !== ''
                ? TraceContextCarrier::extract([TraceContextCarrier::TRACEPARENT => $pai])
                : null;
            $osId = $envelope['body']['osId'] ?? null;

            MessageTracing::publicar(
                $saida->routing_key,
                function (array $headers) use ($saida, $envelope): void {
                    $traceparent = $headers[TraceContextCarrier::TRACEPARENT] ?? null;
                    $tracestate = $headers[TraceContextCarrier::TRACESTATE] ?? null;

                    if (! is_string($traceparent) || $traceparent === '') {
                        $gravado = $envelope['header']['traceparent'] ?? null;
                        $traceparent = is_string($gravado) && $gravado !== '' ? $gravado : null;
                    } else {
                        $envelope['header']['traceparent'] = $traceparent;
                    }

                    $this->publicador->publicar(
                        $saida->routing_key,
                        $envelope,
                        $traceparent,
                        is_string($tracestate) && $tracestate !== '' ? $tracestate : null,
                    );
                },
                is_string($osId) ? ['os.id' => $osId] : [],
                $contextoPai,
            );

            $saida->forceFill(['publicado_em' => now()])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $headerEntrada
     */
    private function traceparentDaIntencao(array $headerEntrada, ?string $explicito): ?string
    {
        if (is_string($explicito) && $explicito !== '') {
            return $explicito;
        }

        $injetado = TraceContextCarrier::inject();
        $atual = $injetado[TraceContextCarrier::TRACEPARENT] ?? null;
        if (is_string($atual) && $atual !== '') {
            return $atual;
        }

        $entrada = $headerEntrada['traceparent'] ?? null;

        return is_string($entrada) && $entrada !== '' ? $entrada : null;
    }
}
