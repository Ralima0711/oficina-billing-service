<?php

namespace App\Exceptions;

use RuntimeException;

class ItemSemPreco extends RuntimeException
{
    public static function para(string $descricao, string $tipo): self
    {
        return new self("Item \"{$descricao}\" do tipo {$tipo} não está no catálogo de preços do billing.");
    }
}
