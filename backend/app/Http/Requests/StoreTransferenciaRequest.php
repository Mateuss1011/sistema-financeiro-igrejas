<?php

namespace App\Http\Requests;

use App\Models\Transferencia;
use App\Support\Dinheiro;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transferencia::class);
    }

    /** O header Idempotency-Key é validado junto com o corpo (e sobrescreve qualquer campo homônimo). */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();

        return [
            'conta_origem_id' => ['required', 'integer', Rule::exists('contas', 'id')->whereNull('deleted_at')],
            'conta_destino_id' => ['required', 'integer', 'different:conta_origem_id', Rule::exists('contas', 'id')->whereNull('deleted_at')],
            'valor' => ['required', StoreDespesaRequest::regraValor()],
            'data_transferencia' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje],
            'descricao' => ['nullable', 'string', 'max:255'],
            'confirmar_saldo_negativo' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'min:1', 'max:64', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'conta_origem_id.required' => 'Selecione a conta de origem.',
            'conta_destino_id.required' => 'Selecione a conta de destino.',
            'conta_destino_id.different' => 'A conta de destino deve ser diferente da conta de origem.',
            'data_transferencia.required' => 'Informe a data da transferência.',
            'data_transferencia.before_or_equal' => 'A data da transferência não pode ser futura.',
            'data_transferencia.date_format' => 'Informe uma data válida (AAAA-MM-DD).',
            'idempotency_key.regex' => 'O Idempotency-Key deve conter apenas caracteres ASCII visíveis (sem espaços).',
            'idempotency_key.max' => 'O Idempotency-Key deve ter no máximo 64 caracteres.',
        ];
    }

    /** Payload normalizado (também é a base do hash de idempotência). */
    public function dadosNormalizados(): array
    {
        $dados = $this->validated();

        return [
            'conta_origem_id' => (int) $dados['conta_origem_id'],
            'conta_destino_id' => (int) $dados['conta_destino_id'],
            'valor' => Dinheiro::normalizar($dados['valor']),
            'data_transferencia' => $dados['data_transferencia'],
            'descricao' => StoreDespesaRequest::textoOuNulo($dados['descricao'] ?? null),
        ];
    }

    public function chaveIdempotencia(): ?string
    {
        return $this->validated()['idempotency_key'] ?? null;
    }
}
