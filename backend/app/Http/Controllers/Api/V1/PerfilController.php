<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerfilResource;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    /**
     * Retorna apenas os perfis que o usuário autenticado pode atribuir,
     * reaproveitando a mesma regra usada na criação/edição de usuários.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $ator = $request->user();

        $atribuiveis = Perfil::orderBy('id')->get()
            ->filter(fn (Perfil $perfil) => $ator->can('create', [User::class, $perfil->slug]))
            ->values();

        return PerfilResource::collection($atribuiveis);
    }
}
