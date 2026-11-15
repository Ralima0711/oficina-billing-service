<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Tests\Concerns\UsaTracerEmMemoria;
use Tests\TestCase;

class CorrelacaoRequisicaoTest extends TestCase
{
    use RefreshDatabase;
    use UsaTracerEmMemoria;

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

    public function test_devolve_o_request_id_recebido(): void
    {
        $this->getJson('/api/health', ['X-Request-Id' => '  req-123  '])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'req-123');
    }

    public function test_sem_request_id_gera_um_uuid(): void
    {
        $resposta = $this->getJson('/api/health')->assertOk();

        $this->assertTrue(Str::isUuid((string) $resposta->headers->get('X-Request-Id')));
    }

    public function test_span_server_e_filho_do_traceparent_e_leva_rota_e_status(): void
    {
        $this->getJson('/api/orcamentos/99999999-9999-9999-9999-999999999999', [
            'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
        ])->assertNotFound();

        $span = $this->spanServer();
        $this->assertSame('GET /api/orcamentos/{id}', $span->getName());
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $span->getTraceId());
        $this->assertSame('bbbbbbbbbbbbbbbb', $span->getParentSpanId());
        $this->assertSame(404, $span->getAttributes()->get('http.status_code'));
        $this->assertSame('GET', $span->getAttributes()->get('http.method'));
        $this->assertIsFloat($span->getAttributes()->get('http.duration_ms'));
        // 4xx e erro do cliente: o span nao e marcado como falha do servico.
        $this->assertNotSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    public function test_resposta_5xx_marca_o_span_com_erro(): void
    {
        $orcamento = $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Barulho no freio',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ])->assertCreated()->json();

        config(['services.mercadopago.access_token' => '']);

        $this->postJson('/api/orcamentos/'.$orcamento['orcamentoId'].'/pagamentos')
            ->assertStatus(503)
            ->assertJsonPath('message', 'MP_ACCESS_TOKEN não está configurado.');

        $span = $this->spanServer('POST /api/orcamentos/{id}/pagamentos');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(503, $span->getAttributes()->get('http.status_code'));
    }

    private function spanServer(?string $nome = null): \OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        $spans = array_values(array_filter(
            $this->spansExportados(),
            fn ($span) => $span->getKind() === SpanKind::KIND_SERVER && ($nome === null || $span->getName() === $nome),
        ));

        $this->assertNotEmpty($spans, 'nenhum span SERVER exportado');

        return end($spans);
    }
}
