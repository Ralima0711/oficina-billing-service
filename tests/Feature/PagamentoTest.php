<?php

namespace Tests\Feature;

use App\Models\Orcamento;
use App\Models\Pagamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $orcamento = Orcamento::query()->create([
            'os_id' => '11111111-1111-1111-1111-111111111111',
            'cliente_id' => '22222222-2222-2222-2222-222222222222',
            'descricao_problema' => 'Sem itens',
            'veiculo' => ['placa' => 'DEM1A23'],
            'valor_total' => '0.00',
            'moeda' => 'BRL',
            'valido_ate' => now()->addDays(7),
            'status' => 'gerado',
        ]);

        $this->postJson('/api/orcamentos/'.$orcamento->id.'/pagamentos')
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_impede_dois_pagamentos_ativos_no_mesmo_orcamento(): void
    {
        $pagamento = $this->pagamentoPendente();

        $this->expectException(UniqueConstraintViolationException::class);

        Pagamento::query()->create([
            'orcamento_id' => $pagamento->orcamento_id,
            'os_id' => $pagamento->os_id,
            'preference_id' => 'pref-2',
            'status' => 'pendente',
            'valor' => '241.80',
            'moeda' => 'BRL',
        ]);
    }

    public function test_permite_nova_cobranca_depois_da_recusa(): void
    {
        $pagamento = $this->pagamentoPendente();
        $pagamento->status = 'recusado';
        $pagamento->motivo = 'timeout';
        $pagamento->save();

        Pagamento::query()->create([
            'orcamento_id' => $pagamento->orcamento_id,
            'os_id' => $pagamento->os_id,
            'preference_id' => 'pref-2',
            'status' => 'pendente',
            'valor' => '241.80',
            'moeda' => 'BRL',
        ]);

        $this->assertSame(2, Pagamento::query()->count());
    }

    public function test_esgota_o_polling_com_motivo_timeout(): void
    {
        config(['services.mercadopago.poll_max_attempts' => 1]);
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []]),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('motivo', 'timeout');
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

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function meiosDePagamento(): array
    {
        return [
            'pix pelo metodo' => ['account_money', 'pix', 'pix'],
            'debito' => ['debit_card', 'maestro', 'debito'],
            'boleto' => ['ticket', 'bolbradesco', 'boleto'],
            'transferencia' => ['bank_transfer', 'pec', 'pix'],
            'desconhecido' => ['crypto', 'btc', null],
        ];
    }

    #[DataProvider('meiosDePagamento')]
    public function test_traduz_o_meio_de_pagamento_do_mercado_pago(string $tipo, string $metodo, ?string $esperado): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [[
                    'id' => 1001,
                    'status' => 'approved',
                    'transaction_amount' => 241.8,
                    'payment_type_id' => $tipo,
                    'payment_method_id' => $metodo,
                ]],
            ]),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('metodo', $esperado);

        // Sem date_approved, o instante de confirmacao e o da consulta.
        $this->assertNotNull($pagamento->fresh()->confirmado_em);
        $this->assertSame('1001', $pagamento->fresh()->payment_id);
    }

    public function test_cancelado_no_mercado_pago_vira_recusado_com_motivo_padrao(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [['id' => 1002, 'status' => 'cancelled']],
            ]),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('motivo', 'recusado');
        $this->assertSame('recusado', $pagamento->fresh()->status);
    }

    public function test_pagamento_ja_confirmado_nao_consulta_o_mercado_pago(): void
    {
        Http::fake();
        $pagamento = $this->pagamentoPendente();
        $pagamento->forceFill([
            'status' => 'confirmado',
            'metodo' => 'credito',
            'confirmado_em' => now(),
        ])->save();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertOk()
            ->assertJsonPath('metodo', 'credito');

        Http::assertNothingSent();
        $this->assertSame(0, (int) $pagamento->fresh()->tentativas);
    }

    public function test_falha_na_consulta_do_mercado_pago_e_502(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['message' => 'fora'], 500),
        ]);

        $pagamento = $this->pagamentoPendente();

        $this->getJson('/api/pagamentos/'.$pagamento->id)
            ->assertStatus(502)
            ->assertJsonPath('message', 'Mercado Pago respondeu HTTP 500 ao criar ou consultar o pagamento.');
        $this->assertSame('pendente', $pagamento->fresh()->status);
    }

    public function test_preferencia_sem_sandbox_init_point_e_502(): void
    {
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'pref-1'], 201),
        ]);

        $orcamento = $this->orcamento();

        $this->postJson('/api/orcamentos/'.$orcamento['orcamentoId'].'/pagamentos')
            ->assertStatus(502);
    }

    public function test_pagamento_pendente_sem_preferencia_ganha_uma_ao_cobrar_de_novo(): void
    {
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref-9',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-9',
            ], 201),
        ]);
        $pagamento = $this->pagamentoPendente();
        $pagamento->forceFill(['preference_id' => null, 'sandbox_init_point' => null])->save();

        $this->postJson('/api/orcamentos/'.$pagamento->orcamento_id.'/pagamentos')
            ->assertOk()
            ->assertJsonPath('pagamentoId', $pagamento->id)
            ->assertJsonPath('sandboxInitPoint', 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-9');

        $this->assertSame('pref-9', $pagamento->fresh()->preference_id);
        $this->assertSame(1, Pagamento::query()->count());
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
