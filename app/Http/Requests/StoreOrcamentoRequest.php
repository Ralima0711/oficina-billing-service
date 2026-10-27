<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrcamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'osId' => ['required', 'uuid'],
            'clienteId' => ['required', 'uuid'],
            'veiculo' => ['required', 'array'],
            'veiculo.placa' => ['required', 'string', 'max:10'],
            'veiculo.modelo' => ['required', 'string', 'max:120'],
            'veiculo.ano' => ['required', 'integer', 'min:1900', 'max:2100'],
            'descricaoProblema' => ['required', 'string'],
            'itensDiagnosticados' => ['required', 'array', 'min:1'],
            'itensDiagnosticados.*.descricao' => ['required', 'string'],
            'itensDiagnosticados.*.tipo' => ['required', 'in:peca,servico'],
            'itensDiagnosticados.*.quantidade' => ['required', 'integer', 'min:1'],
        ];
    }
}
