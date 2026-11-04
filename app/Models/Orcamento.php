<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orcamento extends Model
{
    use HasUuids;

    protected $table = 'orcamentos';

    protected $fillable = [
        'os_id',
        'cliente_id',
        'descricao_problema',
        'veiculo',
        'valor_total',
        'moeda',
        'valido_ate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'veiculo' => 'array',
            'valor_total' => 'decimal:2',
            'valido_ate' => 'datetime',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class);
    }

    /**
     * Corpo do evento orcamento.gerado.
     *
     * @return array<string, mixed>
     */
    public function paraEvento(): array
    {
        return [
            'osId' => $this->os_id,
            'orcamentoId' => $this->id,
            'valorTotal' => (float) $this->valor_total,
            'moeda' => $this->moeda,
            'validoAte' => $this->valido_ate->utc()->format('Y-m-d\TH:i:s\Z'),
            'itens' => $this->itens->map(fn ($item) => [
                'descricao' => $item->descricao,
                'tipo' => $item->tipo,
                'quantidade' => $item->quantidade,
                'valorUnitario' => (float) $item->valor_unitario,
            ])->all(),
        ];
    }
}
