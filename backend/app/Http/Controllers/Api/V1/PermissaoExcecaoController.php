<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissaoExcecaoChave;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePermissaoExcecaoRequest;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\Request;

class PermissaoExcecaoController extends Controller
{
    public function __construct(private UsuarioService $usuarios)
    {
    }

    public function catalogo()
    {
        $this->authorize('gerenciarExcecoes', User::class);

        return response()->json(['data' => array_map(
            fn (PermissaoExcecaoChave $chave) => [
                'chave' => $chave->value,
                'descricao' => $chave->descricao(),
            ],
            PermissaoExcecaoChave::cases()
        )]);
    }

    public function store(StorePermissaoExcecaoRequest $request, User $usuario)
    {
        $excecao = $this->usuarios->concederExcecao(
            $usuario,
            $request->validated('permissao'),
            $request->user()
        );

        return response()->json(['data' => [
            'id' => $excecao->id,
            'permissao' => $excecao->permissao,
        ]], 201);
    }

    public function destroy(Request $request, User $usuario, string $permissao)
    {
        $this->authorize('gerenciarExcecoes', User::class);

        $this->usuarios->revogarExcecao($usuario, $permissao, $request->user());

        return response()->json(['data' => ['message' => 'Exceção revogada.']]);
    }
}
