<?php

namespace App\Providers;

use App\Models\AjusteSaldo;
use App\Models\AuditLog;
use App\Models\Categoria;
use App\Policies\AuditLogPolicy;
use App\Policies\DashboardPolicy;
use App\Policies\RelatorioPolicy;
use App\Support\Relatorios\CatalogoRelatorios;
use App\Support\Dashboard;
use App\Models\Transferencia;
use App\Policies\AjusteSaldoPolicy;
use App\Policies\TransferenciaPolicy;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\PeriodoFinanceiro;
use App\Models\User;
use App\Policies\CategoriaPolicy;
use App\Policies\ContaPolicy;
use App\Policies\DespesaPolicy;
use App\Policies\EntradaPolicy;
use App\Policies\PeriodoFinanceiroPolicy;
use App\Policies\UsuarioPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UsuarioPolicy::class);
        Gate::policy(Categoria::class, CategoriaPolicy::class);
        Gate::policy(Conta::class, ContaPolicy::class);
        Gate::policy(Entrada::class, EntradaPolicy::class);
        Gate::policy(Despesa::class, DespesaPolicy::class);
        Gate::policy(Transferencia::class, TransferenciaPolicy::class);
        Gate::policy(AjusteSaldo::class, AjusteSaldoPolicy::class);
        Gate::policy(PeriodoFinanceiro::class, PeriodoFinanceiroPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Dashboard::class, DashboardPolicy::class);
        Gate::policy(CatalogoRelatorios::class, RelatorioPolicy::class);

        // URLs geradas pela aplicação em produção são sempre https (o redirecionamento/recusa de http está no middleware).
        URL::forceHttps(app()->isProduction());

        // Só os proxies listados em TRUSTED_PROXIES (config/app.php) são acreditados; lista vazia = nenhum.
        $proxies = config('app.trusted_proxies', []);
        if ($proxies !== []) {
            TrustProxies::at(in_array('*', $proxies, true) ? '*' : $proxies);
        }

        $this->definirLimitesDeRequisicao();
    }

    /**
     * Rate limiting (Fase 13). Limites escolhidos pelo custo e pelo risco de cada operação — não há throttle "em tudo":
     *  - login-ip: teto de 30 tentativas/min por IP (contra password spraying em vários e-mails). O limite fino por
     *    e-mail+IP (5 falhas/min) vive no AuthController, porque só conta falhas e zera no sucesso;
     *  - exportacoes: 10/min por usuário. Cada exportação monta o arquivo inteiro (até 10.000 linhas) e grava auditoria;
     *  - consultas-pesadas: 60/min por usuário nas agregações (dashboard, relatórios, auditoria). Uma tela humana faz
     *    poucas por minuto; isso barra só automação abusiva.
     * As operações comuns de escrita não têm throttle: já são protegidas por idempotência, locks e regras de negócio.
     */
    private function definirLimitesDeRequisicao(): void
    {
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(30)->by('ip:' . $request->ip()));
        RateLimiter::for('exportacoes', fn (Request $request) => Limit::perMinute(10)->by('u:' . ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('consultas-pesadas', fn (Request $request) => Limit::perMinute(60)->by('u:' . ($request->user()?->id ?? $request->ip())));
    }
}
