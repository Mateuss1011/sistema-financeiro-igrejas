<?php

use App\Exceptions\LimiteExcedidoException;
use App\Http\Middleware\CabecalhosDeSeguranca;
use App\Http\Middleware\ExigirHttpsEmProducao;
use App\Http\Middleware\GarantirUsuarioAtivo;
use App\Http\Middleware\RejeitarBytesNulos;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Globais: valem para qualquer rota (inclusive as inexistentes e o /sanctum/csrf-cookie). Ambos vão DEPOIS da
        // pilha padrão do Laravel (que inclui o TrustProxies): o HTTPS só pode ser avaliado depois de o Laravel decidir em
        // quais proxies confiar. Os cabeçalhos ficam por fora do teste de HTTPS para cobrir também o 308/426.
        $middleware->append([CabecalhosDeSeguranca::class, ExigirHttpsEmProducao::class]);
        $middleware->api(prepend: [EnsureFrontendRequestsAreStateful::class, RejeitarBytesNulos::class]);

        // Usuário desativado perde a sessão já aberta (aplicado às rotas autenticadas em routes/api.php).
        $middleware->alias(['usuario.ativo' => GarantirUsuarioAtivo::class]);

        // Backend 100% API — nunca existe uma página web de login para redirecionar
        // (o default do framework tentaria route('login'), que não existe aqui).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toda rota /api/* sempre responde em JSON, mesmo sem header Accept correto
        // (ex: chamadas simples via fetch/curl sem "Accept: application/json").
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));

        // As respostas geradas a partir de exceções (401, 403, 404, 419, 422, 429, 500...) também levam os cabeçalhos de segurança.
        $exceptions->respond(fn ($resposta, Throwable $e, Request $request) => CabecalhosDeSeguranca::aplicar($resposta, $request));

        $erro = fn (int $status, string $mensagem, string $codigo, array $cabecalhos = []) => response()->json([
            'message' => $mensagem,
            'code' => $codigo,
            'errors' => [],
        ], $status, $cabecalhos);

        // Mensagens do framework para 404/405/419/429 citam classes internas do sistema ("No query results for model
        // [App\Models\User] 5") e rotas: a resposta externa é sempre genérica e no formato padrão {message, code, errors}.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($erro) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match ($e->getStatusCode()) {
                404 => $erro(404, 'Recurso não encontrado.', 'NAO_ENCONTRADO'),
                405 => $erro(405, 'Método não permitido para este recurso.', 'METODO_NAO_PERMITIDO', array_intersect_key($e->getHeaders(), ['Allow' => true])),
                419 => $erro(419, 'Sessão de segurança expirada. Recarregue a página e tente novamente.', 'CSRF_INVALIDO'),
                429 => $erro(
                    429,
                    'Muitas tentativas. Aguarde alguns instantes e tente novamente.',
                    $e instanceof LimiteExcedidoException ? $e->codigoErro : 'MUITAS_REQUISICOES',
                    array_intersect_key($e->getHeaders(), ['Retry-After' => true]),
                ),
                default => null,
            };
        });

        // Falha inesperada (bug, banco fora do ar, TypeError...): NUNCA devolve stack trace, SQL, caminho de arquivo ou
        // mensagem interna. O detalhe técnico continua indo para o log técnico (report), separado da auditoria de negócio.
        // Em produção isto vale mesmo que APP_DEBUG tenha sido ligado por engano.
        $exceptions->render(function (Throwable $e, Request $request) use ($erro) {
            if (! $request->is('api/*')
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException
                || $e instanceof HttpExceptionInterface
                || (config('app.debug') && ! app()->isProduction())) {
                return null;
            }

            return $erro(500, 'Ocorreu um erro interno. Tente novamente em instantes.', 'ERRO_INTERNO');
        });
    })->create();
