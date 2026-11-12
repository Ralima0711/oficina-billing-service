<?php

namespace App\Services;

use App\Exceptions\ItemSemPreco;
use App\Exceptions\MensagemIlegivel;
use App\Mensageria\CaixaDeSaida;
use App\Mensageria\ConsumidorDeComando;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessarGerarOrcamento implements ConsumidorDeComando
{
    public function __construct(
        private readonly GerarOrcamento $gerar,
        private readonly CaixaDeSaida $caixa,
    ) {}

    /**
     * @param  array<string, mixed>  $mensagem
     */
    public function executar(array $mensagem): void
    {
        $header = $mensagem['header'] ?? null;
        $messageId = is_array($header) ? ($header['messageId'] ?? null) : null;

        if (! is_string($messageId) || ! Str::isUuid($messageId)) {
            throw new MensagemIlegivel('header.messageId ausente ou inválido.');
        }

        if ($this->caixa->jaProcessada($messageId)) {
            $this->caixa->publicarPendentes($messageId);

            return;
        }

        $body = $mensagem['body'] ?? null;
        if (! is_array($body)) {
            throw new MensagemIlegivel('body ausente.');
        }

        $osId = $body['osId'] ?? null;
        if (! is_string($osId) || ! Str::isUuid($osId)) {
            throw new MensagemIlegivel('body.osId ausente ou inválido.');
        }

        try {
            DB::transaction(function () use ($mensagem, $messageId, $body, $osId) {
                $this->caixa->registrar(
                    $messageId,
                    is_string($mensagem['header']['type'] ?? null) ? $mensagem['header']['type'] : 'GerarOrcamento',
                );

                $falha = $this->motivoDeFalha($body);
                if ($falha !== null) {
                    $this->caixa->gravar($mensagem, 'orcamento.falhou', $this->corpoFalha($osId, $falha[0], $falha[1]));

                    return;
                }

                try {
                    $sagaId = $mensagem['header']['sagaId'] ?? null;
                    $traceparent = $mensagem['header']['traceparent'] ?? null;
                    $orcamento = $this->gerar->executar(
                        $this->ajustarAcessorios($body),
                        is_string($sagaId) && $sagaId !== '' ? $sagaId : null,
                        is_string($traceparent) && $traceparent !== '' ? $traceparent : null,
                    );
                } catch (ItemSemPreco $e) {
                    $this->caixa->gravar($mensagem, 'orcamento.falhou', $this->corpoFalha($osId, 'item_fora_do_catalogo', $e->getMessage()));

                    return;
                }

                $this->caixa->gravar($mensagem, 'orcamento.gerado', $orcamento->paraEvento());
            });
        } catch (UniqueConstraintViolationException) {
            $this->caixa->publicarPendentes($messageId);

            return;
        }

        $this->caixa->publicarPendentes($messageId);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: string}|null
     */
    private function motivoDeFalha(array $body): ?array
    {
        $itens = $body['itensDiagnosticados'] ?? null;

        if ($itens === null || $itens === []) {
            return ['sem_itens_a_orcar', 'Nenhum item diagnosticado para orçar.'];
        }

        if (! is_array($itens)) {
            return ['erro_interno', 'itensDiagnosticados malformado.'];
        }

        if (! is_string($body['clienteId'] ?? null) || ! Str::isUuid($body['clienteId'])) {
            return ['erro_interno', 'clienteId ausente ou inválido.'];
        }

        $veiculo = $body['veiculo'] ?? null;
        $placa = is_array($veiculo) ? ($veiculo['placa'] ?? null) : null;
        if (! is_string($placa) || trim($placa) === '') {
            return ['erro_interno', 'veiculo.placa ausente.'];
        }

        $descricao = $body['descricaoProblema'] ?? null;
        if (! is_string($descricao) || trim($descricao) === '') {
            return ['erro_interno', 'descricaoProblema ausente.'];
        }

        foreach ($itens as $item) {
            if (! $this->itemValido($item)) {
                return ['erro_interno', 'Item diagnosticado inválido.'];
            }
        }

        return null;
    }

    private function itemValido(mixed $item): bool
    {
        if (! is_array($item)) {
            return false;
        }

        $descricao = $item['descricao'] ?? null;
        $tipo = $item['tipo'] ?? null;
        $quantidade = $item['quantidade'] ?? null;

        return is_string($descricao)
            && trim($descricao) !== ''
            && in_array($tipo, ['peca', 'servico'], true)
            && is_int($quantidade)
            && $quantidade >= 1;
    }

    /**
     * Ano e modelo são acessórios: valor inválido é descartado e o comando segue.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function ajustarAcessorios(array $body): array
    {
        if (! isset($body['veiculo']) || ! is_array($body['veiculo'])) {
            return $body;
        }

        foreach (['ano', 'modelo'] as $campo) {
            if (! array_key_exists($campo, $body['veiculo']) || $body['veiculo'][$campo] === null) {
                continue;
            }

            $valido = $campo === 'ano'
                ? is_int($body['veiculo'][$campo])
                : is_string($body['veiculo'][$campo]);

            if ($valido) {
                continue;
            }

            Log::warning('campo acessório fora do contrato, valor ignorado', [
                'campo' => 'veiculo.'.$campo,
                'osId' => $body['osId'] ?? null,
            ]);
            unset($body['veiculo'][$campo]);
        }

        return $body;
    }

    /**
     * @return array{osId: string, motivo: string, detalhe: string, ocorridoEm: string}
     */
    private function corpoFalha(string $osId, string $motivo, string $detalhe): array
    {
        return [
            'osId' => $osId,
            'motivo' => $motivo,
            'detalhe' => $detalhe,
            'ocorridoEm' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
