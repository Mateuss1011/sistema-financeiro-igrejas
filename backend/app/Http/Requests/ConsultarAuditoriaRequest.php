<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use App\Services\ConsultaAuditoriaService;
use App\Support\CatalogoAuditoria;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** authorize() roda antes das regras: quem não pode consultar recebe 403 (nunca 422 revelando o formato). */
class ConsultarAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditLog::class);
    }

    public function rules(): array
    {
        return [
            'modulo' => ['nullable', 'string', Rule::in(CatalogoAuditoria::modulos())],
            'acao' => ['nullable', 'string', Rule::in(CatalogoAuditoria::acoes())],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'sem_usuario' => ['nullable', 'in:true,false,1,0'],
            'registro_id' => ['nullable', 'integer', 'min:1'],
            'data_de' => ['nullable', 'date_format:Y-m-d'],
            'data_ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de'],
            'ordenar' => ['bail', 'nullable', 'string', 'max:100', function (string $atributo, mixed $valor, Closure $falhar) {
                foreach (explode(',', $valor) as $campo) {
                    if (! in_array(ltrim($campo, '-'), ConsultaAuditoriaService::CAMPOS_ORDENACAO, true)) {
                        $falhar('Campo de ordenação inválido.');
                    }
                }
            }],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
