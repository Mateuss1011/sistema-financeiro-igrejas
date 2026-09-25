<?php

namespace App\Http\Requests;

use App\Models\Despesa;
use Illuminate\Foundation\Http\FormRequest;

class EstornarDespesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reverse', Despesa::class);
    }

    public function rules(): array
    {
        return ['justificativa' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function messages(): array
    {
        return [
            'justificativa.required' => 'Informe a justificativa do estorno.',
            'justificativa.min' => 'A justificativa deve ter pelo menos 3 caracteres.',
        ];
    }
}
