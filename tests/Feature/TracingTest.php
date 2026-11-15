<?php

namespace Tests\Feature;

use App\Mensageria\MessageTracing;
use App\Observabilidade\Tracing;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\TestCase;

class TracingTest extends TestCase
{
    use UsaTracerEmMemoria;

    private const TRACEPARENT = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->usarTracerEmMemoria();
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();

        parent::tearDown();
    }

    public function test_span_aninhado_herda_o_span_corrente_e_ignora_atributo_nulo(): void
    {
        $externo = Tracing::iniciar('externo', ['os.id' => 'os-1', 'vazio' => null]);
        $interno = Tracing::iniciar('interno');
        Tracing::sucesso($interno);
        Tracing::finalizar($interno);
        Tracing::finalizar($externo);

        [$filho, $pai] = $this->spansExportados();

        $this->assertSame('interno', $filho->getName());
        $this->assertSame($pai->getSpanId(), $filho->getParentSpanId());
        $this->assertSame(StatusCode::STATUS_OK, $filho->getStatus()->getCode());
        $this->assertSame('os-1', $pai->getAttributes()->get('os.id'));
        $this->assertFalse($pai->getAttributes()->has('vazio'));
        $this->assertSame(SpanKind::KIND_INTERNAL, $pai->getKind());
    }

    public function test_erro_registra_a_excecao_e_marca_o_status(): void
    {
        $span = Tracing::iniciar('com-erro');
        Tracing::erro($span, new RuntimeException('deu ruim'));
        Tracing::finalizar($span);

        $exportado = $this->spansExportados()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $exportado->getStatus()->getCode());
        $this->assertSame('deu ruim', $exportado->getStatus()->getDescription());
        $this->assertSame('exception', $exportado->getEvents()[0]->getName());
    }

    public function test_funcoes_aceitam_span_nulo_sem_efeito(): void
    {
        Tracing::sucesso(null);
        Tracing::erro(null, new RuntimeException('ignorado'));
        Tracing::finalizar(null);

        $this->assertSame([], $this->spansExportados());
    }

    public function test_falha_no_consumo_fecha_o_span_com_erro_e_propaga(): void
    {
        try {
            MessageTracing::consumir('billing.cobrar-pagamento', ['traceparent' => self::TRACEPARENT], function (): void {
                throw new RuntimeException('processador falhou');
            }, ['os.id' => 'os-9']);
            $this->fail('a excecao do processador deveria subir');
        } catch (RuntimeException $e) {
            $this->assertSame('processador falhou', $e->getMessage());
        }

        $span = $this->spansExportados()[0];
        $this->assertSame('billing.cobrar-pagamento process', $span->getName());
        $this->assertSame(SpanKind::KIND_CONSUMER, $span->getKind());
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $span->getTraceId());
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('os-9', $span->getAttributes()->get('os.id'));
    }

    public function test_falha_na_publicacao_fecha_o_span_producer_com_erro(): void
    {
        $recebidos = null;

        try {
            MessageTracing::publicar('pagamento.confirmado', function (array $headers) use (&$recebidos): void {
                $recebidos = $headers;

                throw new RuntimeException('broker fora');
            });
            $this->fail('a falha de publicacao deveria subir');
        } catch (RuntimeException) {
            // esperado
        }

        $span = $this->spansExportados()[0];
        $this->assertSame('pagamento.confirmado publish', $span->getName());
        $this->assertSame(SpanKind::KIND_PRODUCER, $span->getKind());
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        // O traceparent entregue ao publicador e o do proprio span PRODUCER.
        $this->assertStringContainsString($span->getSpanId(), $recebidos['traceparent']);
    }

    public function test_publicacao_bem_sucedida_devolve_o_resultado(): void
    {
        $resultado = MessageTracing::publicar('orcamento.gerado', fn (array $headers) => 'publicado');

        $this->assertSame('publicado', $resultado);
        $this->assertSame(StatusCode::STATUS_OK, $this->spansExportados()[0]->getStatus()->getCode());
    }
}
