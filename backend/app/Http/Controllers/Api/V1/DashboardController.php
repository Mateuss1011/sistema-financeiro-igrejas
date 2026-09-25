<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultarDashboardRequest;
use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;

/** Somente leitura: um único GET. Nada aqui grava, trava ou audita (o plano não lista consulta como auditada). */
class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    public function show(ConsultarDashboardRequest $request)
    {
        return new DashboardResource($this->dashboard->consultar($request->user(), $request->anoMes()));
    }
}
