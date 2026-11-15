<?php

namespace Tests\Feature;

use App\Observabilidade\OpenTelemetryProvider;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\TracerProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class OpenTelemetryProviderTest extends TestCase
{
    private string $errorLogOriginal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->errorLogOriginal = (string) ini_get('error_log');
        $this->trocarProvider(null);
    }

    protected function tearDown(): void
    {
        // Volta ao estado que o resto da suite espera: provider recriado sob
        // demanda, sem exportador. Tambem neutraliza o shutdown registrado.
        $this->trocarProvider(null);
        ini_set('error_log', $this->errorLogOriginal);

        parent::tearDown();
    }

    public function test_sem_endpoint_cria_provider_local_uma_vez_so(): void
    {
        config(['observabilidade.endpoint' => '']);

        $provider = OpenTelemetryProvider::provider();

        $this->assertInstanceOf(TracerProvider::class, $provider);
        $this->assertSame($provider, OpenTelemetryProvider::provider());
    }

    public function test_nome_do_servico_vem_da_config_ou_do_padrao(): void
    {
        config(['observabilidade.service_name' => 'billing-em-teste']);
        $this->assertSame('billing-em-teste', OpenTelemetryProvider::nomeServico());

        config(['observabilidade.service_name' => '']);
        $this->assertSame('oficina-billing-service', OpenTelemetryProvider::nomeServico());
    }

    public function test_com_endpoint_otlp_os_spans_levam_o_service_name(): void
    {
        config([
            'observabilidade.endpoint' => 'http://127.0.0.1:4318',
            'observabilidade.headers' => 'api-key=segredo',
            'observabilidade.service_name' => 'billing-otlp',
        ]);

        $provider = OpenTelemetryProvider::provider();
        $this->assertInstanceOf(TracerProvider::class, $provider);

        $span = OpenTelemetryProvider::tracer()->spanBuilder('teste')->startSpan();
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame(
            'billing-otlp',
            $span->toSpanData()->getResource()->getAttributes()->get('service.name'),
        );

        // Nada foi finalizado: o flush nao tem o que mandar e nao pode falhar.
        OpenTelemetryProvider::flush();
        $this->assertSame($provider, OpenTelemetryProvider::provider());
    }

    public function test_endpoint_invalido_desliga_a_exportacao_sem_derrubar_o_servico(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'otel');
        ini_set('error_log', $log);
        config(['observabilidade.endpoint' => 'isto nao e url']);

        $provider = OpenTelemetryProvider::provider();

        $this->assertInstanceOf(TracerProvider::class, $provider);
        $this->assertTrue(OpenTelemetryProvider::tracer()->spanBuilder('ok')->startSpan()->isRecording());
        $this->assertStringContainsString(
            '[otel] exportação desativada, OTEL_EXPORTER_OTLP_ENDPOINT inválido',
            (string) file_get_contents($log),
        );

        @unlink($log);
    }

    public function test_headers_otlp_aceitam_lista_separada_por_virgula(): void
    {
        $parse = new ReflectionMethod(OpenTelemetryProvider::class, 'parseHeaders');

        $this->assertSame(
            ['api-key' => 'abc', 'x-time' => 'a=b'],
            $parse->invoke(null, ' api-key = abc , , sem-igual, x-time=a=b'),
        );
        $this->assertSame([], $parse->invoke(null, ''));
    }

    public function test_flush_sem_provider_nao_faz_nada(): void
    {
        OpenTelemetryProvider::flush();

        $this->assertNull((new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->getValue());
    }

    private function trocarProvider(?object $provider): void
    {
        (new ReflectionProperty(OpenTelemetryProvider::class, 'provider'))->setValue(null, $provider);
    }
}
