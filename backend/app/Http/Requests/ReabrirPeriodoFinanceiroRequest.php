<?php

namespace App\Http\Requests;

use App\Models\PeriodoFinanceiro;
use Illuminate\Foundation\Http\FormRequest;

class ReabrirPeriodoFinanceiroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reabrir', PeriodoFinanceiro::class);
    }

    public function rules(): array
    {
        return [
            'justificativa' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'justificativa.required' => 'Informe a justificativa da reabertura.',
            'justificativa.min' => 'A justificativa deve ter pelo menos 3 caracteres.',
        ];
    }
}
