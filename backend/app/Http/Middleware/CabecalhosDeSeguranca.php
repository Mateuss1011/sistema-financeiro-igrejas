<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança de TODA resposta. A API só devolve JSON ou arquivos de download: nenhum recurso é embutível
 * em página, então a política é a mais restritiva possível. Os dados são financeiros e por usuário — nunca devem ficar
 * em cache de navegador ou de intermediário (`no-store`). HSTS só é enviado por HTTPS e em produção.
 *
 * É middleware GLOBAL (cobre também rotas inexistentes/405) e a mesma função é aplicada às respostas de exceção pelo
 * handler (bootstrap/app.php), que não passam pelo "retorno" da pilha de middleware (401 de autenticação, 404, 429...).
 */
class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::aplicar($next($request), $request);
    }

    public static function aplicar(Response $resposta, Request $request): Response
    {
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('X-Frame-Options', 'DENY');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');
        $resposta->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $resposta->headers->set('Cache-Control', 'no-store, private');

        if ($request->isSecure() && app()->isProduction()) {
            $resposta->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $resposta;
    }
}
