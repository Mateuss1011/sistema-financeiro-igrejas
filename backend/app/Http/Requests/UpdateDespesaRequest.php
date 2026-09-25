<?php

namespace App\Http\Requests;

use App\Models\Despesa;
use App\Support\Dinheiro;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edição parcial de uma Pendente. Campos financeiros/controlados são PROIBIDOS (422). */
class UpdateDespesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Despesa::class);
    }

    public function rules(): array
    {
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();

        return [
            'status' => ['prohibited'],
            'conta_id' => ['prohibited'],
            'data_pagamento' => ['prohibited'],
            'pago_por' => ['prohibited'],
            'pago_em' => ['prohibited'],
            'criado_por' => ['prohibited'],
            'atualizado_por' => ['prohibited'],
            'despesa_estornada_id' => ['prohibited'],
            'motivo_estorno' => ['prohibited'],
            'motivo_cancelamento' => ['prohibited'],
            'saldo_atual' => ['prohibited'],
            'categoria_id' => ['sometimes', 'required', 'integer', Rule::exists('categorias', 'id')->where('tipo', 'despesa')],
            'valor' => ['sometimes', 'required', StoreDespesaRequest::regraValor()],
            'data_competencia' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje],
            'descricao' => ['sometimes', 'required', 'string', 'max:255'],
            'fornecedor_nome' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'descricao.required' => 'Informe a descrição da despesa.',
            'data_competencia.before_or_equal' => 'A data de competência não pode ser futura.',
            'data_competencia.date_format' => 'Informe uma data válida (AAAA-MM-DD).',
        ];
    }

    /** Somente os campos enviados, normalizados. */
    public function dadosNormalizados(): array
    {
        $dados = $this->validated();
        $saida = [];

        if (array_key_exists('categoria_id', $dados)) {
            $saida['categoria_id'] = (int) $dados['categoria_id'];
        }
        if (array_key_exists('valor', $dados)) {
            $saida['valor'] = Dinheiro::normalizar($dados['valor']);
        }
        if (array_key_exists('data_competencia', $dados)) {
            $saida['data_competencia'] = $dados['data_competencia'];
        }
        if (array_key_exists('descricao', $dados)) {
            $saida['descricao'] = StoreDespesaRequest::textoOuNulo($dados['descricao']) ?? '';
        }
        if (array_key_exists('fornecedor_nome', $dados)) {
            $saida['fornecedor_nome'] = StoreDespesaRequest::textoOuNulo($dados['fornecedor_nome']);
        }

        return $saida;
    }
}
