<?php

namespace App\Http\Requests;

use App\Support\Relatorios\CatalogoRelatorios;

/**
 * Exportação: além de poder VER o relatório, o ator precisa poder EXPORTAR (Auxiliar e Secretário: 403 no backend,
 * antes de qualquer consulta). Não há paginação — a exportação sempre cobre todo o conjunto filtrado.
 */
class ExportarRelatorioRequest extends ConsultarRelatorioRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('export', CatalogoRelatorios::class) && parent::authorize();
    }

    public function rules(): array
    {
        $regras = parent::rules();
        unset($regras['por_pagina'], $regras['page']);

        return $regras;
    }
}
