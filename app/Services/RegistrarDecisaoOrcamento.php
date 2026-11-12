<?php

namespace App\Services;

use App\Exceptions\DecisaoNaoAplicavel;
use App\Mensageria\CaixaDeSaida;
use App\Models\Orcamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A decisao do cliente sobre um orcamento.
 *
 * Por que isto esta no Billing e nao no OS Service: o contrato de eventos
 * v1.1 declara orcamento.aprovado e orcamento.recusado como evento
 * "<- Billing" — decisao do cliente sobre um orcamento, e o orcamento e deste
 * servico. Uma rota no OS Service publicaria um evento que o proprio OS
 * declara como de entrada, e o desacoplamento viraria so uma afirmacao no
 * documento.
 *
 * O caminho e o mesmo dos comandos consumidos da fila: a mudanca de status e a
 * intencao de publicar vao na MESMA transacao (outbox), e a publicacao sai
 * depois do commit.
 *
 * O que e diferente de um comando de fila, e o que exigiu as duas coisas
 * abaixo: aqui a messageId nasce no HTTP. Ninguem reentrega.
 *
 *   1. TODAS as guardas rodam dentro da transacao, sobre a linha com
 *      lockForUpdate. Fora dela, duas chamadas simultaneas leem "gerado" as
 *      duas, gravam dois eventos com messageId diferente e a saga trata os
 *      dois — a idempotencia nao cola porque nao e a mesma mensagem.
 *
 *   2. O orcamento guarda a decisao que o decidiu (decisao_id). Se o processo
 *      morrer entre o commit e a publicacao, a saida fica com publicado_em
 *      nulo e sem ninguem que saiba o id dela. Com o decisao_id gravado, a
 *      chamada seguinte — a que recebe 409 de "ja decidido" — republica o que
 *      ficou pendente em vez de so recusar. Sem isso, o retry do cliente
 *      depois do 500 CONFIRMA a perda, e a saga fica em AGUARDANDO_APROVACAO
 *      ate o prazo de sete dias expirar.
 */
class RegistrarDecisaoOrcamento
{
    public function __construct(private readonly CaixaDeSaida $caixa) {}

    /**
     * @param  bool  $aprovado  true publica orcamento.aprovado, false orcamento.recusado
     * @param  string|null  $motivo  so vai no corpo da recusa, como manda o contrato
     * @return string id da decisao, devolvido na resposta HTTP para rastreio
     *
     * @throws DecisaoNaoAplicavel
     */
    public function executar(string $orcamentoId, bool $aprovado, ?string $motivo = null): string
    {
        $decisaoId = (string) Str::uuid();
        $routingKey = $aprovado ? 'orcamento.aprovado' : 'orcamento.recusado';

        $orcamento = DB::transaction(function () use ($orcamentoId, $aprovado, $motivo, $decisaoId, $routingKey) {
            $orcamento = Orcamento::query()->whereKey($orcamentoId)->lockForUpdate()->first();

            if ($orcamento === null) {
                throw DecisaoNaoAplicavel::naoEncontrado();
            }

            $this->exigirDecidivel($orcamento);

            $this->caixa->registrar($decisaoId, $routingKey);

            $orcamento->status = $aprovado ? 'aprovado' : 'recusado';
            $orcamento->decisao_id = $decisaoId;
            $orcamento->save();

            $corpo = [
                'osId' => $orcamento->os_id,
                'orcamentoId' => $orcamento->id,
                'decidioEm' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ];

            if (! $aprovado && is_string($motivo) && trim($motivo) !== '') {
                $corpo['motivo'] = $motivo;
            }

            // O sagaId sai do orcamento: e a saga que pediu este orcamento.
            // Sem ele o Orchestrator do OS descarta o evento em sagaAtiva().
            $this->caixa->gravar(
                [
                    'header' => [
                        'messageId' => $decisaoId,
                        'sagaId' => $orcamento->saga_id,
                    ],
                ],
                $routingKey,
                $corpo,
            );

            return $orcamento;
        });

        Log::info('Decisao do cliente sobre o orcamento', [
            'decisaoId' => $decisaoId,
            'orcamentoId' => $orcamento->id,
            'osId' => $orcamento->os_id,
            'sagaId' => $orcamento->saga_id,
            'tipo' => $routingKey,
        ]);

        // Publish-then-mark, fora da transacao: duplicata a saga resolve por
        // idempotencia, perda ela nao resolve. Se o processo morrer aqui, quem
        // corrige e o republicar() na proxima chamada.
        $this->caixa->publicarPendentes($decisaoId);

        return $decisaoId;
    }

    /**
     * Publica o que ficou pendente de uma decisao ja tomada.
     *
     * Chamado no caminho do 409: se nada ficou pendente, nao faz nada; se
     * ficou, a perda e desfeita na primeira repeticao do cliente.
     */
    public function republicarPendentes(?string $decisaoId): void
    {
        if ($decisaoId === null) {
            return;
        }

        if (! $this->caixa->temSaidaPendente($decisaoId)) {
            return;
        }

        Log::warning('Decisao comitada sem publicar: republicando a saida pendente', [
            'decisaoId' => $decisaoId,
        ]);

        $this->caixa->publicarPendentes($decisaoId);
    }

    /** @throws DecisaoNaoAplicavel */
    private function exigirDecidivel(Orcamento $orcamento): void
    {
        // Orcamento sem saga veio do POST /api/orcamentos, que e porta avulsa.
        // Publicar a decisao dele geraria um evento que o Orchestrator do OS
        // descartaria em sagaAtiva() — melhor recusar aqui e dizer por que.
        if (! is_string($orcamento->saga_id) || $orcamento->saga_id === '') {
            throw DecisaoNaoAplicavel::semSaga($orcamento->status);
        }

        if ($orcamento->status !== 'gerado') {
            throw DecisaoNaoAplicavel::jaDecidido($orcamento->status, $orcamento->decisao_id);
        }

        if ($orcamento->valido_ate->isPast()) {
            throw DecisaoNaoAplicavel::prazoVencido(
                $orcamento->status,
                $orcamento->valido_ate->utc()->format('Y-m-d\TH:i:s\Z'),
            );
        }
    }
}
