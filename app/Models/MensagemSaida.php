<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MensagemSaida extends Model
{
    use HasUuids;

    protected $table = 'mensagens_saida';

    protected $fillable = [
        'mensagem_entrada_id',
        'routing_key',
        'envelope',
        'publicado_em',
    ];

    protected function casts(): array
    {
        return [
            'envelope' => 'array',
            'publicado_em' => 'datetime',
        ];
    }
}
