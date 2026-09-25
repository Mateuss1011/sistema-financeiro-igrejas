<?php

namespace App\Http\Requests;

use App\Enums\SentidoAjuste;
use App\Models\AjusteSaldo;
use App\Support\Dinheiro;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ajuste = valor POSITIVO + sentido. Nunca aceita saldo alvo, valor com sinal nem campos controlados. */
class StoreAjusteSaldoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AjusteSaldo::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();

        return [
            'conta_id' => ['required', 'integer', Rule::exists('contas', 'id')->whereNull('deleted_at')],
            'valor' => ['required', StoreDespesaRequest::regraValor()],
            'sentido' => ['required', Rule::enum(SentidoAjuste::class)],
            'data_ajuste' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje],
            'justificativa' => ['required', 'string', 'min:3', 'max:500'],
            'confirmar_saldo_negativo' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'min:1', 'max:64', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'conta_id.required' => 'Selecione a conta.',
            'sentido.required' => 'Informe se o ajuste é de crédito ou de débito.',
            'data_ajuste.required' => 'Informe a data do ajuste.',
            'data_ajuste.before_or_equal' => 'A data do ajuste não pode ser futura.',
            'data_ajuste.date_format' => 'Informe uma data válida (AAAA-MM-DD).',
            'justificativa.required' => 'Informe a justificativa do ajuste.',
            'justificativa.min' => 'A justificativa deve ter pelo menos 3 caracteres.',
            'idempotency_key.regex' => 'O Idempotency-Key deve conter apenas caracteres ASCII visíveis (sem espaços).',
            'idempotency_key.max' => 'O Idempotency-Key deve ter no máximo 64 caracteres.',
        ];
    }

    public function dadosNormalizados(): array
    {
        $dados = $this->validated();

        return [
            'conta_id' => (int) $dados['conta_id'],
            'valor' => Dinheiro::normalizar($dados['valor']),
            'sentido' => $dados['sentido'],
            'data_ajuste' => $dados['data_ajuste'],
            'justificativa' => trim($dados['justificativa']),
        ];
    }

    public function chaveIdempotencia(): ?string
    {
        return $this->validated()['idempotency_key'] ?? null;
    }
}
