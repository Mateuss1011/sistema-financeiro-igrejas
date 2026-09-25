<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateUsuarioRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function __construct(private UsuarioService $usuarios)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        // Mesmo teto das demais listagens (1–100): sem isto, `por_pagina=-1` virava erro de SQL (500) e um valor gigante
        // devolvia todos os usuários de uma vez.
        $filtros = $request->validate([
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $usuarios = User::with($this->relacoes($request))
            ->orderBy('name')
            ->paginate($filtros['por_pagina'] ?? 20);

        return UsuarioResource::collection($usuarios);
    }

    public function store(StoreUsuarioRequest $request)
    {
        $usuario = $this->usuarios->criar($request->validated(), $request->user());

        return (new UsuarioResource($usuario->load($this->relacoes($request))))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateUsuarioRequest $request, User $usuario)
    {
        $usuario = $this->usuarios->atualizar($usuario, $request->validated(), $request->user());

        return new UsuarioResource($usuario->load($this->relacoes($request)));
    }

    public function destroy(Request $request, User $usuario)
    {
        $this->authorize('delete', $usuario);

        $this->usuarios->desativar($usuario, $request->user());

        return response()->json(['data' => ['message' => 'Usuário desativado.']]);
    }

    /** @return list<string> */
    private function relacoes(Request $request): array
    {
        return $request->user()->can('gerenciarExcecoes', User::class)
            ? ['perfil', 'permissoesExcecao']
            : ['perfil'];
    }
}
