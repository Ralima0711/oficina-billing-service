<?php

namespace App\Services;

use App\Models\Orcamento;
use Illuminate\Support\Facades\DB;

class GerarOrcamento
{
    public function __construct(private readonly TabelaPrecos $precos) {}

    public function executar(array $comando): Orcamento
    {
        return DB::transaction(function () use ($comando) {
            $itens = [];
            $total = 0.0;

            foreach ($comando['itensDiagnosticados'] as $item) {
                $unitario = (float) $this->precos->valorUnitario($item['tipo']);
                $quantidade = (int) $item['quantidade'];
                $total += $unitario * $quantidade;
                $itens[] = [
                    'descricao' => $item['descricao'],
                    'tipo' => $item['tipo'],
                    'quantidade' => $quantidade,
                    'valor_unitario' => number_format($unitario, 2, '.', ''),
                ];
            }

            $orcamento = Orcamento::query()->create([
                'os_id' => $comando['osId'],
                'cliente_id' => $comando['clienteId'],
                'descricao_problema' => $comando['descricaoProblema'],
                'veiculo' => $comando['veiculo'],
                'valor_total' => number_format($total, 2, '.', ''),
                'moeda' => 'BRL',
                'valido_ate' => now()->utc()->addDays(7),
                'status' => 'gerado',
            ]);

            $orcamento->itens()->createMany($itens);

            return $orcamento->load('itens');
        });
    }
}
