<?php

namespace App\Http\Middleware;

use App\Mensageria\TraceContextCarrier;
use App\Observabilidade\OpenTelemetryProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Um span SERVER por request, filho do traceparent recebido. Sem header, abre
 * trace novo. A decisão do cliente não usa este span como pai do evento: a
 * saga esperou dias, e o pai é o traceparent guardado no orçamento.
 */
class CorrelacaoRequisicaoMiddleware
{
    public const HEADER = 'X-Request-Id';

    private ?ScopeInterface $escopo = null;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolverRequestId($request);
        $request->attributes->set('request_id', $requestId);

        $span = $this->iniciarSpan($request);

        $contexto = [
            'request.id' => $requestId,
            'service.name' => OpenTelemetryProvider::nomeServico(),
            'http.method' => $request->method(),
            'http.route' => $request->path(),
        ];

        $spanContext = $span?->getContext();
        if ($spanContext !== null && $spanContext->isValid()) {
            $contexto['trace.id'] = $spanContext->getTraceId();
            $contexto['span.id'] = $spanContext->getSpanId();
        }

        Log::withContext($contexto);

        $inicio = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $this->desativarSpan();
            $span?->end();

            throw $e;
        }

        $duracaoMs = round((microtime(true) - $inicio) * 1000, 2);

        $span?->setAttribute('http.status_code', $response->getStatusCode());
        $span?->setAttribute('http.duration_ms', $duracaoMs);

        if ($response->getStatusCode() >= 500) {
            $span?->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->desativarSpan();
        $span?->end();

        $response->headers->set(self::HEADER, $requestId);

        Log::info('requisicao_processada', [
            'http.status_code' => $response->getStatusCode(),
            'http.duration_ms' => $duracaoMs,
        ]);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        OpenTelemetryProvider::flush();
    }

    private function resolverRequestId(Request $request): string
    {
        $recebido = $request->header(self::HEADER);

        if (is_string($recebido) && trim($recebido) !== '') {
            return trim($recebido);
        }

        return (string) Str::uuid();
    }

    private function iniciarSpan(Request $request): ?SpanInterface
    {
        if (! class_exists(Span::class)) {
            return null;
        }

        $rota = $request->route()?->uri() ?? $request->path();

        $pai = TraceContextCarrier::extract([
            TraceContextCarrier::TRACEPARENT => $request->header(TraceContextCarrier::TRACEPARENT),
            TraceContextCarrier::TRACESTATE => $request->header(TraceContextCarrier::TRACESTATE),
        ]);

        $span = OpenTelemetryProvider::tracer()
            ->spanBuilder(sprintf('%s /%s', $request->method(), ltrim($rota, '/')))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($pai)
            ->setAttribute('http.method', $request->method())
            ->setAttribute('http.route', $rota)
            ->setAttribute('http.target', $request->getRequestUri())
            ->startSpan();

        $this->escopo = $span->activate();

        return $span;
    }

    private function desativarSpan(): void
    {
        if ($this->escopo instanceof ScopeInterface) {
            $this->escopo->detach();
            $this->escopo = null;
        }
    }
}
