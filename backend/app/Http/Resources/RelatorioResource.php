<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `data` = as linhas do relatório; `meta` (via `additional`) = relatório, mês, escopo, colunas, totais, filtros
 * aplicados, paginação e (só no Resumo) os indicadores no formato do Dashboard. Nenhum campo interno ou pessoal
 * além do nome de quem criou o lançamento (o Auxiliar só recebe os próprios).
 */
class RelatorioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->linhas;
    }
}
