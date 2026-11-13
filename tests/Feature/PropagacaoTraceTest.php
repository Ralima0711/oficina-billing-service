<?php

namespace Tests\Feature;

use App\Mensageria\CaixaDeSaida;
use App\Mensageria\MessageTracing;
use App\Mensageria\PublicadorEventos;
use App\Observabilidade\ContextoObservabilidadeProcessor;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\SpanKind;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\Support\PublicadorFake;
use Tests\Support\Traceparent;
use Tests\TestCase;

class PropagacaoTraceTest extends TestCase
{
    use RefreshDatabase;
    use UsaTracerEmMemoria;

    private const TRACEPARENT = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    private const MESSAGE_ID = '33333333-3333-3333-3333-333333333333';

    private PublicadorFake $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = new PublicadorFake;
        $this->app->instance(PublicadorEventos::class, $this->publicador);
        $this->usarTracerEmMemoria();
    }

    protected function tearDown(): void
    {
        $this->restaurarTracer();

        parent::tearDown();
    }

    public function test_publicar_abre_span_producer_filho_do_consumo(): void
    {
        MessageTracing::consumir('billing.gerar-orcamento', ['traceparent' => self::TRACEPARENT], function (): void {
            $caixa = app(CaixaDeSaida::class);
            $caixa->registrar(self::MESSAGE_ID, 'GerarOrcamento');
            $caixa->gravar([
                'header' => [
                    'messageId' => self::MESSAGE_ID,
                    'sagaId' => '44444444-4444-4444-4444-444444444444',
                    'traceparent' => self::TRACEPARENT,
                ],
            ], 'orcamento.gerado', ['osId' => '11111111-1111-1111-1111-111111111111']);
        });

        app(CaixaDeSaida::class)->publicarPendentes(self::MESSAGE_ID);

        $spans = $this->spansExportados();
        $this->assertCount(2, $spans);

        $consumo = $spans[0];
        $publicacao = $spans[1];

        $this->assertSame('billing.gerar-orcamento process', $consumo->getName());
        $this->assertSame(SpanKind::KIND_CONSUMER, $consumo->getKind());
        $this->assertSame('orcamento.gerado publish', $publicacao->getName());
        $this->assertSame(SpanKind::KIND_PRODUCER, $publicacao->getKind());
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $consumo->getContext()->getTraceId());
        $this->assertSame($consumo->getSpanId(), $publicacao->getParentSpanId());

        $publicado = $this->publicador->eventos[0]['traceparent'];
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', Traceparent::traceId($publicado));
        $this->assertSame($publicacao->getContext()->getSpanId(), Traceparent::spanId($publicado));
        $this->assertSame($publicado, $this->publicador->eventos[0]['envelope']['header']['traceparent']);
    }

    public function test_log_leva_service_name_e_trace_id_do_span_corrente(): void
    {
        MessageTracing::consumir('billing.cobrar-pagamento', ['traceparent' => self::TRACEPARENT], function (): void {
            $record = (new ContextoObservabilidadeProcessor)(new LogRecord(
                datetime: new DateTimeImmutable,
                channel: 'test',
                level: Level::Info,
                message: 'dentro',
            ));

            $this->assertSame('oficina-billing-service', $record->context['service.name']);
            $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $record->context['trace.id']);
            $this->assertNotSame('', $record->context['span.id']);
        });
    }
}
