<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrcamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_gera_orcamento_com_o_payload_do_contrato(): void
    {
        $resposta = $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
            'descricaoProblema' => 'Barulho no freio',
            'itensDiagnosticados' => [
                ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 2],
                ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
            ],
        ]);

        $resposta->assertCreated()
            ->assertJsonPath('osId', '11111111-1111-1111-1111-111111111111')
            ->assertJsonPath('moeda', 'BRL')
            ->assertJsonPath('valorTotal', 241.8)
            ->assertJsonPath('itens.0.valorUnitario', 45.9)
            ->assertJsonPath('itens.1.valorUnitario', 150)
            ->assertJsonCount(2, 'itens');

        $id = $resposta->json('orcamentoId');
        $this->getJson('/api/orcamentos/'.$id)->assertOk()->assertJsonPath('valorTotal', 241.8);
    }

    public function test_rejeita_item_que_nao_esta_no_catalogo(): void
    {
        $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Pastilha', 'tipo' => 'peca', 'quantidade' => 1],
            ],
        ])->assertUnprocessable();
    }

    public function test_rejeita_item_com_tipo_invalido(): void
    {
        $this->postJson('/api/orcamentos', [
            'osId' => '11111111-1111-1111-1111-111111111111',
            'clienteId' => '22222222-2222-2222-2222-222222222222',
            'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
            'descricaoProblema' => 'Barulho',
            'itensDiagnosticados' => [
                ['descricao' => 'Algo', 'tipo' => 'outro', 'quantidade' => 1],
            ],
        ])->assertUnprocessable();
    }
}
