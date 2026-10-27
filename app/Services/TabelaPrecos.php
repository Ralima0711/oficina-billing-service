<?php

namespace App\Services;

class TabelaPrecos
{
    /**
     * Preço unitário inicial, até existir catálogo de peças e serviços.
     * O comando GerarOrcamento não traz valor — o billing calcula.
     */
    public function valorUnitario(string $tipo): string
    {
        return match ($tipo) {
            'peca' => '95.00',
            'servico' => '180.00',
            default => throw new \InvalidArgumentException('Tipo de item desconhecido: '.$tipo),
        };
    }
}
