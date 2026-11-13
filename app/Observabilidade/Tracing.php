<?php

namespace App\Observabilidade;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

class Tracing
{
    /** @var ScopeInterface[] */
    private static array $escopos = [];

    /**
     * @param  array<string, scalar|null>  $atributos
     */
    public static function iniciar(
        string $nome,
        array $atributos = [],
        int $tipo = SpanKind::KIND_INTERNAL,
        ?ContextInterface $pai = null,
    ): ?SpanInterface {
        if (! class_exists(Span::class)) {
            return null;
        }

        $builder = OpenTelemetryProvider::tracer()
            ->spanBuilder($nome)
            ->setSpanKind($tipo);

        foreach ($atributos as $chave => $valor) {
            if ($valor !== null) {
                $builder->setAttribute($chave, $valor);
            }
        }

        if ($pai !== null) {
            $builder->setParent($pai);
        } else {
            $atual = Span::fromContext(Context::getCurrent())->getContext();
            if ($atual->isValid()) {
                $builder->setParent(Context::getCurrent());
            }
        }

        $span = $builder->startSpan();
        self::$escopos[] = $span->activate();

        return $span;
    }

    public static function sucesso(?SpanInterface $span): void
    {
        $span?->setStatus(StatusCode::STATUS_OK);
    }

    public static function erro(?SpanInterface $span, Throwable $e): void
    {
        if ($span === null) {
            return;
        }

        $span->recordException($e);
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
    }

    public static function finalizar(?SpanInterface $span): void
    {
        if ($span === null) {
            return;
        }

        $escopo = array_pop(self::$escopos);
        if ($escopo instanceof ScopeInterface) {
            $escopo->detach();
        }

        $span->end();
    }
}
