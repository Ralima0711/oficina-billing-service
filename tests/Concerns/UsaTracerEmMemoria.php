<?php

namespace Tests\Concerns;

use App\Observabilidade\OpenTelemetryProvider;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use ReflectionProperty;

trait UsaTracerEmMemoria
{
    private InMemoryExporter $exportador;

    protected function usarTracerEmMemoria(): void
    {
        $this->exportador = new InMemoryExporter;

        $this->substituirProvider(new TracerProvider(
            [new SimpleSpanProcessor($this->exportador)],
            new AlwaysOnSampler,
        ));
    }

    protected function restaurarTracer(): void
    {
        $this->substituirProvider(null);
    }

    /**
     * @return array<int, ImmutableSpan>
     */
    protected function spansExportados(): array
    {
        return $this->exportador->getSpans();
    }

    private function substituirProvider(?object $provider): void
    {
        (new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->setValue(null, $provider);
    }
}
