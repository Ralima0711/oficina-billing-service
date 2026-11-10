<?php

namespace App\Services;

use App\Mensageria\CaixaDeSaida;
use App\Mensageria\ComandoRecebido;
use App\Mensageria\ConsumidorDeComando;
use App\Models\Orcamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessarAnularOrcamento implements ConsumidorDeComando
{
    public function __construct(private readonly CaixaDeSaida $caixa) {}

    public function executar(array $mensagem): void
    {
        $comando = ComandoRecebido::abrir($mensagem);

        if ($this->caixa->jaProcessada($comando->messageId)) {
            return;
        }

        try {
            DB::transaction(function () use ($comando) {
                $this->caixa->registrar($comando->messageId, 'AnularOrcamento');

                $orcamento = $this->orcamento($comando);
                if ($orcamento === null || $orcamento->os_id !== $comando->osId || $orcamento->status === 'anulado') {
                    Log::info('compensação de orçamento pulada', [
                        'osId' => $comando->osId,
                        'motivo' => $comando->motivo,
                    ]);

                    return;
                }

                $orcamento->status = 'anulado';
                $orcamento->save();
            });
        } catch (UniqueConstraintViolationException) {
            // A outra réplica gravou o messageId. O efeito já foi um só.
        }
    }

    private function orcamento(ComandoRecebido $comando): ?Orcamento
    {
        $orcamentoId = $comando->uuidOpcional('orcamentoId');

        if ($orcamentoId !== null) {
            return Orcamento::query()->find($orcamentoId);
        }

        return Orcamento::query()->where('os_id', $comando->osId)->latest('created_at')->first();
    }
}
