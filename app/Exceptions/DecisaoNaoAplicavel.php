<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A decisao do cliente nao pode ser aplicada a este orcamento.
 *
 * Carrega o motivo em vez de so uma mensagem porque o controller precisa
 * decidir status HTTP e, no caso JA_DECIDIDO, o que fazer com a saida que
 * possa ter ficado pendente.
 */
final class DecisaoNaoAplicavel extends RuntimeException
{
    public const NAO_ENCONTRADO = 'nao_encontrado';

    public const SEM_SAGA = 'sem_saga';

    public const JA_DECIDIDO = 'ja_decidido';

    public const PRAZO_VENCIDO = 'prazo_vencido';

    private function __construct(
        public readonly string $motivo,
        string $mensagem,
        public readonly ?string $status = null,
        public readonly ?string $decisaoId = null,
        public readonly ?string $validoAte = null,
    ) {
        parent::__construct($mensagem);
    }

    public static function naoEncontrado(): self
    {
        return new self(self::NAO_ENCONTRADO, 'Orcamento nao encontrado.');
    }

    public static function semSaga(string $status): self
    {
        return new self(
            self::SEM_SAGA,
            'Orcamento sem saga: nao foi gerado pelo comando GerarOrcamento e nao ha saga para decidir.',
            status: $status,
        );
    }

    /** @param string|null $decisaoId decisao que ja decidiu este orcamento, quando houve uma */
    public static function jaDecidido(string $status, ?string $decisaoId): self
    {
        return new self(
            self::JA_DECIDIDO,
            'Orcamento ja decidido ou anulado.',
            status: $status,
            decisaoId: $decisaoId,
        );
    }

    public static function prazoVencido(string $status, string $validoAte): self
    {
        return new self(
            self::PRAZO_VENCIDO,
            'Prazo de aprovacao vencido.',
            status: $status,
            validoAte: $validoAte,
        );
    }
}
