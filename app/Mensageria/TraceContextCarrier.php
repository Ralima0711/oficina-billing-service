<?php

namespace App\Mensageria;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;

/**
 * W3C Trace Context num array de headers. Serve ao envelope e ao AMQP.
 */
final class TraceContextCarrier
{
    public const TRACEPARENT = 'traceparent';

    public const TRACESTATE = 'tracestate';

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function inject(array $headers = [], ?ContextInterface $contexto = null): array
    {
        TraceContextPropagator::getInstance()->inject(
            $headers,
            ArrayAccessGetterSetter::getInstance(),
            $contexto ?? Context::getCurrent(),
        );

        return $headers;
    }

    /**
     * Parte da raiz: header ausente ou inválido abre trace novo, em vez de
     * herdar o span que o worker deixou corrente.
     *
     * @param  array<string, mixed>  $headers
     */
    public static function extract(array $headers): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract(
            self::normalizar($headers),
            ArrayAccessGetterSetter::getInstance(),
            Context::getRoot(),
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function normalizar(array $headers): array
    {
        $normalizados = [];

        foreach ($headers as $chave => $valor) {
            if (is_array($valor)) {
                $valor = $valor[0] ?? null;
            }

            if (is_string($valor)) {
                $normalizados[strtolower((string) $chave)] = $valor;
            }
        }

        return $normalizados;
    }
}
