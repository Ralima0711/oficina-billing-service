<?php

namespace App\Services;

use App\Exceptions\CobrancaException;
use App\Models\Orcamento;
use App\Models\Pagamento;
use Illuminate\Support\Carbon;

class CobrarPagamento
{
    public function __construct(private readonly MercadoPagoClient $mercadoPago) {}

    public function iniciar(Orcamento $orcamento): Pagamento
    {
        $existente = Pagamento::query()
            ->where('orcamento_id', $orcamento->id)
            ->whereIn('status', ['pendente', 'confirmado'])
            ->latest('created_at')
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $valor = (float) $orcamento->valor_total;

        if ($valor <= 0) {
            throw CobrancaException::semValor();
        }

        $preferencia = $this->mercadoPago->criarPreferencia($orcamento->id, $valor, $orcamento->moeda);

        return Pagamento::query()->create([
            'orcamento_id' => $orcamento->id,
            'os_id' => $orcamento->os_id,
            'preference_id' => $preferencia['id'],
            'status' => 'pendente',
            'valor' => number_format($valor, 2, '.', ''),
            'moeda' => $orcamento->moeda,
            'sandbox_init_point' => $preferencia['sandbox_init_point'],
        ]);
    }

    public function consultar(Pagamento $pagamento): Pagamento
    {
        if ($pagamento->status === 'confirmado') {
            return $pagamento;
        }

        $remoto = $this->mercadoPago->buscarPorReferencia($pagamento->orcamento_id);

        if ($remoto === null) {
            return $pagamento;
        }

        $status = $this->statusLocal((string) ($remoto['status'] ?? ''));
        $pagamento->payment_id = isset($remoto['id']) ? (string) $remoto['id'] : $pagamento->payment_id;
        $pagamento->status = $status;

        if ($status === 'confirmado') {
            $pagamento->valor = number_format((float) ($remoto['transaction_amount'] ?? $pagamento->valor), 2, '.', '');
            $pagamento->metodo = $this->metodo($remoto['payment_type_id'] ?? null, $remoto['payment_method_id'] ?? null);
            $pagamento->motivo = null;
            $pagamento->confirmado_em = $this->confirmadoEm($remoto['date_approved'] ?? null);
        }

        if ($status === 'recusado') {
            $pagamento->metodo = $this->metodo($remoto['payment_type_id'] ?? null, $remoto['payment_method_id'] ?? null);
            $pagamento->motivo = (string) ($remoto['status_detail'] ?? 'recusado');
            $pagamento->confirmado_em = $this->confirmadoEm($remoto['date_created'] ?? null);
        }

        $pagamento->save();

        return $pagamento;
    }

    private function statusLocal(string $status): string
    {
        return match ($status) {
            'approved' => 'confirmado',
            'rejected', 'cancelled' => 'recusado',
            default => 'pendente',
        };
    }

    private function metodo(?string $tipo, ?string $metodo): ?string
    {
        if ($metodo === 'pix') {
            return 'pix';
        }

        return match ($tipo) {
            'credit_card' => 'credito',
            'debit_card' => 'debito',
            'ticket' => 'boleto',
            'bank_transfer' => 'pix',
            default => null,
        };
    }

    private function confirmadoEm(mixed $instante): Carbon
    {
        if (is_string($instante) && $instante !== '') {
            return Carbon::parse($instante)->utc();
        }

        return now()->utc();
    }
}
