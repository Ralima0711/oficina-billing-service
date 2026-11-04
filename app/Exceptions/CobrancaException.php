<?php

namespace App\Exceptions;

use RuntimeException;

class CobrancaException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public static function semValor(): self
    {
        return new self('Orçamento sem valor não gera cobrança no Mercado Pago.', 422);
    }

    public static function semToken(): self
    {
        return new self('MP_ACCESS_TOKEN não está configurado.', 503);
    }

    public static function mercadoPago(int $status): self
    {
        return new self('Mercado Pago respondeu HTTP '.$status.' ao criar ou consultar o pagamento.', 502);
    }
}
