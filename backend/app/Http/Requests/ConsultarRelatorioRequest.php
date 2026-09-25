<?php

namespace App\Http\Requests;

use App\Support\AnoMes;
use App\Support\Relatorios\CatalogoRelatorios;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Consulta de um relatório. authorize() roda ANTES das regras: quem não pode ver o relatório recebe 403 (nunca 422).
 * Só os filtros que fazem sentido para o relatório pedido são validados e aceitos (CatalogoRelatorios); os demais
 * parâmetros — inclusive tentativas de forçar `visao`, `escopo` ou perfil — são ignorados: o backend decide tudo pela
 * Policy e pelo usuário autenticado.
 */
class ConsultarRelatorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', [CatalogoRelatorios::class, $this->relatorio()]);
    }

    public function rules(): array
    {
        $relatorio = $this->relatorio();

        $regras = [
            'ano_mes' => ['nullable', 'string', 'regex:' . AnoMes::PADRAO, function (string $atributo, mixed $valor, Closure $falhar) {
                if ($valor > AnoMes::corrente()) {
                    $falhar('Não é possível consultar um mês futuro.');
                }
            }],
        ];

        foreach (CatalogoRelatorios::filtros($relatorio) as $filtro) {
            $regras[$filtro] = $filtro === 'status'
                ? ['nullable', 'string', Rule::in(CatalogoRelatorios::statusDespesa())]
                : ['nullable', 'integer', 'min:1'];
        }

        $ordenacao = CatalogoRelatorios::ordenacao($relatorio);
        if ($ordenacao !== []) {
            $regras['ordenar'] = ['bail', 'nullable', 'string', 'max:100', function (string $atributo, mixed $valor, Closure $falhar) use ($ordenacao) {
                foreach (explode(',', $valor) as $campo) {
                    if (! in_array(ltrim($campo, '-'), $ordenacao, true)) {
                        $falhar('Campo de ordenação inválido.');
                    }
                }
            }];
        }

        if (CatalogoRelatorios::paginado($relatorio)) {
            $regras['por_pagina'] = ['nullable', 'integer', 'min:1', 'max:100'];
            $regras['page'] = ['nullable', 'integer', 'min:1'];
        }

        return $regras;
    }

    public function messages(): array
    {
        return [
            'ano_mes.regex' => 'Informe o mês no formato AAAA-MM.',
        ];
    }

    public function relatorio(): string
    {
        return (string) $this->route('relatorio');
    }

    /**
     * Filtros já normalizados (mês omitido = mês corrente do fuso da igreja). Sem `por_pagina`/`page`.
     *
     * @return array<string, mixed>
     */
    public function filtros(): array
    {
        $validados = $this->validated();

        $filtros = ['ano_mes' => $validados['ano_mes'] ?? AnoMes::corrente()];
        foreach (CatalogoRelatorios::filtros($this->relatorio()) as $filtro) {
            if (($validados[$filtro] ?? null) !== null) {
                $filtros[$filtro] = $filtro === 'status' ? $validados[$filtro] : (int) $validados[$filtro];
            }
        }
        if (($validados['ordenar'] ?? null) !== null) {
            $filtros['ordenar'] = $validados['ordenar'];
        }

        return $filtros;
    }

    public function porPagina(): int
    {
        return (int) ($this->validated('por_pagina') ?? 20);
    }

    public function pagina(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
