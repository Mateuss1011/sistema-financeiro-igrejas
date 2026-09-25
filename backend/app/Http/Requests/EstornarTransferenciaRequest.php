<?php

namespace App\Http\Requests;

use App\Models\Transferencia;
use Illuminate\Foundation\Http\FormRequest;

class EstornarTransferenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reverse', Transferencia::class);
    }

    public function rules(): array
    {
        return [
            'justificativa' => ['required', 'string', 'min:3', 'max:500'],
            'confirmar_saldo_negativo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'justificativa.required' => 'Informe a justificativa do estorno.',
            'justificativa.min' => 'A justificativa deve ter pelo menos 3 caracteres.',
        ];
    }
}
