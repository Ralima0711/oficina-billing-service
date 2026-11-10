<?php

namespace App\Mensageria;

interface ConsumidorDeComando
{
    /**
     * @param  array<string, mixed>  $mensagem
     */
    public function executar(array $mensagem): void;
}
