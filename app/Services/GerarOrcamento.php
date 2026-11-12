<?php

namespace App\Services;

use App\Models\Orcamento;
use Illuminate\Support\Facades\DB;

class GerarOrcamento
{
    public function __construct(private readonly TabelaPrecos $precos) {}

    /**
     * @param  array<string, mixed>  $comando  body do comando GerarOrcamento
     * @param  string|null  $sagaId  header.sagaId do comando; null quando o
     *                               orcamento vem do POST /api/orcamentos, que
     *                               nao pertence a saga nenhuma
     * @param  string|null  $traceparent  traceparent do comando, pai da decisão
     *                                    que o cliente toma depois
     */
    public function executar(array $comando, ?string $sagaId = null, ?string $traceparent = null): Orcamento
    {
        return DB::transaction(function () use ($comando, $sagaId, $traceparent) {
            $itens = [];
            $total = 0.0;

            foreach ($comando['itensDiagnosticados'] ?? [] as $item) {
                $unitario = (float) $this->precos->valorUnitario($item['tipo'], $item['descricao']);
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
                'saga_id' => $sagaId,
                'traceparent' => is_string($traceparent) && $traceparent !== '' ? $traceparent : null,
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
