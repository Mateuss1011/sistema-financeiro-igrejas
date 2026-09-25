<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uma sessão já aberta não sobrevive à desativação do usuário. O login recusa inativos, mas a sessão (cookie) de quem foi
 * desativado DEPOIS de entrar continuaria válida até expirar: aqui, a cada requisição autenticada, o usuário é
 * conferido no banco e, se estiver inativo, a sessão é encerrada e a resposta é 401 (o frontend volta ao login).
 */
class GarantirUsuarioAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->ativo) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Sua sessão foi encerrada. Faça login novamente.',
                'code' => 'USUARIO_INATIVO',
                'errors' => [],
            ], 401);
        }

        return $next($request);
    }
}
