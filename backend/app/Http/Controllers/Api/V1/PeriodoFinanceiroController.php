<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReabrirPeriodoFinanceiroRequest;
use App\Http\Resources\PeriodoFinanceiroResource;
use App\Models\PeriodoFinanceiro;
use App\Policies\PeriodoFinanceiroPolicy;
use App\Services\PeriodoFinanceiroService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Sem GET individual nem PUT/DELETE: um período só muda de estado via fechar/reabrir.
 * A listagem mistura linhas reais (já fechadas ao menos uma vez) com o mês corrente sintético,
 * por isso a paginação é feita em memória (ver PeriodoFinanceiroService::listarTodos()).
 */
class PeriodoFinanceiroController extends Controller
{
    public function __construct(private PeriodoFinanceiroService $periodos)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', PeriodoFinanceiro::class);

        $filtros = $request->validate([
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $todos = $this->periodos->listarTodos();
        $porPagina = $filtros['por_pagina'] ?? 20;
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $fatia = $todos->slice(($pagina - 1) * $porPagina, $porPagina)->values();

        $paginador = new LengthAwarePaginator(
            $fatia,
            $todos->count(),
            $porPagina,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $permissoes = app(PeriodoFinanceiroPolicy::class)->permissoes($request->user());

        return PeriodoFinanceiroResource::collection($paginador)->additional(['meta' => ['permissoes' => $permissoes]]);
    }

    public function fechar(Request $request, string $ano_mes)
    {
        $this->authorize('fechar', PeriodoFinanceiro::class);

        $periodo = $this->periodos->fechar($ano_mes, $request->user());

        return (new PeriodoFinanceiroResource($periodo->load(['fechadoPor', 'reabertoPor'])))->response()->setStatusCode(200);
    }

    public function reabrir(ReabrirPeriodoFinanceiroRequest $request, string $ano_mes)
    {
        $periodo = $this->periodos->reabrir($ano_mes, $request->user(), $request->validated('justificativa'));

        return (new PeriodoFinanceiroResource($periodo->load(['fechadoPor', 'reabertoPor'])))->response()->setStatusCode(200);
    }
}
