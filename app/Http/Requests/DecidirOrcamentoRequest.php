<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisao do cliente sobre um orcamento.
 *
 * "motivo" so faz sentido na recusa — o contrato de eventos diz, no payload de
 * OrcamentoDecidido, "Preenchido apenas na recusa". Aceitar motivo numa
 * aprovacao e deixar entrar um campo que nunca seria publicado.
 */
class DecidirOrcamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decisao' => ['required', 'string', 'in:aprovar,recusar'],
            'motivo' => ['nullable', 'string', 'max:255', 'exclude_if:decisao,aprovar'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decisao.in' => 'decisao deve ser "aprovar" ou "recusar".',
        ];
    }

    public function aprovou(): bool
    {
        return $this->validated()['decisao'] === 'aprovar';
    }

    public function motivo(): ?string
    {
        $motivo = $this->validated()['motivo'] ?? null;

        return is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : null;
    }
}
