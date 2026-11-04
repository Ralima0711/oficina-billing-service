<?php

namespace App\Services;

use App\Exceptions\MensagemIlegivel;
use App\Mensageria\CaixaDeSaida;
use App\Models\Orcamento;
use App\Models\Pagamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessarCobrarPagamento
{
    public function __construct(
        private readonly CobrarPagamento $cobrar,
        private readonly CaixaDeSaida $caixa,
    ) {}

    /**
     * @param  array<string, mixed>  $mensagem
     */
    public function executar(array $mensagem): void
    {
        $messageId = $this->messageId($mensagem);
        $body = $this->body($mensagem);
        $osId = $this->uuid($body['osId'] ?? null, 'body.osId');
        $orcamentoId = $this->uuid($body['orcamentoId'] ?? null, 'body.orcamentoId');

        if ($this->caixa->jaProcessada($messageId)) {
            $this->retomar($mensagem, $messageId, $orcamentoId);

            return;
        }

        try {
            DB::transaction(function () use ($mensagem, $messageId, $body, $osId, $orcamentoId) {
                $this->caixa->registrar($messageId, 'CobrarPagamento');

                $recusa = $this->recusaImediata($body, $osId, $orcamentoId);
                if ($recusa !== null) {
                    $this->caixa->gravar($mensagem, 'pagamento.recusado', $recusa);

                    return;
                }

                $this->cobrar->iniciar(Orcamento::query()->findOrFail($orcamentoId));
            });
        } catch (UniqueConstraintViolationException) {
            $this->retomar($mensagem, $messageId, $orcamentoId);

            return;
        }

        if ($this->caixa->temSaida($messageId)) {
            $this->caixa->publicarPendentes($messageId);

            return;
        }

        $this->acompanhar($mensagem, $messageId, $this->pagamentoDoOrcamento($orcamentoId));
    }

    /**
     * @param  array<string, mixed>  $mensagem
     */
    private function retomar(array $mensagem, string $messageId, string $orcamentoId): void
    {
        if ($this->caixa->temSaida($messageId)) {
            $this->caixa->publicarPendentes($messageId);

            return;
        }

        $pagamento = $this->pagamentoDoOrcamento($orcamentoId);
        if ($pagamento === null) {
            return;
        }

        $this->acompanhar($mensagem, $messageId, $pagamento);
    }

    /**
     * @param  array<string, mixed>  $mensagem
     */
    private function acompanhar(array $mensagem, string $messageId, Pagamento $pagamento): void
    {
        while ($pagamento->status === 'pendente') {
            if ((int) $pagamento->tentativas > 0) {
                $espera = $this->cobrar->intervaloSegundos();
                if ($espera > 0) {
                    sleep($espera);
                }
            }

            $pagamento = $this->cobrar->consultar($pagamento->fresh());
        }

        $routingKey = $pagamento->status === 'confirmado' ? 'pagamento.confirmado' : 'pagamento.recusado';

        try {
            DB::transaction(function () use ($mensagem, $messageId, $pagamento, $routingKey) {
                if ($this->caixa->temSaida($messageId)) {
                    return;
                }

                $this->caixa->gravar($mensagem, $routingKey, $pagamento->paraEvento());
            });
        } catch (UniqueConstraintViolationException) {
            // A outra réplica gravou o evento. A publicação abaixo reaproveita a linha.
        }

        $this->caixa->publicarPendentes($messageId);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function recusaImediata(array $body, string $osId, string $orcamentoId): ?array
    {
        $orcamento = Orcamento::query()->find($orcamentoId);

        if ($orcamento === null) {
            return $this->corpoSemPagamento($osId, $orcamentoId, $body, 'orcamento_inexistente');
        }

        $motivo = $this->motivoDeRecusa($body, $osId, $orcamento);
        if ($motivo === null) {
            return null;
        }

        $pagamento = Pagamento::query()->create([
            'orcamento_id' => $orcamento->id,
            'os_id' => $orcamento->os_id,
            'status' => 'recusado',
            'valor' => number_format((float) $orcamento->valor_total, 2, '.', ''),
            'moeda' => $orcamento->moeda,
            'motivo' => $motivo,
            'confirmado_em' => now()->utc(),
        ]);

        $evento = $pagamento->paraEvento();
        $evento['osId'] = $osId;

        return $evento;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function motivoDeRecusa(array $body, string $osId, Orcamento $orcamento): ?string
    {
        if ($osId !== $orcamento->os_id) {
            return 'os_divergente';
        }

        if (! is_int($body['valorTotal'] ?? null) && ! is_float($body['valorTotal'] ?? null)) {
            return 'valor_invalido';
        }

        if ((float) $body['valorTotal'] <= 0 || (float) $orcamento->valor_total <= 0) {
            return 'sem_valor';
        }

        if (abs((float) $body['valorTotal'] - (float) $orcamento->valor_total) > 0.001) {
            return 'valor_divergente';
        }

        if (($body['moeda'] ?? null) !== 'BRL' || $orcamento->moeda !== 'BRL') {
            return 'moeda_invalida';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function corpoSemPagamento(string $osId, string $orcamentoId, array $body, string $motivo): array
    {
        $valor = is_int($body['valorTotal'] ?? null) || is_float($body['valorTotal'] ?? null)
            ? (float) $body['valorTotal']
            : 0.0;

        return [
            'osId' => $osId,
            'pagamentoId' => (string) Str::uuid(),
            'orcamentoId' => $orcamentoId,
            'valorPago' => $valor,
            'metodo' => null,
            'confirmadoEm' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'motivo' => $motivo,
        ];
    }

    private function pagamentoDoOrcamento(string $orcamentoId): ?Pagamento
    {
        return Pagamento::query()
            ->where('orcamento_id', $orcamentoId)
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $mensagem
     */
    private function messageId(array $mensagem): string
    {
        $header = $mensagem['header'] ?? null;
        $messageId = is_array($header) ? ($header['messageId'] ?? null) : null;

        return $this->uuid($messageId, 'header.messageId');
    }

    /**
     * @param  array<string, mixed>  $mensagem
     * @return array<string, mixed>
     */
    private function body(array $mensagem): array
    {
        $body = $mensagem['body'] ?? null;
        if (! is_array($body)) {
            throw new MensagemIlegivel('body ausente.');
        }

        return $body;
    }

    private function uuid(mixed $valor, string $campo): string
    {
        if (! is_string($valor) || ! Str::isUuid($valor)) {
            throw new MensagemIlegivel($campo.' ausente ou inválido.');
        }

        return $valor;
    }
}
