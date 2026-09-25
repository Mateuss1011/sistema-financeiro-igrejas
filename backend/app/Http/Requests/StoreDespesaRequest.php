<?php

namespace App\Http\Requests;

use App\Models\Despesa;
use App\Support\Dinheiro;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Criação de despesa: sempre Pendente; conta_id, status e demais campos controlados são ignorados. */
class StoreDespesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Despesa::class);
    }

    /** O header Idempotency-Key é validado junto com o corpo (e sobrescreve qualquer campo homônimo). */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        // "Hoje" no fuso da igreja, só para esta validação (o fuso global não muda).
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();

        return [
            'categoria_id' => ['required', 'integer', Rule::exists('categorias', 'id')->where('tipo', 'despesa')],
            'valor' => ['required', self::regraValor()],
            'data_competencia' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje],
            'descricao' => ['required', 'string', 'max:255'],
            'fornecedor_nome' => ['nullable', 'string', 'max:150'],
            'idempotency_key' => ['nullable', 'string', 'min:1', 'max:64', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'descricao.required' => 'Informe a descrição da despesa.',
            'data_competencia.before_or_equal' => 'A data de competência não pode ser futura.',
            'data_competencia.date_format' => 'Informe uma data válida (AAAA-MM-DD).',
            'idempotency_key.regex' => 'O Idempotency-Key deve conter apenas caracteres ASCII visíveis (sem espaços).',
            'idempotency_key.max' => 'O Idempotency-Key deve ter no máximo 64 caracteres.',
        ];
    }

    /** Regra de valor monetário: decimal com até 2 casas, maior que zero (sem float, sem arredondar). */
    public static function regraValor(): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falhar) {
            $normalizado = Dinheiro::normalizar($valor);

            if ($normalizado === null) {
                $falhar('O valor deve ser um número decimal com até 2 casas (ex.: 150.00).');
            } elseif (bccomp($normalizado, '0', 2) <= 0) {
                $falhar('O valor deve ser maior que zero.');
            }
        };
    }

    public static function textoOuNulo(mixed $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }

    /** Payload normalizado (também é a base do hash de idempotência). */
    public function dadosNormalizados(): array
    {
        $dados = $this->validated();

        return [
            'categoria_id' => (int) $dados['categoria_id'],
            'valor' => Dinheiro::normalizar($dados['valor']),
            'data_competencia' => $dados['data_competencia'],
            'descricao' => self::textoOuNulo($dados['descricao']) ?? '',
            'fornecedor_nome' => self::textoOuNulo($dados['fornecedor_nome'] ?? null),
        ];
    }

    public function chaveIdempotencia(): ?string
    {
        return $this->validated()['idempotency_key'] ?? null;
    }
}
