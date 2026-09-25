<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultarAuditoriaRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\ConsultaAuditoriaService;
use Illuminate\Http\Request;

/**
 * Somente leitura, por decisão do plano (seções 4 e 6): sem POST/PUT/PATCH/DELETE e sem GET individual —
 * o registro completo (incluindo dados anteriores/novos) já vem na listagem.
 */
class AuditoriaController extends Controller
{
    public function __construct(private ConsultaAuditoriaService $consulta)
    {
    }

    public function index(ConsultarAuditoriaRequest $request)
    {
        return AuditLogResource::collection($this->consulta->consultar($request->validated()));
    }

    public function catalogo(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        return response()->json(['data' => $this->consulta->catalogo()]);
    }
}
