<?php

namespace App\Services;

use App\Exceptions\ItemSemPreco;
use App\Models\CatalogoPreco;

class TabelaPrecos
{
    /**
     * O comando GerarOrcamento não traz valor. O preço sai do catálogo local,
     * com os mesmos nomes e valores usados na API da Fase 3.
     */
    public function valorUnitario(string $tipo, string $descricao): string
    {
        $alvo = mb_strtolower(trim($descricao));

        $item = CatalogoPreco::query()
            ->where('tipo', $tipo)
            ->get()
            ->first(fn (CatalogoPreco $row) => mb_strtolower($row->descricao) === $alvo);

        if ($item === null) {
            throw ItemSemPreco::para($descricao, $tipo);
        }

        return $item->preco_unitario;
    }
}
