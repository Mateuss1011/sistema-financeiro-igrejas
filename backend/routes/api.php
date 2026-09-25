<?php

use App\Http\Controllers\Api\V1\AjusteSaldoController;
use App\Http\Controllers\Api\V1\AuditoriaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TransferenciaController;
use App\Http\Controllers\Api\V1\ContaController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DespesaController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\EntradaController;
use App\Http\Controllers\Api\V1\PerfilController;
use App\Http\Controllers\Api\V1\PeriodoFinanceiroController;
use App\Http\Controllers\Api\V1\PermissaoExcecaoController;
use App\Http\Controllers\Api\V1\RelatorioController;
use App\Support\Relatorios\CatalogoRelatorios;
use App\Http\Controllers\Api\V1\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login-ip');

    Route::middleware(['auth:sanctum', 'usuario.ativo'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'usuario.ativo'])->group(function () {
    // Ids de rota só numéricos (rotas manuais: 1 a 18 dígitos, que cabem num int de 64 bits). Sem isto o MariaDB comparava `id = '8abc'` como 8 e resolvia o registro errado, e um id gigante estourava o tipo int do PHP (500).
    Route::apiResource('contas', ContaController::class)->except(['show'])->whereNumber('conta');
    Route::apiResource('categorias', CategoriaController::class)->except(['show'])->whereNumber('categoria');
    // Entradas são imutáveis: sem show/update/destroy. Correção só por estorno.
    Route::get('entradas', [EntradaController::class, 'index']);
    Route::post('entradas', [EntradaController::class, 'store']);
    Route::post('entradas/{entrada}/estornar', [EntradaController::class, 'estornar'])->where('entrada', '[0-9]{1,18}');
    // Despesas: PUT/DELETE só para Pendentes (regra no service); sem GET individual.
    Route::get('despesas', [DespesaController::class, 'index']);
    Route::post('despesas', [DespesaController::class, 'store']);
    Route::put('despesas/{despesa}', [DespesaController::class, 'update'])->where('despesa', '[0-9]{1,18}');
    Route::delete('despesas/{despesa}', [DespesaController::class, 'destroy'])->where('despesa', '[0-9]{1,18}');
    Route::post('despesas/{despesa}/pagar', [DespesaController::class, 'pagar'])->where('despesa', '[0-9]{1,18}');
    Route::post('despesas/{despesa}/cancelar', [DespesaController::class, 'cancelar'])->where('despesa', '[0-9]{1,18}');
    Route::post('despesas/{despesa}/estornar', [DespesaController::class, 'estornar'])->where('despesa', '[0-9]{1,18}');
    // Transferências e ajustes são imutáveis: sem PUT/DELETE/GET individual. Correção só por estorno
    // (transferência) ou por outro ajuste de sentido oposto.
    Route::get('transferencias', [TransferenciaController::class, 'index']);
    Route::post('transferencias', [TransferenciaController::class, 'store']);
    Route::post('transferencias/{transferencia}/estornar', [TransferenciaController::class, 'estornar'])->where('transferencia', '[0-9]{1,18}');
    Route::get('ajustes', [AjusteSaldoController::class, 'index']);
    Route::post('ajustes', [AjusteSaldoController::class, 'store']);
    // Fase 9: um período só muda de estado via fechar/reabrir; sem GET individual/PUT/DELETE.
    Route::get('periodos-financeiros', [PeriodoFinanceiroController::class, 'index']);
    Route::post('periodos-financeiros/{ano_mes}/fechar', [PeriodoFinanceiroController::class, 'fechar'])
        ->where('ano_mes', '[0-9]{4}-(0[1-9]|1[0-2])');
    Route::post('periodos-financeiros/{ano_mes}/reabrir', [PeriodoFinanceiroController::class, 'reabrir'])
        ->where('ano_mes', '[0-9]{4}-(0[1-9]|1[0-2])');
    // Fase 12: relatórios (consulta) e exportação CSV/XLSX. Só GET; a exportação é sempre auditada. `{relatorio}` e
    // `{formato}` são restritos por regex (valor desconhecido = 404). Auxiliar não exporta; Secretário não acessa.
    Route::get('relatorios', [RelatorioController::class, 'index']);
    Route::get('relatorios/{relatorio}', [RelatorioController::class, 'show'])
        ->where('relatorio', implode('|', CatalogoRelatorios::slugs()))
        ->middleware('throttle:consultas-pesadas');
    Route::get('relatorios/{relatorio}/exportar/{formato}', [RelatorioController::class, 'exportar'])
        ->where('relatorio', implode('|', CatalogoRelatorios::slugs()))
        ->where('formato', implode('|', CatalogoRelatorios::FORMATOS))
        ->middleware('throttle:exportacoes');
    // Fase 11: dashboard é somente consulta (um único GET, filtro opcional ?ano_mes=AAAA-MM).
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('throttle:consultas-pesadas');
    // Fase 10: auditoria é somente consulta. Nenhuma rota de escrita, e nenhum GET individual.
    Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('throttle:consultas-pesadas');
    Route::get('auditoria/catalogo', [AuditoriaController::class, 'catalogo']);
    Route::get('perfis',[PerfilController::class, 'index']);
    Route::get('permissoes-excecao', [PermissaoExcecaoController::class, 'catalogo']);
    Route::apiResource('usuarios', UsuarioController::class)->except(['show'])->whereNumber('usuario');
    Route::post('usuarios/{usuario}/permissoes-excecao', [PermissaoExcecaoController::class, 'store'])->whereNumber('usuario');
    Route::delete('usuarios/{usuario}/permissoes-excecao/{permissao}', [PermissaoExcecaoController::class, 'destroy'])->whereNumber('usuario');
});
