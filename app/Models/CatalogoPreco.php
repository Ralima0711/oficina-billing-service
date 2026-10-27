<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoPreco extends Model
{
    protected $table = 'catalogo_precos';

    protected $fillable = [
        'descricao',
        'tipo',
        'codigo',
        'preco_unitario',
    ];

    protected function casts(): array
    {
        return [
            'preco_unitario' => 'decimal:2',
        ];
    }
}
