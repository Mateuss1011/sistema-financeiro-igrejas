<?php

namespace App\Http\Requests;

use App\Models\Conta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('conta'));
    }

    public function rules(): array
    {
        /** @var Conta $conta */
        $conta = $this->route('conta');

        return [
            // Imutáveis: definidos na criação e nunca alterados (mesmo que o valor seja igual).
            'tipo' => ['prohibited'],
            'saldo_inicial' => ['prohibited'],
            'nome' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('contas', 'nome')->whereNull('deleted_at')->ignore($conta->id),
            ],
            'ativa' => ['sometimes', 'boolean'],
        ];
    }
}
