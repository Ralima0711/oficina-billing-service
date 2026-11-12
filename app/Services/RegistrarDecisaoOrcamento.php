<?php

namespace App\Services;

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
 * O caminho e o mesmo dos comandos consumidos da fila, de proposito: a mudanca
 * de status e a intencao de publicar vao na MESMA transacao (outbox), e a
 * publicacao sai depois do commit. Se o processo morrer entre as duas coisas,
 * o evento fica pendente na mensagens_saida e e republicado; o que nao pode
 * acontecer e o status mudar e o evento se perder, porque ai a saga do OS fica
 * parada em AGUARDANDO_APROVACAO para sempre.
 *
 * A mensagens_saida tem FK para mensagens_processadas, entao a decisao recebe
 * um id proprio e e registrada la como se fosse uma mensagem de entrada. Nao e
 * contorno de constraint: a decisao E a origem deste evento, e ter id proprio
 * e o que permite republicar so o que ficou pendente.
 */
class RegistrarDecisaoOrcamento
{
    public function __construct(private readonly CaixaDeSaida $caixa) {}

    /**
     * @param  bool  $aprovado  true publica orcamento.aprovado, false orcamento.recusado
     * @param  string|null  $motivo  so vai no corpo da recusa, como manda o contrato
     * @return string id da decisao, devolvido na resposta HTTP para rastreio
     */
    public function executar(Orcamento $orcamento, bool $aprovado, ?string $motivo = null): string
    {
        $decisaoId = (string) Str::uuid();
        $routingKey = $aprovado ? 'orcamento.aprovado' : 'orcamento.recusado';

        DB::transaction(function () use ($orcamento, $aprovado, $motivo, $decisaoId, $routingKey) {
            $this->caixa->registrar($decisaoId, $routingKey);

            $orcamento->status = $aprovado ? 'aprovado' : 'recusado';
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
        });

        Log::info('Decisao do cliente sobre o orcamento', [
            'decisaoId' => $decisaoId,
            'orcamentoId' => $orcamento->id,
            'osId' => $orcamento->os_id,
            'sagaId' => $orcamento->saga_id,
            'tipo' => $routingKey,
        ]);

        // Publish-then-mark, fora da transacao: duplicata a saga resolve por
        // idempotencia, perda ela nao resolve.
        $this->caixa->publicarPendentes($decisaoId);

        return $decisaoId;
    }
}
