<?php

namespace Tests\Feature;

use App\Exceptions\CobrancaException;
use App\Exceptions\MensagemIlegivel;
use App\Mensageria\PublicadorEventos;
use App\Models\MensagemProcessada;
use App\Models\Orcamento;
use App\Models\Pagamento;
use App\Services\ProcessarAnularOrcamento;
use App\Services\ProcessarEstornarPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicadorFake;
use Tests\TestCase;

class ConsumirCompensacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PublicadorEventos::class, new PublicadorFake);
    }

    public function test_anula_orcamento_e_ignora_repeticao(): void
    {
        $orcamento = $this->orcamento();
        $mensagem = $this->mensagem($orcamento['osId'], $orcamento['orcamentoId'], 'pagamento_recusado');

        $this->anular()->executar($mensagem);
        $this->anular()->executar($mensagem);

        $this->assertSame('anulado', Orcamento::query()->find($orcamento['orcamentoId'])->status);
        $this->assertSame(1, MensagemProcessada::query()->count());
    }

    public function test_anular_orcamento_inexistente_e_pulado(): void
    {
        $this->anular()->executar($this->mensagem(
            '11111111-1111-1111-1111-111111111111',
            '99999999-9999-9999-9999-999999999999',
            'aprovacao_expirada',
            '66666666-6666-6666-6666-666666666666',
        ));

        $this->assertSame(0, Orcamento::query()->count());
        $this->assertSame(1, MensagemProcessada::query()->count());
    }

    public function test_estorna_pagamento_confirmado_no_mercado_pago(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/999/refunds' => Http::response(['status' => 'approved'], 201),
        ]);
        $pagamento = $this->pagamentoConfirmado();
        $mensagem = $this->mensagem($pagamento->os_id, $pagamento->orcamento_id, 'execucao_falhou', '77777777-7777-7777-7777-777777777777', $pagamento->id);

        $this->estornar()->executar($mensagem);
        $this->estornar()->executar($mensagem);

        $this->assertSame('estornado', $pagamento->fresh()->status);
        $this->assertSame('execucao_falhou', $pagamento->fresh()->motivo);
        Http::assertSentCount(1);
    }

    public function test_estorno_de_pagamento_recusado_nao_chama_o_mercado_pago(): void
    {
        Http::fake();
        $pagamento = $this->pagamentoConfirmado();
        $pagamento->status = 'recusado';
        $pagamento->motivo = 'timeout';
        $pagamento->save();

        $this->estornar()->executar($this->mensagem(
            $pagamento->os_id,
            $pagamento->orcamento_id,
            'pagamento_recusado',
            '88888888-8888-8888-8888-888888888888',
            $pagamento->id,
        ));

        Http::assertNothingSent();
        $this->assertSame('recusado', $pagamento->fresh()->status);
        $this->assertSame(1, MensagemProcessada::query()->count());
    }

    public function test_falha_do_mercado_pago_nao_grava_o_estorno(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/999/refunds' => Http::response(['message' => 'fora'], 503),
            'https://api.mercadopago.com/v1/payments/999' => Http::response(['status' => 'approved'], 200),
        ]);
        $pagamento = $this->pagamentoConfirmado();

        try {
            $this->estornar()->executar($this->mensagem(
                $pagamento->os_id,
                $pagamento->orcamento_id,
                'execucao_falhou',
                '12121212-1212-1212-1212-121212121212',
                $pagamento->id,
            ));
            $this->fail('o estorno deveria falhar');
        } catch (CobrancaException) {
            $this->assertSame('confirmado', $pagamento->fresh()->status);
            $this->assertSame(0, MensagemProcessada::query()->count());
        }
    }

    public function test_motivo_invalido_nao_gera_efeito(): void
    {
        $orcamento = $this->orcamento();
        $mensagem = $this->mensagem($orcamento['osId'], $orcamento['orcamentoId'], 'pagamento_recusado');
        $mensagem['body']['motivo'] = 'cancelado_pelo_cliente';

        try {
            $this->anular()->executar($mensagem);
            $this->fail('o motivo deveria ser ilegível');
        } catch (MensagemIlegivel) {
            $this->assertSame('gerado', Orcamento::query()->find($orcamento['orcamentoId'])->status);
            $this->assertSame(0, MensagemProcessada::query()->count());
        }
    }

    private function anular(): ProcessarAnularOrcamento
    {
        return $this->app->make(ProcessarAnularOrcamento::class);
    }

    private function estornar(): ProcessarEstornarPagamento
    {
        return $this->app->make(ProcessarEstornarPagamento::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function mensagem(string $osId, string $orcamentoId, string $motivo, string $messageId = '33333333-3333-3333-3333-333333333333', ?string $pagamentoId = null): array
    {
        $body = [
            'osId' => $osId,
            'orcamentoId' => $orcamentoId,
            'motivo' => $motivo,
        ];

        if ($pagamentoId !== null) {
            $body['pagamentoId'] = $pagamentoId;
        }

        return [
            'header' => [
                'messageId' => $messageId,
                'sagaId' => '44444444-4444-4444-4444-444444444444',
                'type' => 'AnularOrcamento',
                'occurredAt' => '2026-11-10T18:00:00Z',
                'version' => 1,
            ],
            'body' => $body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orcamento(): array
    {
        return $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Barulho no freio',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ])->assertCreated()->json();
    }

    private function pagamentoConfirmado(): Pagamento
    {
        $orcamento = $this->orcamento();

        return Pagamento::query()->create([
            'orcamento_id' => $orcamento['orcamentoId'],
            'os_id' => $orcamento['osId'],
            'preference_id' => 'pref-1',
            'payment_id' => '999',
            'status' => 'confirmado',
            'valor' => '45.90',
            'moeda' => 'BRL',
            'metodo' => 'credito',
            'confirmado_em' => now(),
        ]);
    }
}
