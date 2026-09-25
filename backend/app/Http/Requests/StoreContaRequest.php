<?php

namespace App\Http\Requests;

use App\Enums\TipoConta;
use App\Models\Conta;
use App\Support\Dinheiro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreContaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Conta::class);
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required', 'string', 'max:100',
                // Único apenas entre contas não excluídas (o banco reforça via nome_ativo).
                Rule::unique('contas', 'nome')->whereNull('deleted_at'),
            ],
            'tipo' => ['required', Rule::enum(TipoConta::class)],
            'saldo_inicial' => [
                'nullable',
                function (string $atributo, mixed $valor, \Closure $falhar) {
                    if ($valor !== null && Dinheiro::normalizar($valor) === null) {
                        $falhar('O saldo inicial deve ser um valor decimal com até 2 casas (ex.: 1500.00).');
                    }
                },
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $valor = Dinheiro::normalizar($this->input('saldo_inicial'));

                if ($valor !== null
                    && $this->input('tipo') === TipoConta::Caixa->value
                    && Dinheiro::ehNegativo($valor)
                ) {
                    $validator->errors()->add('saldo_inicial', 'O saldo inicial de um caixa não pode ser negativo.');
                }
            },
        ];
    }
}
