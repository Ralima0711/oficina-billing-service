<?php

namespace Tests\Feature;

use App\Models\Pagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PagamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_preferencia_no_sandbox(): void
    {
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref-1',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
            ], 201),
        ]);

        $orcamento = $this->orcamento();

        $resposta = $this->postJson('/api/orcamentos/'.$orcamento['orcamentoId'].'/pagamentos');

        $resposta->assertCreated()
            ->assertJsonPath('osId', $orcamento['osId'])
            ->assertJsonPath('orcamentoId', $orcamento['orcamentoId'])
            ->assertJsonPath('status', 'pendente')
            ->assertJsonPath('sandboxInitPoint', 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1');

        Http::assertSent(function ($request) use ($orcamento) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.mercadopago.com/checkout/preferences'
                && $request->hasHeader('Authorization', 'Bearer TEST-token')
                && $request['external_reference'] === $orcamento['orcamentoId']
                && abs($request['items'][0]['unit_price'] - 241.8) < 0.001;
        });
    }

    public function test_reaproveita_preferencia_pendente(): void
    {
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref-1',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
            ], 201),
        ]);

        $orcamento = $this->orcamento();
        $primeira = $this->postJson('/api/orcamentos/'.$orcamento['orcamentoId'].'/pagamentos')->assertCreated();
        $segunda = $this->postJson('/api/orcamentos/'.$orcamento['orcamentoId'].'/pagamentos')->assertOk();

        $this->assertSame($primeira->json('pagamentoId'), $segunda->json('pagamentoId'));
        $this->assertSame(1, Pagamento::query()->count());
        Http::assertSentCount(1);
    }

    public function test_consulta_pagamento_aprovado(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [[
                    'id' => 999,
                    'status' => 'approved',
                    'transaction_amount' => 241.8,
                    'payment_type_id' => 'credit_card',
                    'payment_method_id' => 'visa',
                    'date_approved' => '2026-11-01T22:20:00.000-03:00',
                ]],
            ]),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('valorPago', 241.8)
            ->assertJsonPath('metodo', 'credito')
            ->assertJsonPath('confirmadoEm', '2026-11-02T01:20:00Z')
            ->assertJsonMissingPath('motivo');
    }

    public function test_consulta_pagamento_recusado(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [[
                    'id' => 1000,
                    'status' => 'rejected',
                    'status_detail' => 'cc_rejected_other_reason',
                    'payment_type_id' => 'credit_card',
                    'payment_method_id' => 'visa',
                    'date_created' => '2026-11-01T22:21:00.000-03:00',
                ]],
            ]),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('motivo', 'cc_rejected_other_reason')
            ->assertJsonPath('metodo', 'credito');
    }

    public function test_consulta_sem_pagamento_no_mercado_pago_continua_pendente(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []]),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('status', 'pendente')
            ->assertJsonPath('sandboxInitPoint', $pagamento->sandbox_init_point);
    }

    public function test_recusa_orcamento_sem_valor(): void
    {
        Http::fake();

        $orcamento = $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Sem itens',
        ])->assertCreated();

        $this->postJson('/api/orcamentos/'.$orcamento->json('orcamentoId').'/pagamentos')
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_recusa_cobranca_sem_token(): void
    {
        config(['services.mercadopago.access_token' => '']);
        Http::fake();

        $orcamento = $this->orcamento();

        $this->postJson('/api/orcamentos/'.$orcamento['orcamentoId'].'/pagamentos')
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    private function orcamento(): array
    {
        return $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
            'descricaoProblema' => 'Barulho no freio',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 2],
                ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
            ],
        ])->assertCreated()->json();
    }

    private function pagamentoPendente(): Pagamento
    {
        $orcamento = $this->orcamento();

        return Pagamento::query()->create([
            'orcamento_id' => $orcamento['orcamentoId'],
            'os_id' => $orcamento['osId'],
            'preference_id' => 'pref-1',
            'status' => 'pendente',
            'valor' => '241.80',
            'moeda' => 'BRL',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
        ]);
    }
}
