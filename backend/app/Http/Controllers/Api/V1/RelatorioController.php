<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultarRelatorioRequest;
use App\Http\Requests\ExportarRelatorioRequest;
use App\Http\Resources\RelatorioResource;
use App\Models\Categoria;
use App\Models\Conta;
use App\Services\ExportacaoRelatorioService;
use App\Services\RelatorioService;
use App\Support\Relatorios\CatalogoRelatorios;
use Illuminate\Http\Request;

/**
 * Fase 12: só leitura + exportação. O controller apenas orquestra; toda regra financeira está no RelatorioService (que
 * consome a mesma fonte do Dashboard) e a auditoria da exportação, no ExportacaoRelatorioService.
 */
class RelatorioController extends Controller
{
    public function __construct(private RelatorioService $relatorios, private ExportacaoRelatorioService $exportacao)
    {
    }

    /** Catálogo dos relatórios que ESTE usuário pode ver + o que ele pode fazer (a tela nunca decide isso sozinha). */
    public function index(Request $request)
    {
        $this->authorize('viewAny', CatalogoRelatorios::class);
        $ator = $request->user();

        $relatorios = collect(CatalogoRelatorios::slugs())
            ->filter(fn (string $slug) => $ator->can('view', [CatalogoRelatorios::class, $slug]))
            ->map(fn (string $slug) => [
                'codigo' => $slug,
                'nome' => CatalogoRelatorios::nome($slug),
                'descricao' => CatalogoRelatorios::descricao($slug),
                'filtros' => CatalogoRelatorios::filtros($slug),
                'ordenacao' => CatalogoRelatorios::ordenacao($slug),
                'ordenar_padrao' => CatalogoRelatorios::ordenarPadrao($slug),
                'paginado' => CatalogoRelatorios::paginado($slug),
            ])
            ->values();

        $exportar = $ator->can('export', CatalogoRelatorios::class);

        return response()->json([
            'data' => $relatorios,
            'meta' => [
                'permissoes' => ['exportar' => $exportar],
                'formatos' => $exportar ? CatalogoRelatorios::FORMATOS : [],
                'status_despesa' => CatalogoRelatorios::statusDespesaRotulos(),
                'opcoes' => [
                    // Só id/nome/tipo para preencher os filtros — nenhum saldo sai por aqui.
                    'contas' => Conta::query()->orderBy('nome')->get(['id', 'nome', 'tipo', 'ativa'])
                        ->map(fn (Conta $c) => ['id' => $c->id, 'nome' => $c->nome, 'tipo' => $c->tipo->value, 'ativa' => $c->ativa])->all(),
                    'categorias' => Categoria::query()->orderBy('nome')->get(['id', 'nome', 'tipo'])
                        ->map(fn (Categoria $c) => ['id' => $c->id, 'nome' => $c->nome, 'tipo' => $c->tipo->value])->all(),
                ],
            ],
        ]);
    }

    public function show(ConsultarRelatorioRequest $request, string $relatorio)
    {
        $resultado = $this->relatorios->consultar(
            $request->user(),
            $relatorio,
            $request->filtros(),
            CatalogoRelatorios::paginado($relatorio) ? $request->porPagina() : null,
            $request->pagina(),
        );

        return (new RelatorioResource($resultado))->additional(['meta' => $resultado->meta()]);
    }

    public function exportar(ExportarRelatorioRequest $request, string $relatorio, string $formato)
    {
        $arquivo = $this->exportacao->exportar($request->user(), $relatorio, $formato, $request->filtros());

        return response($arquivo['conteudo'], 200, [
            'Content-Type' => $arquivo['tipo'],
            'Content-Disposition' => 'attachment; filename="' . $arquivo['nome'] . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
