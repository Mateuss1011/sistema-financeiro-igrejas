<?php

namespace App\Http\Requests;

use App\Models\Despesa;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PagarDespesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pay', Despesa::class);
    }

    public function rules(): array
    {
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();

        return [
            'conta_id' => ['required', 'integer', Rule::exists('contas', 'id')->whereNull('deleted_at')],
            'data_pagamento' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje],
            'confirmar_saldo_negativo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'conta_id.required' => 'Informe a conta de origem do pagamento.',
            'data_pagamento.required' => 'Informe a data de pagamento.',
            'data_pagamento.before_or_equal' => 'A data de pagamento não pode ser futura.',
            'data_pagamento.date_format' => 'Informe uma data válida (AAAA-MM-DD).',
        ];
    }
}
