<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoCategoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoriaRequest;
use App\Http\Requests\UpdateCategoriaRequest;
use App\Http\Resources\CategoriaResource;
use App\Models\Categoria;
use App\Services\CategoriaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function __construct(private CategoriaService $categorias)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Categoria::class);

        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoCategoria::class)],
            'ativa' => ['nullable', 'in:true,false,1,0'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Categoria::query()
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when(isset($filtros['ativa']), fn ($q) => $q->where('ativa', filter_var($filtros['ativa'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('tipo')
            ->orderBy('nome');

        return CategoriaResource::collection($query->paginate($filtros['por_pagina'] ?? 20));
    }

    public function store(StoreCategoriaRequest $request)
    {
        $categoria = $this->categorias->criar($request->validated(), $request->user());

        return (new CategoriaResource($categoria))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoriaRequest $request, Categoria $categoria)
    {
        $categoria = $this->categorias->atualizar($categoria, $request->validated(), $request->user());

        return new CategoriaResource($categoria);
    }

    public function destroy(Request $request, Categoria $categoria)
    {
        $this->authorize('delete', $categoria);

        $this->categorias->excluir($categoria, $request->user());

        return response()->json(['data' => ['message' => 'Categoria excluída.']]);
    }
}
