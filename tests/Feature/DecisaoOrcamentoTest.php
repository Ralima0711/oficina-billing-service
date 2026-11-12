<?php

namespace Tests\Feature;

use App\Mensageria\PublicadorEventos;
use App\Models\MensagemSaida;
use App\Models\Orcamento;
use App\Services\ProcessarGerarOrcamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicadorFake;
use Tests\TestCase;

/**
 * A decisao do cliente sobre o orcamento — POST /api/orcamentos/{id}/decisao.
 *
 * Este e o unico ponto da saga que nao e automatico: entre orcamento.gerado e
 * CobrarPagamento existe uma espera humana, e ate aqui nada no sistema a
 * encerrava. A OS entrava em AGUARDANDO_APROVACAO e so saia de la pelo TTL de
 * sete dias — ou seja, a saga tinha um caminho feliz que nao existia.
 */
class DecisaoOrcamentoTest extends TestCase
{
    use RefreshDatabase;

    private PublicadorFake $publicador;

    private const SAGA = '44444444-4444-4444-4444-444444444444';

    private const OS = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = new PublicadorFake;
        $this->app->instance(PublicadorEventos::class, $this->publicador);
    }

    public function test_aprovacao_publica_orcamento_aprovado_com_a_saga_do_comando(): void
    {
        $orcamento = $this->orcamentoDaSaga();

        $resposta = $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", [
            'decisao' => 'aprovar',
        ]);

        $resposta->assertAccepted()
            ->assertJsonPath('orcamentoId', $orcamento->id)
            ->assertJsonPath('osId', self::OS);

        $evento = $this->eventoDeDecisao();

        $this->assertSame('orcamento.aprovado', $evento['routingKey']);
        $this->assertSame('orcamento.aprovado', $evento['envelope']['header']['type']);

        // O sagaId e o que faz o evento voltar para a saga certa: sem ele o
        // Orchestrator do OS descarta em sagaAtiva().
        $this->assertSame(self::SAGA, $evento['envelope']['header']['sagaId']);

        $corpo = $evento['envelope']['body'];
        $this->assertSame(self::OS, $corpo['osId']);
        $this->assertSame($orcamento->id, $corpo['orcamentoId']);
        $this->assertArrayHasKey('decidioEm', $corpo);
        $this->assertArrayNotHasKey('motivo', $corpo);

        $this->assertSame('aprovado', $orcamento->fresh()->status);
        $this->assertNotNull(MensagemSaida::query()->whereNotNull('publicado_em')->first());
    }

    public function test_recusa_publica_orcamento_recusado_com_motivo(): void
    {
        $orcamento = $this->orcamentoDaSaga();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", [
            'decisao' => 'recusar',
            'motivo' => 'achou caro',
        ])->assertAccepted();

        $evento = $this->eventoDeDecisao();

        $this->assertSame('orcamento.recusado', $evento['routingKey']);
        $this->assertSame('achou caro', $evento['envelope']['body']['motivo']);
        $this->assertSame('recusado', $orcamento->fresh()->status);
    }

    public function test_motivo_em_aprovacao_nao_vai_para_o_evento(): void
    {
        $orcamento = $this->orcamentoDaSaga();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", [
            'decisao' => 'aprovar',
            'motivo' => 'nao deveria aparecer',
        ])->assertAccepted();

        $this->assertArrayNotHasKey('motivo', $this->eventoDeDecisao()['envelope']['body']);
    }

    public function test_segunda_decisao_e_recusada_e_nao_publica_de_novo(): void
    {
        $orcamento = $this->orcamentoDaSaga();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", ['decisao' => 'aprovar'])
            ->assertAccepted();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", ['decisao' => 'recusar'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'aprovado');

        $this->assertCount(1, $this->eventosDeDecisao());
    }

    public function test_orcamento_anulado_nao_pode_ser_decidido(): void
    {
        $orcamento = $this->orcamentoDaSaga();
        $orcamento->forceFill(['status' => 'anulado'])->save();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", ['decisao' => 'aprovar'])
            ->assertStatus(409);

        $this->assertCount(0, $this->eventosDeDecisao());
    }

    public function test_orcamento_vencido_nao_pode_ser_aprovado(): void
    {
        $orcamento = $this->orcamentoDaSaga();
        $orcamento->forceFill(['valido_ate' => now()->subMinute()])->save();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", ['decisao' => 'aprovar'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'gerado');

        $this->assertCount(0, $this->eventosDeDecisao());
    }

    public function test_orcamento_sem_saga_nao_pode_ser_decidido(): void
    {
        // Este veio do POST /api/orcamentos, que e porta avulsa e nao tem saga.
        $resposta = $this->postJson('/api/orcamentos', [
            'osId' => self::OS,
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Barulho no freio',
            'itensDiagnosticados' => [
                ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
            ],
        ])->assertCreated();

        $id = $resposta->json('orcamentoId');
        $this->assertNull(Orcamento::query()->find($id)->saga_id);

        $this->postJson("/api/orcamentos/{$id}/decisao", ['decisao' => 'aprovar'])
            ->assertStatus(409);

        $this->assertCount(0, $this->eventosDeDecisao());
    }

    public function test_decisao_invalida_e_422(): void
    {
        $orcamento = $this->orcamentoDaSaga();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", ['decisao' => 'talvez'])
            ->assertUnprocessable();

        $this->postJson("/api/orcamentos/{$orcamento->id}/decisao", [])
            ->assertUnprocessable();
    }

    public function test_orcamento_inexistente_e_404(): void
    {
        $this->postJson('/api/orcamentos/99999999-9999-9999-9999-999999999999/decisao', [
            'decisao' => 'aprovar',
        ])->assertNotFound();
    }

    /**
     * O PublicadorEventos e uma interface. Ate 12/11 o unico lugar que a
     * registrava era o comando `fila:consumir`, em tempo de execucao — entao
     * uma rota HTTP que publicasse evento estourava com "Target
     * [PublicadorEventos] is not instantiable" e devolvia 500, mesmo com todos
     * os testes verdes: os testes injetam o PublicadorFake antes.
     *
     * Esta assercao e o que separa "o teste passa" de "a aplicacao funciona".
     * getBindings() so enxerga o que um provider registrou; o instance() do
     * setUp nao entra ai.
     */
    public function test_publicador_de_eventos_tem_binding_no_container(): void
    {
        $this->assertArrayHasKey(
            PublicadorEventos::class,
            $this->app->getBindings(),
            'Sem binding de PublicadorEventos, qualquer rota HTTP que publique evento devolve 500.'
        );
    }

    /**
     * Gera o orcamento pelo caminho de verdade — o comando GerarOrcamento —
     * para que o saga_id venha do header, e nao de um create() de teste que
     * poderia esconder o fato de o comando nao estar gravando a coluna.
     */
    private function orcamentoDaSaga(): Orcamento
    {
        app(ProcessarGerarOrcamento::class)->executar([
            'header' => [
                'messageId' => '33333333-3333-3333-3333-333333333333',
                'sagaId' => self::SAGA,
                'causationId' => '55555555-5555-5555-5555-555555555555',
                'type' => 'GerarOrcamento',
                'occurredAt' => '2026-11-12T12:00:00Z',
                'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                'version' => 1,
            ],
            'body' => [
                'osId' => self::OS,
                'clienteId' => '22222222-2222-2222-2222-222222222222',
                'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
                'descricaoProblema' => 'Barulho no freio',
                'itensDiagnosticados' => [
                    ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
                ],
            ],
        ]);

        $orcamento = Orcamento::query()->where('os_id', self::OS)->firstOrFail();

        $this->assertSame(self::SAGA, $orcamento->saga_id, 'GerarOrcamento precisa gravar o saga_id do header.');

        return $orcamento;
    }

    /** @return array<int, array<string, mixed>> */
    private function eventosDeDecisao(): array
    {
        return array_values(array_filter(
            $this->publicador->eventos,
            fn (array $e) => in_array($e['routingKey'], ['orcamento.aprovado', 'orcamento.recusado'], true),
        ));
    }

    /** @return array<string, mixed> */
    private function eventoDeDecisao(): array
    {
        $eventos = $this->eventosDeDecisao();
        $this->assertCount(1, $eventos, 'Esperava exatamente um evento de decisao.');

        return $eventos[0];
    }
}
