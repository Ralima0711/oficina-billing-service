<?php

namespace App\Observabilidade;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use Throwable;

class OpenTelemetryProvider
{
    private const SERVICO_PADRAO = 'oficina-billing-service';

    private static ?TracerProviderInterface $provider = null;

    private static bool $shutdownRegistrado = false;

    public static function nomeServico(): string
    {
        return (string) (config('observabilidade.service_name') ?: self::SERVICO_PADRAO);
    }

    public static function tracer(): TracerInterface
    {
        return self::provider()->getTracer(self::nomeServico());
    }

    public static function provider(): TracerProviderInterface
    {
        if (self::$provider !== null) {
            return self::$provider;
        }

        $endpoint = (string) (config('observabilidade.endpoint') ?: '');

        if ($endpoint === '') {
            self::$provider = new TracerProvider([], new AlwaysOnSampler);

            return self::$provider;
        }

        try {
            self::$provider = self::criarProviderOtlp($endpoint);
        } catch (Throwable $e) {
            error_log('[otel] exportação desativada, OTEL_EXPORTER_OTLP_ENDPOINT inválido: '.$e->getMessage());
            self::$provider = new TracerProvider([], new AlwaysOnSampler);

            return self::$provider;
        }

        self::registrarShutdown();

        return self::$provider;
    }

    private static function criarProviderOtlp(string $endpoint): TracerProviderInterface
    {
        $headers = self::parseHeaders((string) (config('observabilidade.headers') ?: ''));

        $transport = (new OtlpHttpTransportFactory)->create(
            $endpoint.'/v1/traces',
            'application/x-protobuf',
            $headers,
        );

        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(Attributes::create([
                ServiceAttributes::SERVICE_NAME => self::nomeServico(),
            ])),
        );

        return new TracerProvider(
            [new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault())],
            new AlwaysOnSampler,
            $resource,
        );
    }

    /**
     * O BatchSpanProcessor só envia no flush ou no shutdown. Em PHP o processo
     * morre sem avisar o SDK, então o shutdown fica registrado aqui.
     */
    private static function registrarShutdown(): void
    {
        if (self::$shutdownRegistrado) {
            return;
        }

        self::$shutdownRegistrado = true;

        register_shutdown_function(static function (): void {
            $provider = self::$provider;

            if ($provider !== null && method_exists($provider, 'shutdown')) {
                $provider->shutdown();
            }
        });
    }

    /**
     * Worker é processo longo: o shutdown não chega entre uma mensagem e outra.
     */
    public static function flush(): void
    {
        $provider = self::$provider;

        if ($provider !== null && method_exists($provider, 'forceFlush')) {
            $provider->forceFlush();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (explode(',', $raw) as $par) {
            $par = trim($par);
            if ($par === '' || ! str_contains($par, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $par, 2);
            $headers[trim($chave)] = trim($valor);
        }

        return $headers;
    }
}
