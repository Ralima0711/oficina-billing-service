<?php

namespace App\Services;

use App\Mensageria\CaixaDeSaida;
use App\Mensageria\ComandoRecebido;
use App\Mensageria\ConsumidorDeComando;
use App\Models\Pagamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessarEstornarPagamento implements ConsumidorDeComando
{
    public function __construct(
        private readonly CaixaDeSaida $caixa,
        private readonly MercadoPagoClient $mercadoPago,
    ) {}

    public function executar(array $mensagem): void
    {
        $comando = ComandoRecebido::abrir($mensagem);

        if ($this->caixa->jaProcessada($comando->messageId)) {
            return;
        }

        try {
            DB::transaction(function () use ($comando) {
                $this->caixa->registrar($comando->messageId, 'EstornarPagamento');

                $pagamento = $this->pagamento($comando);
                if ($pagamento === null || $pagamento->os_id !== $comando->osId || ! in_array($pagamento->status, ['confirmado', 'estornado'], true)) {
                    Log::info('compensação de pagamento pulada', [
                        'osId' => $comando->osId,
                        'motivo' => $comando->motivo,
                    ]);

                    return;
                }

                $atual = Pagamento::query()->whereKey($pagamento->id)->lockForUpdate()->first();
                if ($atual === null || $atual->status !== 'confirmado') {
                    return;
                }

                if (is_string($atual->payment_id) && $atual->payment_id !== '') {
                    $this->mercadoPago->estornar($atual->payment_id, $comando->messageId);
                }

                $atual->status = 'estornado';
                $atual->motivo = $comando->motivo;
                $atual->save();
            });
        } catch (UniqueConstraintViolationException) {
            // A outra réplica gravou o messageId. O estorno já foi um só.
        }
    }

    private function pagamento(ComandoRecebido $comando): ?Pagamento
    {
        $pagamentoId = $comando->uuidOpcional('pagamentoId');

        if ($pagamentoId !== null) {
            return Pagamento::query()->find($pagamentoId);
        }

        $consulta = Pagamento::query()->where('os_id', $comando->osId);
        $orcamentoId = $comando->uuidOpcional('orcamentoId');
        if ($orcamentoId !== null) {
            $consulta->where('orcamento_id', $orcamentoId);
        }

        return $consulta
            ->whereIn('status', ['confirmado', 'estornado'])
            ->latest('created_at')
            ->first();
    }
}
