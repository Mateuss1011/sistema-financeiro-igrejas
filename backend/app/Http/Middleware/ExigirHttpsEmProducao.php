<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTPS obrigatório em produção. Fora de produção (local/testing) não interfere. Leituras (GET/HEAD) são redirecionadas
 * (308) para a URL segura; qualquer outro método é RECUSADO (426) em vez de redirecionado — o corpo (senha, dados
 * financeiros) já teria trafegado em texto puro. Atrás de proxy reverso, o proxy precisa ser confiável
 * (TRUSTED_PROXIES) para o Laravel reconhecer o HTTPS terminado nele.
 */
class ExigirHttpsEmProducao
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction() || $request->isSecure() || $request->is('api/v1/health', 'up')) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            // O host do redirecionamento vem da configuração (APP_URL), nunca do cabeçalho Host da requisição, que o
            // cliente controla (envenenamento de Host).
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost();

            return redirect()->to('https://' . $host . $request->getRequestUri(), 308);
        }

        return response()->json([
            'message' => 'Esta API só aceita conexões seguras (HTTPS).',
            'code' => 'HTTPS_OBRIGATORIO',
            'errors' => [],
        ], 426);
    }
}
