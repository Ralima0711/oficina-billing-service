<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pagamento extends Model
{
    use HasUuids;

    protected $table = 'pagamentos';

    protected $fillable = [
        'orcamento_id',
        'os_id',
        'preference_id',
        'payment_id',
        'status',
        'valor',
        'moeda',
        'metodo',
        'motivo',
        'sandbox_init_point',
        'confirmado_em',
        'tentativas',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'confirmado_em' => 'datetime',
            'tentativas' => 'integer',
        ];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    /**
     * Corpo de pagamento.confirmado ou pagamento.recusado.
     *
     * @return array<string, mixed>
     */
    public function paraEvento(): array
    {
        $corpo = [
            'osId' => $this->os_id,
            'pagamentoId' => $this->id,
            'orcamentoId' => $this->orcamento_id,
            'valorPago' => (float) $this->valor,
            'metodo' => $this->metodo,
            'confirmadoEm' => $this->confirmado_em?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->status === 'recusado') {
            $corpo['motivo'] = $this->motivo;
        }

        return $corpo;
    }
}
