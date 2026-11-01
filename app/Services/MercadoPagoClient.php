<?php

namespace App\Services;

use App\Exceptions\CobrancaException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MercadoPagoClient
{
    public function criarPreferencia(string $orcamentoId, float $valor, string $moeda): array
    {
        $resposta = $this->http()->post($this->url('/checkout/preferences'), [
            'items' => [[
                'title' => 'Orçamento '.$orcamentoId,
                'quantity' => 1,
                'currency_id' => $moeda,
                'unit_price' => round($valor, 2),
            ]],
            'external_reference' => $orcamentoId,
        ]);

        if ($resposta->failed() || ! is_string($resposta->json('id')) || ! is_string($resposta->json('sandbox_init_point'))) {
            throw CobrancaException::mercadoPago($resposta->status());
        }

        return $resposta->json();
    }

    public function buscarPorReferencia(string $orcamentoId): ?array
    {
        $resposta = $this->http()->get($this->url('/v1/payments/search'), [
            'external_reference' => $orcamentoId,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]);

        if ($resposta->failed()) {
            throw CobrancaException::mercadoPago($resposta->status());
        }

        $resultado = $resposta->json('results.0');

        return is_array($resultado) ? $resultado : null;
    }

    private function http(): PendingRequest
    {
        $token = config('services.mercadopago.access_token');

        if (! is_string($token) || $token === '') {
            throw CobrancaException::semToken();
        }

        return Http::withToken($token)->acceptJson();
    }

    private function url(string $caminho): string
    {
        return rtrim((string) config('services.mercadopago.base_url'), '/').$caminho;
    }
}
