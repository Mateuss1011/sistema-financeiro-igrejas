<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Byte nulo (\0) nunca é um dado legítimo nesta API. Além de inútil, ele derruba regras nativas do PHP/Laravel
 * (ex.: `date_format` chama DateTime::createFromFormat, que lança ValueError → 500) e é vetor clássico de truncamento
 * de strings. Recusa cedo, com 422 no formato padrão, qualquer entrada (query, corpo JSON/form, chaves e valores)
 * que contenha um.
 */
class RejeitarBytesNulos
{
    public function handle(Request $request, Closure $next): Response
    {
        $contem = false;
        $entrada = $request->all();
        array_walk_recursive($entrada, function ($valor, $chave) use (&$contem) {
            if ((is_string($valor) && str_contains($valor, "\0")) || (is_string($chave) && str_contains($chave, "\0"))) {
                $contem = true;
            }
        });

        if ($contem) {
            return response()->json([
                'message' => 'A requisição contém caracteres inválidos.',
                'code' => 'ENTRADA_INVALIDA',
                'errors' => [],
            ], 422);
        }

        return $next($request);
    }
}
