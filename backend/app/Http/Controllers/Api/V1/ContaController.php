<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoConta;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContaRequest;
use App\Http\Requests\UpdateContaRequest;
use App\Http\Resources\ContaResource;
use App\Models\Conta;
use App\Services\ContaService;
use App\Services\SaldoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContaController extends Controller
{
    public function __construct(private ContaService $contas, private SaldoService $saldos)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Conta::class);

        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoConta::class)],
            'ativa' => ['nullable', 'in:true,false,1,0'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Conta::query()
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when(isset($filtros['ativa']), fn ($q) => $q->where('ativa', filter_var($filtros['ativa'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('tipo')
            ->orderBy('nome');

        $paginado = $query->paginate($filtros['por_pagina'] ?? 20);
        $this->saldos->anexarSaldos($paginado->items());

        return ContaResource::collection($paginado);
    }

    public function store(StoreContaRequest $request)
    {
        $conta = $this->contas->criar($request->validated(), $request->user());

        return (new ContaResource($conta))->response()->setStatusCode(201);
    }

    public function update(UpdateContaRequest $request, Conta $conta)
    {
        $conta = $this->contas->atualizar($conta, $request->validated(), $request->user());

        return new ContaResource($conta);
    }

    public function destroy(Request $request, Conta $conta)
    {
        $this->authorize('delete', $conta);

        $this->contas->excluir($conta, $request->user());

        return response()->json(['data' => ['message' => 'Conta excluída.']]);
    }
}
