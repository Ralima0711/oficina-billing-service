<?php

namespace App\Services;

use App\Exceptions\CobrancaException;
use App\Models\Orcamento;
use App\Models\Pagamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class CobrarPagamento
{
    public function __construct(private readonly MercadoPagoClient $mercadoPago) {}

    public function iniciar(Orcamento $orcamento): Pagamento
    {
        $existente = $this->pagamentoAtivo($orcamento);

        if ($existente !== null) {
            return $this->garantirPreferencia($existente, $orcamento);
        }

        $valor = (float) $orcamento->valor_total;

        if ($valor <= 0) {
            throw CobrancaException::semValor();
        }

        try {
            $pagamento = Pagamento::query()->create([
                'orcamento_id' => $orcamento->id,
                'os_id' => $orcamento->os_id,
                'preference_id' => null,
                'status' => 'pendente',
                'valor' => number_format($valor, 2, '.', ''),
                'moeda' => $orcamento->moeda,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $pagamento = $this->pagamentoAtivo($orcamento);

            if ($pagamento === null) {
                throw $e;
            }
        }

        return $this->garantirPreferencia($pagamento, $orcamento);
    }

    public function consultar(Pagamento $pagamento): Pagamento
    {
        if (in_array($pagamento->status, ['confirmado', 'recusado'], true)) {
            return $pagamento;
        }

        $pagamento->tentativas = (int) $pagamento->tentativas + 1;
        $remoto = $this->mercadoPago->buscarPorReferencia($pagamento->orcamento_id);
        $status = $remoto === null ? 'pendente' : $this->statusLocal((string) ($remoto['status'] ?? ''));

        if (is_array($remoto) && isset($remoto['id'])) {
            $pagamento->payment_id = (string) $remoto['id'];
        }

        if ($status === 'confirmado' && is_array($remoto)) {
            $pagamento->status = 'confirmado';
            $pagamento->valor = number_format((float) ($remoto['transaction_amount'] ?? $pagamento->valor), 2, '.', '');
            $pagamento->metodo = $this->metodo($remoto['payment_type_id'] ?? null, $remoto['payment_method_id'] ?? null);
            $pagamento->motivo = null;
            $pagamento->confirmado_em = $this->confirmadoEm($remoto['date_approved'] ?? null);
            $pagamento->save();

            return $pagamento;
        }

        if ($status === 'recusado' && is_array($remoto)) {
            $pagamento->status = 'recusado';
            $pagamento->metodo = $this->metodo($remoto['payment_type_id'] ?? null, $remoto['payment_method_id'] ?? null);
            $pagamento->motivo = (string) ($remoto['status_detail'] ?? 'recusado');
            $pagamento->confirmado_em = $this->confirmadoEm($remoto['date_created'] ?? null);
            $pagamento->save();

            return $pagamento;
        }

        if ($pagamento->tentativas >= $this->maxTentativas()) {
            $pagamento->status = 'recusado';
            $pagamento->motivo = 'timeout';
            $pagamento->confirmado_em = now()->utc();
        }

        $pagamento->save();

        return $pagamento;
    }

    public function intervaloSegundos(): int
    {
        return max(0, (int) config('services.mercadopago.poll_interval_seconds', 30));
    }

    private function pagamentoAtivo(Orcamento $orcamento): ?Pagamento
    {
        return Pagamento::query()
            ->where('orcamento_id', $orcamento->id)
            ->whereIn('status', ['pendente', 'confirmado'])
            ->latest('created_at')
            ->first();
    }

    private function garantirPreferencia(Pagamento $pagamento, Orcamento $orcamento): Pagamento
    {
        if ($pagamento->preference_id !== null) {
            return $pagamento;
        }

        $preferencia = $this->mercadoPago->criarPreferencia(
            $orcamento->id,
            (float) $pagamento->valor,
            $pagamento->moeda,
        );

        $pagamento->preference_id = $preferencia['id'];
        $pagamento->sandbox_init_point = $preferencia['sandbox_init_point'];
        $pagamento->save();

        return $pagamento;
    }

    private function maxTentativas(): int
    {
        return max(1, (int) config('services.mercadopago.poll_max_attempts', 20));
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
