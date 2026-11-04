<?php

namespace Tests\Feature;

use App\Exceptions\CobrancaException;
use App\Exceptions\MensagemIlegivel;
use App\Mensageria\PublicadorEventos;
use App\Models\MensagemProcessada;
use App\Models\Pagamento;
use App\Services\ProcessarCobrarPagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicadorFake;
use Tests\TestCase;

class ConsumirCobrarPagamentoTest extends TestCase
{
    use RefreshDatabase;

    private PublicadorFake $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = new PublicadorFake;
        $this->app->instance(PublicadorEventos::class, $this->publicador);
        config([
            'services.mercadopago.poll_interval_seconds' => 0,
            'services.mercadopago.poll_max_attempts' => 2,
        ]);
    }

    public function test_publica_pagamento_confirmado_e_ignora_repeticao(): void
    {
        $orcamento = $this->orcamento();
        $this->fakeMercadoPago([
            $this->pagamentoAprovado(),
        ]);

        $mensagem = $this->mensagem($orcamento);
        $this->processar()->executar($mensagem);
        $this->processar()->executar($mensagem);

        $this->assertSame(1, Pagamento::query()->count());
        $this->assertCount(1, $this->publicador->eventos);
        $evento = $this->publicador->eventos[0];
        $this->assertSame('pagamento.confirmado', $evento['routingKey']);
        $this->assertSame('pagamento.confirmado', $evento['envelope']['header']['type']);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $evento['envelope']['header']['causationId']);
        $this->assertSame(241.8, $evento['envelope']['body']['valorPago']);
        $this->assertSame('credito', $evento['envelope']['body']['metodo']);
        $this->assertArrayNotHasKey('motivo', $evento['envelope']['body']);
    }

    public function test_esgota_o_polling_e_publica_recusado_por_timeout(): void
    {
        config(['services.mercadopago.poll_max_attempts' => 1]);
        $orcamento = $this->orcamento();
        $this->fakeMercadoPago([['results' => []]]);

        $this->processar()->executar($this->mensagem($orcamento, '66666666-6666-6666-6666-666666666666'));

        $this->assertSame('recusado', Pagamento::query()->first()->status);
        $this->assertSame('pagamento.recusado', $this->publicador->eventos[0]['routingKey']);
        $this->assertSame('timeout', $this->publicador->eventos[0]['envelope']['body']['motivo']);
    }

    public function test_valor_divergente_recusa_sem_chamar_o_mercado_pago(): void
    {
        Http::fake();
        $orcamento = $this->orcamento();
        $orcamento['valorTotal'] = 10;

        $this->processar()->executar($this->mensagem($orcamento, '77777777-7777-7777-7777-777777777777'));

        Http::assertNothingSent();
        $this->assertSame('recusado', Pagamento::query()->first()->status);
        $this->assertSame('valor_divergente', $this->publicador->eventos[0]['envelope']['body']['motivo']);
    }

    public function test_orcamento_inexistente_publica_recusa(): void
    {
        Http::fake();

        $this->processar()->executar($this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'orcamentoId' => '99999999-9999-9999-9999-999999999999',
            'valorTotal' => 241.8,
            'moeda' => 'BRL',
        ], '88888888-8888-8888-8888-888888888888'));

        Http::assertNothingSent();
        $this->assertSame(0, Pagamento::query()->count());
        $this->assertSame('orcamento_inexistente', $this->publicador->eventos[0]['envelope']['body']['motivo']);
    }

    public function test_falha_do_mercado_pago_nao_grava_a_mensagem(): void
    {
        Http::fake([
            'https://api.mercadopago.com/*' => Http::response(['message' => 'fora'], 503),
        ]);
        $orcamento = $this->orcamento();

        try {
            $this->processar()->executar($this->mensagem($orcamento, '12121212-1212-1212-1212-121212121212'));
            $this->fail('a cobrança deveria falhar');
        } catch (CobrancaException) {
            $this->assertSame(0, MensagemProcessada::query()->count());
            $this->assertSame([], $this->publicador->eventos);
        }
    }

    public function test_mensagem_sem_orcamento_id_nao_gera_efeito(): void
    {
        $mensagem = $this->mensagem($this->orcamento());
        unset($mensagem['body']['orcamentoId']);

        try {
            $this->processar()->executar($mensagem);
            $this->fail('a mensagem deveria ser ilegível');
        } catch (MensagemIlegivel) {
            $this->assertSame(0, Pagamento::query()->count());
            $this->assertSame([], $this->publicador->eventos);
        }
    }

    private function processar(): ProcessarCobrarPagamento
    {
        return $this->app->make(ProcessarCobrarPagamento::class);
    }

    /**
     * @param  array<string, mixed>  $orcamento
     * @return array<string, mixed>
     */
    private function mensagem(array $orcamento, string $messageId = '33333333-3333-3333-3333-333333333333'): array
    {
        return [
            'header' => [
                'messageId' => $messageId,
                'sagaId' => '44444444-4444-4444-4444-444444444444',
                'type' => 'CobrarPagamento',
                'occurredAt' => '2026-11-04T18:00:00Z',
                'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                'version' => 1,
            ],
            'body' => [
                'osId' => $orcamento['osId'],
                'orcamentoId' => $orcamento['orcamentoId'],
                'valorTotal' => $orcamento['valorTotal'],
                'moeda' => 'BRL',
            ],
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
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 2],
                ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
            ],
        ])->assertCreated()->json();
    }

    /**
     * @param  list<array<string, mixed>>  $buscas
     */
    private function fakeMercadoPago(array $buscas): void
    {
        $sequencia = Http::sequence();
        foreach ($buscas as $busca) {
            $sequencia->push($busca, 200);
        }

        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref-1',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
            ], 201),
            'https://api.mercadopago.com/v1/payments/search*' => $sequencia,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pagamentoAprovado(): array
    {
        return [
            'results' => [[
                'id' => 999,
                'status' => 'approved',
                'transaction_amount' => 241.8,
                'payment_type_id' => 'credit_card',
                'payment_method_id' => 'visa',
                'date_approved' => '2026-11-04T18:20:00.000Z',
            ]],
        ];
    }
}
