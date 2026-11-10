<?php

namespace App\Mensageria;

use App\Exceptions\MensagemIlegivel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ComandoRecebido
{
    /** Motivos que o contrato v1.1 define (MotivoCompensacao). Um valor fora daqui e logado, nao recusado. */
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
        if (! is_string($motivo) || trim($motivo) === '') {
            throw new MensagemIlegivel('body.motivo ausente ou inválido.');
        }

        // O motivo e campo ACESSORIO: ele so vai para log e para a coluna, e nao
        // muda o que a compensacao faz. Pelo ADR-0014, campo acessorio fora do
        // dominio se ajusta e se registra — nao derruba o comando.
        //
        // Recusar um motivo desconhecido mandaria a compensacao para a DLQ, e
        // compensacao que nao acontece e dinheiro que nao volta. Bastaria o
        // contrato ganhar um motivo novo para o billing parar de estornar em
        // silencio enquanto a Execucao continuaria compensando — ela ja aceita
        // qualquer motivo, pelo mesmo raciocinio.
        if (! in_array($motivo, self::MOTIVOS, true)) {
            Log::warning('motivo de compensacao fora do contrato', [
                'motivo' => $motivo,
                'osId' => $osId,
                'messageId' => $messageId,
                'conhecidos' => self::MOTIVOS,
            ]);
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
