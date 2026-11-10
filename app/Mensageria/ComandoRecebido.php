<?php

namespace App\Mensageria;

use App\Exceptions\MensagemIlegivel;
use Illuminate\Support\Str;

class ComandoRecebido
{
    private const MOTIVOS = [
        'orcamento_falhou',
        'orcamento_recusado',
        'aprovacao_expirada',
        'pagamento_recusado',
        'execucao_falhou',
    ];

    /**
     * @param  array<string, mixed>  $mensagem
     * @param  array<string, mixed>  $body
     */
    private function __construct(
        public readonly string $messageId,
        public readonly string $osId,
        public readonly string $motivo,
        public readonly array $mensagem,
        public readonly array $body,
    ) {}

    /**
     * @param  array<string, mixed>  $mensagem
     */
    public static function abrir(array $mensagem): self
    {
        $header = $mensagem['header'] ?? null;
        $messageId = is_array($header) ? ($header['messageId'] ?? null) : null;
        $body = $mensagem['body'] ?? null;

        if (! is_string($messageId) || ! Str::isUuid($messageId)) {
            throw new MensagemIlegivel('header.messageId ausente ou inválido.');
        }

        if (! is_array($body)) {
            throw new MensagemIlegivel('body ausente.');
        }

        $osId = $body['osId'] ?? null;
        if (! is_string($osId) || ! Str::isUuid($osId)) {
            throw new MensagemIlegivel('body.osId ausente ou inválido.');
        }

        $motivo = $body['motivo'] ?? null;
        if (! is_string($motivo) || ! in_array($motivo, self::MOTIVOS, true)) {
            throw new MensagemIlegivel('body.motivo ausente ou inválido.');
        }

        return new self($messageId, $osId, $motivo, $mensagem, $body);
    }

    public function uuidOpcional(string $campo): ?string
    {
        if (! array_key_exists($campo, $this->body) || $this->body[$campo] === null) {
            return null;
        }

        $valor = $this->body[$campo];
        if (! is_string($valor) || ! Str::isUuid($valor)) {
            throw new MensagemIlegivel('body.'.$campo.' ausente ou inválido.');
        }

        return $valor;
    }
}
