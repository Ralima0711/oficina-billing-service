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
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'confirmado_em' => 'datetime',
        ];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }
}
