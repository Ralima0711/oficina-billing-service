<?php

namespace App\Mensageria;

use App\Observabilidade\OpenTelemetryProvider;
use App\Observabilidade\Tracing;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * publicar abre um span PRODUCER "<destino> publish" e o traceparent injetado
 * é o desse span. consumir abre um span CONSUMER "<origem> process", filho do
 * traceparent recebido. Copiar a string que chegou não cria span nenhum.
 */
final class MessageTracing
{
    /**
     * @param  callable(array<string, mixed>): mixed  $publicar
     * @param  array<string, scalar|null>  $atributos
     */
    public static function publicar(string $destino, callable $publicar, array $atributos = [], ?ContextInterface $pai = null): mixed
    {
        $span = Tracing::iniciar("{$destino} publish", $atributos + [
            'messaging.destination.name' => $destino,
            'messaging.operation' => 'publish',
        ], SpanKind::KIND_PRODUCER, $pai);

        try {
            $resultado = $publicar(TraceContextCarrier::inject());
            Tracing::sucesso($span);

            return $resultado;
        } catch (Throwable $e) {
            Tracing::erro($span, $e);

            throw $e;
        } finally {
            Tracing::finalizar($span);
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  callable(): mixed  $processar
     * @param  array<string, scalar|null>  $atributos
     */
    public static function consumir(string $origem, array $headers, callable $processar, array $atributos = []): mixed
    {
        $span = Tracing::iniciar("{$origem} process", $atributos + [
            'messaging.destination.name' => $origem,
            'messaging.operation' => 'process',
        ], SpanKind::KIND_CONSUMER, TraceContextCarrier::extract($headers));

        try {
            $resultado = $processar();
            Tracing::sucesso($span);
            Log::info('mensagem_processada', ['messaging.destination.name' => $origem]);

            return $resultado;
        } catch (Throwable $e) {
            Tracing::erro($span, $e);
            Log::error('falha_ao_processar_mensagem', [
                'messaging.destination.name' => $origem,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            Tracing::finalizar($span);
            OpenTelemetryProvider::flush();
        }
    }
}
