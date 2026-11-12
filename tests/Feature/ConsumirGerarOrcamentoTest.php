<?php

namespace Tests\Feature;

use App\Exceptions\MensagemIlegivel;
use App\Mensageria\PublicadorEventos;
use App\Models\MensagemSaida;
use App\Models\Orcamento;
use App\Services\ProcessarGerarOrcamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicadorFake;
use Tests\Support\Traceparent;
use Tests\TestCase;

class ConsumirGerarOrcamentoTest extends TestCase
{
    use RefreshDatabase;

    private PublicadorFake $publicador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicador = new PublicadorFake;
        $this->app->instance(PublicadorEventos::class, $this->publicador);
    }

    public function test_publica_orcamento_gerado_e_ignora_repeticao(): void
    {
        $mensagem = $this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
            'descricaoProblema' => 'Barulho no freio',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 2],
                ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
            ],
        ]);

        $this->processar()->executar($mensagem);
        $this->processar()->executar($mensagem);

        $this->assertSame(1, Orcamento::query()->count());
        $this->assertCount(1, $this->publicador->eventos);

        $evento = $this->publicador->eventos[0];
        $this->assertSame('orcamento.gerado', $evento['routingKey']);
        $this->assertSame('orcamento.gerado', $evento['envelope']['header']['type']);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $evento['envelope']['header']['causationId']);
        $this->assertSame('44444444-4444-4444-4444-444444444444', $evento['envelope']['header']['sagaId']);
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', Traceparent::traceId($evento['traceparent']));
        $this->assertNotSame('bbbbbbbbbbbbbbbb', Traceparent::spanId($evento['traceparent']));
        $this->assertSame($evento['traceparent'], $evento['envelope']['header']['traceparent']);
        $this->assertSame(241.8, $evento['envelope']['body']['valorTotal']);
        $this->assertNotNull(MensagemSaida::query()->whereNotNull('publicado_em')->first());
    }

    public function test_sem_itens_publica_falha_e_nao_grava_orcamento(): void
    {
        $this->processar()->executar($this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Nada a reparar',
            'itensDiagnosticados' => [],
        ]));

        $this->processar()->executar($this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Nada a reparar',
            'itensDiagnosticados' => [],
        ]));

        $this->assertSame(0, Orcamento::query()->count());
        $this->assertCount(1, $this->publicador->eventos);
        $this->assertSame('orcamento.falhou', $this->publicador->eventos[0]['routingKey']);
        $this->assertSame('sem_itens_a_orcar', $this->publicador->eventos[0]['envelope']['body']['motivo']);
        $this->assertArrayNotHasKey('orcamentoId', $this->publicador->eventos[0]['envelope']['body']);
    }

    public function test_item_fora_do_catalogo_publica_falha(): void
    {
        $this->processar()->executar($this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Pastilha', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ], '66666666-6666-6666-6666-666666666666'));

        $this->assertSame(0, Orcamento::query()->count());
        $this->assertSame('item_fora_do_catalogo', $this->publicador->eventos[0]['envelope']['body']['motivo']);
    }

    public function test_campo_essencial_invalido_publica_erro_interno(): void
    {
        $this->processar()->executar($this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['modelo' => 'Gol'],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ], '77777777-7777-7777-7777-777777777777'));

        $this->assertSame(0, Orcamento::query()->count());
        $this->assertSame('erro_interno', $this->publicador->eventos[0]['envelope']['body']['motivo']);
    }

    public function test_ano_invalido_e_ignorado_e_o_orcamento_sai(): void
    {
        $this->processar()->executar($this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23', 'ano' => 'dois mil'],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ], '88888888-8888-8888-8888-888888888888'));

        $orcamento = Orcamento::query()->first();
        $this->assertNotNull($orcamento);
        $this->assertSame('DEM1A23', $orcamento->veiculo['placa']);
        $this->assertArrayNotHasKey('ano', $orcamento->veiculo);
        $this->assertSame('orcamento.gerado', $this->publicador->eventos[0]['routingKey']);
    }

    public function test_falha_de_publicacao_republica_sem_gerar_outro_orcamento(): void
    {
        $this->publicador->falhas = 1;
        $mensagem = $this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ], '99999999-9999-9999-9999-999999999999');

        try {
            $this->processar()->executar($mensagem);
            $this->fail('a publicação deveria falhar');
        } catch (\RuntimeException) {
            $this->assertSame(1, Orcamento::query()->count());
            $this->assertNull(MensagemSaida::query()->first()->publicado_em);
        }

        $this->processar()->executar($mensagem);

        $this->assertSame(1, Orcamento::query()->count());
        $this->assertCount(1, $this->publicador->eventos);
        $this->assertNotNull(MensagemSaida::query()->first()->publicado_em);
    }

    public function test_mensagem_sem_message_id_nao_gera_efeito(): void
    {
        $mensagem = $this->mensagem([
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23'],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ]);
        unset($mensagem['header']['messageId']);

        try {
            $this->processar()->executar($mensagem);
            $this->fail('a mensagem deveria ser ilegível');
        } catch (MensagemIlegivel) {
            $this->assertSame(0, Orcamento::query()->count());
            $this->assertSame([], $this->publicador->eventos);
        }
    }

    public function test_comando_fila_consumir_esta_registrado(): void
    {
        $this->artisan('help', ['command_name' => 'fila:consumir'])->assertOk();
    }

    private function processar(): ProcessarGerarOrcamento
    {
        return $this->app->make(ProcessarGerarOrcamento::class);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mensagem(array $body, string $messageId = '33333333-3333-3333-3333-333333333333'): array
    {
        return [
            'header' => [
                'messageId' => $messageId,
                'sagaId' => '44444444-4444-4444-4444-444444444444',
                'causationId' => '55555555-5555-5555-5555-555555555555',
                'type' => 'GerarOrcamento',
                'occurredAt' => '2026-11-04T12:00:00Z',
                'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                'version' => 1,
            ],
            'body' => $body,
        ];
    }
}
