<?php

namespace App\Services;

use App\Exceptions\ExportacaoMuitoGrandeException;
use App\Models\AjusteSaldo;
use App\Models\Transferencia;
use App\Models\User;
use App\Support\Relatorios\CatalogoRelatorios;
use App\Support\Relatorios\RelatorioResultado;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fase 12: monta os relatórios (Resumo, Entradas, Despesas, Movimentação e Saldos) — somente leitura.
 *
 * NENHUM SQL financeiro próprio: todo predicado (entradas em vigor, despesas pagas/pendentes, transferências, ajustes,
 * saldos, período) vem de IndicadoresFinanceirosService, a MESMA fonte do Dashboard. Aqui só há joins de exibição,
 * filtros opcionais, ordenação e paginação sobre esses builders. Lista, totais, CSV e XLSX saem do mesmo resultado.
 *
 * Escopo: o Auxiliar só enxerga o que criou (mesmo `viewAll` do Dashboard); transferências e ajustes só entram na
 * Movimentação para quem já os enxerga pelas Policies existentes (Auxiliar nunca); "Saldos por conta" é negado ao
 * Auxiliar na RelatorioPolicy.
 *
 * Todas as leituras de um relatório rodam numa transação (snapshot REPEATABLE READ): linhas, total e contagem são
 * coerentes entre si mesmo com lançamentos entrando no meio da consulta.
 */
class RelatorioService
{
    private const STATUS_ENTRADA = ['confirmada' => 'Confirmada', 'estornada' => 'Estornada'];
    private const STATUS_DESPESA = ['pendente' => 'Pendente', 'paga' => 'Paga', 'estornada' => 'Estornada', 'cancelada' => 'Cancelada'];
    private const TIPO_CONTA = ['banco' => 'Banco', 'caixa' => 'Caixa'];
    private const TIPO_MOVIMENTACAO = [
        'entrada' => 'Entrada',
        'despesa_paga' => 'Despesa paga',
        'transferencia_recebida' => 'Transferência recebida',
        'transferencia_enviada' => 'Transferência enviada',
        'ajuste_credito' => 'Ajuste (crédito)',
        'ajuste_debito' => 'Ajuste (débito)',
    ];

    public function __construct(private IndicadoresFinanceirosService $indicadores)
    {
    }

    /**
     * @param  array<string, mixed>  $filtros  já validados: `ano_mes` (obrigatório aqui) e, conforme o relatório,
     *                                          `conta_id`, `categoria_id`, `status`, `ordenar`
     * @param  ?int  $porPagina  paginação da tela; `null` = TODAS as linhas (exportação), respeitando `$limite`
     * @param  ?int  $limite  máximo de linhas quando `$porPagina` é null (acima disso: ExportacaoMuitoGrandeException)
     */
    public function consultar(User $ator, string $relatorio, array $filtros, ?int $porPagina = null, int $pagina = 1, ?int $limite = null): RelatorioResultado
    {
        return DB::transaction(fn () => match ($relatorio) {
            'resumo' => $this->resumo($ator, $filtros),
            'entradas' => $this->entradas($ator, $filtros, $porPagina, $pagina, $limite),
            'despesas' => $this->despesas($ator, $filtros, $porPagina, $pagina, $limite),
            'movimentacoes' => $this->movimentacoes($ator, $filtros, $porPagina, $pagina, $limite),
            'saldos' => $this->saldos($ator, $filtros, $porPagina, $pagina),
        });
    }

    // ================================================================== RESUMO

    private function resumo(User $ator, array $filtros): RelatorioResultado
    {
        $anoMes = $filtros['ano_mes'];
        $ind = $this->indicadores->resumo($ator, $anoMes);

        $linha = fn (string $rotulo, mixed $valor, string $tipo) => ['indicador' => $rotulo, 'valor' => $valor, '_tipos' => ['valor' => $tipo]];

        $linhas = [
            $linha('Período', substr($anoMes, 5, 2) . '/' . substr($anoMes, 0, 4), 'texto'),
            $linha('Total de entradas', $ind['entradas']['total'], 'dinheiro'),
            $linha('Total de despesas pagas', $ind['despesas_pagas']['total'], 'dinheiro'),
            $linha('Total de despesas pendentes', $ind['despesas_pendentes']['valor'], 'dinheiro'),
            $linha('Quantidade de despesas pendentes', $ind['despesas_pendentes']['quantidade'], 'inteiro'),
        ];

        if (isset($ind['saldo'])) {
            $referencia = $ind['saldo']['referencia'] === 'atual' ? 'Atual' : Carbon::createFromFormat('Y-m-d', $ind['saldo']['referencia'])->format('d/m/Y');
            $linhas[] = $linha('Saldo total', $ind['saldo']['total'], 'dinheiro');
            $linhas[] = $linha('Data de referência do saldo', $referencia, 'texto');
            $linhas[] = $linha('Situação do período', $ind['periodo']['status'] === 'fechado' ? 'Fechado' : 'Aberto', 'texto');
            foreach ($ind['saldo']['contas'] as $conta) {
                $linhas[] = $linha('Saldo — ' . $conta['nome'] . ($conta['ativa'] ? '' : ' (inativa)'), $conta['saldo'], 'dinheiro');
            }
        }

        return new RelatorioResultado(
            relatorio: 'resumo',
            titulo: CatalogoRelatorios::nome('resumo'),
            anoMes: $anoMes,
            escopo: $ind['escopo'],
            colunas: [
                ['chave' => 'indicador', 'rotulo' => 'Indicador', 'tipo' => 'texto'],
                ['chave' => 'valor', 'rotulo' => 'Valor', 'tipo' => 'texto'],
            ],
            linhas: $linhas,
            totais: [],
            filtros: ['ano_mes' => $anoMes],
            indicadores: $ind,
        );
    }

    // ================================================================== ENTRADAS

    private function entradas(User $ator, array $filtros, ?int $porPagina, int $pagina, ?int $limite): RelatorioResultado
    {
        $anoMes = $filtros['ano_mes'];
        $escopo = $this->indicadores->escopoDe($ator);

        $base = $this->indicadores->entradasEmVigor($anoMes, $escopo['entradas'])
            ->when($filtros['conta_id'] ?? null, fn ($q, $v) => $q->where('entradas.conta_id', $v))
            ->when($filtros['categoria_id'] ?? null, fn ($q, $v) => $q->where('entradas.categoria_id', $v));

        $totais = $this->indicadores->quantidadeETotal($base);

        $lista = (clone $base)
            ->join('categorias', 'categorias.id', '=', 'entradas.categoria_id')
            ->join('contas', 'contas.id', '=', 'entradas.conta_id')
            ->join('users', 'users.id', '=', 'entradas.criado_por')
            ->select([
                'entradas.id', 'entradas.data_competencia', 'categorias.nome as categoria', 'contas.nome as conta',
                'contas.tipo as conta_tipo', 'entradas.descricao', 'entradas.valor', 'entradas.status',
                'users.name as criado_por', 'entradas.created_at',
            ]);
        $this->ordenar($lista, $filtros['ordenar'] ?? CatalogoRelatorios::ordenarPadrao('entradas'), [
            'data_competencia' => 'entradas.data_competencia', 'valor' => 'entradas.valor',
            'created_at' => 'entradas.created_at', 'id' => 'entradas.id',
        ], 'entradas.id');

        [$itens, $paginacao] = $this->coletar($lista, $porPagina, $pagina, $limite);

        $linhas = array_map(fn ($e) => [
            'id' => (int) $e->id,
            'data_competencia' => $e->data_competencia,
            'categoria' => $e->categoria,
            'conta' => $e->conta,
            'tipo' => self::TIPO_CONTA[$e->conta_tipo] ?? $e->conta_tipo,
            'descricao' => $e->descricao,
            'valor' => $this->dinheiro($e->valor),
            'status' => self::STATUS_ENTRADA[$e->status] ?? $e->status,
            'criado_por' => $e->criado_por,
            'criado_em' => $this->instante($e->created_at),
        ], $itens);

        return new RelatorioResultado(
            relatorio: 'entradas',
            titulo: CatalogoRelatorios::nome('entradas'),
            anoMes: $anoMes,
            escopo: $escopo['entradas'] === null ? 'todos' : 'proprios',
            colunas: [
                ['chave' => 'id', 'rotulo' => 'ID', 'tipo' => 'inteiro'],
                ['chave' => 'data_competencia', 'rotulo' => 'Data de competência', 'tipo' => 'data'],
                ['chave' => 'categoria', 'rotulo' => 'Categoria', 'tipo' => 'texto'],
                ['chave' => 'conta', 'rotulo' => 'Conta', 'tipo' => 'texto'],
                ['chave' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'texto'],
                ['chave' => 'descricao', 'rotulo' => 'Descrição', 'tipo' => 'texto'],
                ['chave' => 'valor', 'rotulo' => 'Valor', 'tipo' => 'dinheiro'],
                ['chave' => 'status', 'rotulo' => 'Status', 'tipo' => 'texto'],
                ['chave' => 'criado_por', 'rotulo' => 'Criado por', 'tipo' => 'texto'],
                ['chave' => 'criado_em', 'rotulo' => 'Data de criação', 'tipo' => 'datahora'],
            ],
            linhas: $linhas,
            totais: [
                $this->total('quantidade', 'Quantidade de entradas', 'inteiro', $totais['quantidade']),
                $this->total('total', 'Total de entradas', 'dinheiro', $totais['valor']),
            ],
            filtros: $this->filtrosAplicados('entradas', $filtros),
            paginacao: $paginacao,
        );
    }

    // ================================================================== DESPESAS

    private function despesas(User $ator, array $filtros, ?int $porPagina, int $pagina, ?int $limite): RelatorioResultado
    {
        $anoMes = $filtros['ano_mes'];
        $escopo = $this->indicadores->escopoDe($ator);
        $status = $filtros['status'] ?? null;

        $comFiltros = fn (Builder $q) => $q
            ->when($filtros['conta_id'] ?? null, fn ($w, $v) => $w->where('despesas.conta_id', $v))
            ->when($filtros['categoria_id'] ?? null, fn ($w, $v) => $w->where('despesas.categoria_id', $v))
            ->when($status !== null, fn ($w) => $w->where('despesas.status', $status));

        // Totais SEMPRE pelos builders dos indicadores (mesmo predicado do Dashboard). O filtro de status só zera o total
        // do status que ele exclui.
        $pagas = $this->indicadores->quantidadeETotal($comFiltros($this->indicadores->despesasPagasEmVigor($anoMes, $escopo['despesas'])));
        $pendentes = $this->indicadores->quantidadeETotal($comFiltros($this->indicadores->despesasPendentes($anoMes, $escopo['despesas'])));

        $lista = $comFiltros($this->indicadores->despesasDoMes($anoMes, $escopo['despesas']))
            ->join('categorias', 'categorias.id', '=', 'despesas.categoria_id')
            ->leftJoin('contas', 'contas.id', '=', 'despesas.conta_id')
            ->join('users', 'users.id', '=', 'despesas.criado_por')
            ->select([
                'despesas.id', 'despesas.data_competencia', 'despesas.data_pagamento', 'categorias.nome as categoria',
                'contas.nome as conta', 'despesas.fornecedor_nome', 'despesas.descricao', 'despesas.valor', 'despesas.status',
                'users.name as criado_por', 'despesas.created_at',
            ]);
        $this->ordenar($lista, $filtros['ordenar'] ?? CatalogoRelatorios::ordenarPadrao('despesas'), [
            'data_competencia' => 'despesas.data_competencia', 'data_pagamento' => 'despesas.data_pagamento',
            'valor' => 'despesas.valor', 'created_at' => 'despesas.created_at', 'id' => 'despesas.id',
        ], 'despesas.id');

        [$itens, $paginacao] = $this->coletar($lista, $porPagina, $pagina, $limite);

        $linhas = array_map(fn ($d) => [
            'id' => (int) $d->id,
            'data_competencia' => $d->data_competencia,
            'data_pagamento' => $d->data_pagamento,
            'categoria' => $d->categoria,
            'conta' => $d->conta,
            'fornecedor' => $d->fornecedor_nome,
            'descricao' => $d->descricao,
            'valor' => $this->dinheiro($d->valor),
            'status' => self::STATUS_DESPESA[$d->status] ?? $d->status,
            'criado_por' => $d->criado_por,
            'criado_em' => $this->instante($d->created_at),
        ], $itens);

        return new RelatorioResultado(
            relatorio: 'despesas',
            titulo: CatalogoRelatorios::nome('despesas'),
            anoMes: $anoMes,
            escopo: $escopo['despesas'] === null ? 'todos' : 'proprios',
            colunas: [
                ['chave' => 'id', 'rotulo' => 'ID', 'tipo' => 'inteiro'],
                ['chave' => 'data_competencia', 'rotulo' => 'Data de competência', 'tipo' => 'data'],
                ['chave' => 'data_pagamento', 'rotulo' => 'Data de pagamento', 'tipo' => 'data'],
                ['chave' => 'categoria', 'rotulo' => 'Categoria', 'tipo' => 'texto'],
                ['chave' => 'conta', 'rotulo' => 'Conta', 'tipo' => 'texto'],
                ['chave' => 'fornecedor', 'rotulo' => 'Fornecedor', 'tipo' => 'texto'],
                ['chave' => 'descricao', 'rotulo' => 'Descrição', 'tipo' => 'texto'],
                ['chave' => 'valor', 'rotulo' => 'Valor', 'tipo' => 'dinheiro'],
                ['chave' => 'status', 'rotulo' => 'Status', 'tipo' => 'texto'],
                ['chave' => 'criado_por', 'rotulo' => 'Criado por', 'tipo' => 'texto'],
                ['chave' => 'criado_em', 'rotulo' => 'Data de criação', 'tipo' => 'datahora'],
            ],
            linhas: $linhas,
            totais: [
                $this->total('pagas_quantidade', 'Despesas pagas — quantidade', 'inteiro', $pagas['quantidade']),
                $this->total('pagas_total', 'Despesas pagas — total', 'dinheiro', $pagas['valor']),
                $this->total('pendentes_quantidade', 'Despesas pendentes — quantidade', 'inteiro', $pendentes['quantidade']),
                $this->total('pendentes_total', 'Despesas pendentes — total', 'dinheiro', $pendentes['valor']),
            ],
            filtros: $this->filtrosAplicados('despesas', $filtros),
            paginacao: $paginacao,
        );
    }

    // ================================================================== MOVIMENTAÇÃO

    private function movimentacoes(User $ator, array $filtros, ?int $porPagina, int $pagina, ?int $limite): RelatorioResultado
    {
        $anoMes = $filtros['ano_mes'];
        $escopo = $this->indicadores->escopoDe($ator);
        $contaId = $filtros['conta_id'] ?? null;
        // Transferências e ajustes: só para quem já os enxerga (Policies existentes — Auxiliar nunca).
        $vaiTransferencias = $ator->can('viewAny', Transferencia::class);
        $vaiAjustes = $ator->can('viewAny', AjusteSaldo::class);

        $partes = [];

        $partes[] = $this->indicadores->entradasEmVigor($anoMes, $escopo['entradas'])
            ->join('contas', 'contas.id', '=', 'entradas.conta_id')
            ->join('users', 'users.id', '=', 'entradas.criado_por')
            ->when($contaId, fn ($q, $v) => $q->where('entradas.conta_id', $v))
            ->selectRaw("entradas.data_competencia AS data, 'entrada' AS tipo_codigo, entradas.conta_id AS conta_id,
                contas.nome AS conta_nome, entradas.descricao AS descricao, entradas.valor AS entrada, NULL AS saida,
                entradas.valor AS liquido, entradas.id AS referencia_id, users.name AS usuario_nome, 0 AS lado");

        $partes[] = $this->indicadores->despesasPagasEmVigor($anoMes, $escopo['despesas'])
            ->join('contas', 'contas.id', '=', 'despesas.conta_id')
            ->join('users', 'users.id', '=', 'despesas.criado_por')
            ->when($contaId, fn ($q, $v) => $q->where('despesas.conta_id', $v))
            ->selectRaw("despesas.data_pagamento AS data, 'despesa_paga' AS tipo_codigo, despesas.conta_id AS conta_id,
                contas.nome AS conta_nome, despesas.descricao AS descricao, NULL AS entrada, despesas.valor AS saida,
                (0 - despesas.valor) AS liquido, despesas.id AS referencia_id, users.name AS usuario_nome, 0 AS lado");

        if ($vaiTransferencias) {
            $descricaoTransferencia = "CONCAT(origem.nome, ' → ', destino.nome, COALESCE(CONCAT(' · ', transferencias.descricao), ''))";

            $partes[] = $this->indicadores->transferenciasEmVigor($anoMes)
                ->join('contas as origem', 'origem.id', '=', 'transferencias.conta_origem_id')
                ->join('contas as destino', 'destino.id', '=', 'transferencias.conta_destino_id')
                ->join('users', 'users.id', '=', 'transferencias.criado_por')
                ->when($contaId, fn ($q, $v) => $q->where('transferencias.conta_destino_id', $v))
                ->selectRaw("transferencias.data_transferencia AS data, 'transferencia_recebida' AS tipo_codigo,
                    transferencias.conta_destino_id AS conta_id, destino.nome AS conta_nome, {$descricaoTransferencia} AS descricao,
                    transferencias.valor AS entrada, NULL AS saida, transferencias.valor AS liquido,
                    transferencias.id AS referencia_id, users.name AS usuario_nome, 1 AS lado");

            $partes[] = $this->indicadores->transferenciasEmVigor($anoMes)
                ->join('contas as origem', 'origem.id', '=', 'transferencias.conta_origem_id')
                ->join('contas as destino', 'destino.id', '=', 'transferencias.conta_destino_id')
                ->join('users', 'users.id', '=', 'transferencias.criado_por')
                ->when($contaId, fn ($q, $v) => $q->where('transferencias.conta_origem_id', $v))
                ->selectRaw("transferencias.data_transferencia AS data, 'transferencia_enviada' AS tipo_codigo,
                    transferencias.conta_origem_id AS conta_id, origem.nome AS conta_nome, {$descricaoTransferencia} AS descricao,
                    NULL AS entrada, transferencias.valor AS saida, (0 - transferencias.valor) AS liquido,
                    transferencias.id AS referencia_id, users.name AS usuario_nome, 2 AS lado");
        }

        if ($vaiAjustes) {
            $partes[] = $this->indicadores->ajustesDoMes($anoMes)
                ->join('contas', 'contas.id', '=', 'ajustes_saldo.conta_id')
                ->join('users', 'users.id', '=', 'ajustes_saldo.criado_por')
                ->when($contaId, fn ($q, $v) => $q->where('ajustes_saldo.conta_id', $v))
                ->selectRaw("ajustes_saldo.data_ajuste AS data,
                    CASE WHEN ajustes_saldo.sentido = 'credito' THEN 'ajuste_credito' ELSE 'ajuste_debito' END AS tipo_codigo,
                    ajustes_saldo.conta_id AS conta_id, contas.nome AS conta_nome, ajustes_saldo.justificativa AS descricao,
                    CASE WHEN ajustes_saldo.sentido = 'credito' THEN ajustes_saldo.valor ELSE NULL END AS entrada,
                    CASE WHEN ajustes_saldo.sentido = 'debito' THEN ajustes_saldo.valor ELSE NULL END AS saida,
                    CASE WHEN ajustes_saldo.sentido = 'credito' THEN ajustes_saldo.valor ELSE (0 - ajustes_saldo.valor) END AS liquido,
                    ajustes_saldo.id AS referencia_id, users.name AS usuario_nome, 0 AS lado");
        }

        $uniao = array_shift($partes);
        foreach ($partes as $parte) {
            $uniao->unionAll($parte);
        }
        $movimentos = DB::query()->fromSub($uniao, 'm');

        $agregados = (clone $movimentos)
            ->selectRaw('tipo_codigo, COUNT(*) AS quantidade, COALESCE(SUM(entrada), 0) AS entrada, COALESCE(SUM(saida), 0) AS saida, COALESCE(SUM(liquido), 0) AS liquido')
            ->groupBy('tipo_codigo')
            ->get()
            ->keyBy('tipo_codigo');

        $soma = fn (string $tipo, string $campo) => $this->dinheiro($agregados[$tipo]->{$campo} ?? '0');
        $quantidade = (int) $agregados->sum('quantidade');
        $variacao = '0.00';
        foreach ($agregados as $agregado) {
            $variacao = bcadd($variacao, $this->dinheiro($agregado->liquido), 2);
        }

        $totais = [
            $this->total('quantidade', 'Quantidade de movimentações', 'inteiro', $quantidade),
            $this->total('total_entradas', 'Total de entradas', 'dinheiro', $soma('entrada', 'entrada')),
            $this->total('total_despesas_pagas', 'Total de despesas pagas', 'dinheiro', $soma('despesa_paga', 'saida')),
        ];
        if ($vaiTransferencias) {
            $totais[] = $this->total('transferencias_recebidas', 'Transferências recebidas (não é receita)', 'dinheiro', $soma('transferencia_recebida', 'entrada'));
            $totais[] = $this->total('transferencias_enviadas', 'Transferências enviadas (não é despesa)', 'dinheiro', $soma('transferencia_enviada', 'saida'));
        }
        if ($vaiAjustes) {
            $totais[] = $this->total('ajustes_credito', 'Ajustes de crédito', 'dinheiro', $soma('ajuste_credito', 'entrada'));
            $totais[] = $this->total('ajustes_debito', 'Ajustes de débito', 'dinheiro', $soma('ajuste_debito', 'saida'));
        }
        $totais[] = $this->total('variacao_liquida', 'Variação líquida do saldo', 'dinheiro', $variacao);

        $lista = clone $movimentos;
        $this->ordenar($lista, $filtros['ordenar'] ?? CatalogoRelatorios::ordenarPadrao('movimentacoes'), ['data' => 'data', 'referencia_id' => 'referencia_id'], 'referencia_id');
        $lista->orderBy('tipo_codigo')->orderBy('lado');

        [$itens, $paginacao] = $this->coletar($lista, $porPagina, $pagina, $limite);

        $linhas = array_map(fn ($m) => [
            'data' => $m->data,
            'tipo' => self::TIPO_MOVIMENTACAO[$m->tipo_codigo] ?? $m->tipo_codigo,
            'conta' => $m->conta_nome,
            'descricao' => $m->descricao,
            'entrada' => $m->entrada === null ? null : $this->dinheiro($m->entrada),
            'saida' => $m->saida === null ? null : $this->dinheiro($m->saida),
            'liquido' => $this->dinheiro($m->liquido),
            'referencia' => (int) $m->referencia_id,
            'usuario' => $m->usuario_nome,
        ], $itens);

        return new RelatorioResultado(
            relatorio: 'movimentacoes',
            titulo: CatalogoRelatorios::nome('movimentacoes'),
            anoMes: $anoMes,
            escopo: ($escopo['entradas'] === null && $escopo['despesas'] === null) ? 'todos' : 'proprios',
            colunas: [
                ['chave' => 'data', 'rotulo' => 'Data', 'tipo' => 'data'],
                ['chave' => 'tipo', 'rotulo' => 'Tipo de movimentação', 'tipo' => 'texto'],
                ['chave' => 'conta', 'rotulo' => 'Conta', 'tipo' => 'texto'],
                ['chave' => 'descricao', 'rotulo' => 'Descrição', 'tipo' => 'texto'],
                ['chave' => 'entrada', 'rotulo' => 'Entrada', 'tipo' => 'dinheiro'],
                ['chave' => 'saida', 'rotulo' => 'Saída', 'tipo' => 'dinheiro'],
                ['chave' => 'liquido', 'rotulo' => 'Valor líquido', 'tipo' => 'dinheiro'],
                ['chave' => 'referencia', 'rotulo' => 'Referência (ID)', 'tipo' => 'inteiro'],
                ['chave' => 'usuario', 'rotulo' => 'Usuário responsável', 'tipo' => 'texto'],
            ],
            linhas: $linhas,
            totais: $totais,
            filtros: $this->filtrosAplicados('movimentacoes', $filtros),
            paginacao: $paginacao,
        );
    }

    // ================================================================== SALDOS

    private function saldos(User $ator, array $filtros, ?int $porPagina, int $pagina): RelatorioResultado
    {
        $anoMes = $filtros['ano_mes'];
        $saldos = $this->indicadores->saldos($anoMes);

        $contas = $saldos['contas'];
        if (($filtros['conta_id'] ?? null) !== null) {
            $contas = array_values(array_filter($contas, fn ($c) => $c['id'] === (int) $filtros['conta_id']));
        }

        $ordenar = $filtros['ordenar'] ?? CatalogoRelatorios::ordenarPadrao('saldos');
        usort($contas, function ($a, $b) use ($ordenar) {
            foreach (explode(',', $ordenar) as $campo) {
                $desc = str_starts_with($campo, '-');
                $chave = ltrim($campo, '-');
                if ($chave === 'id') {
                    continue;
                }
                $cmp = $chave === 'saldo' ? bccomp($a['saldo'], $b['saldo'], 2) : strcmp((string) $a[$chave], (string) $b[$chave]);
                if ($cmp !== 0) {
                    return $desc ? -$cmp : $cmp;
                }
            }

            return $a['id'] <=> $b['id'];
        });

        $total = '0.00';
        foreach ($contas as $conta) {
            $total = bcadd($total, $conta['saldo'], 2);
        }

        $paginacao = null;
        if ($porPagina !== null) {
            $ultima = max(1, (int) ceil(count($contas) / $porPagina));
            $pagina = min($pagina, $ultima);
            $paginacao = ['current_page' => $pagina, 'last_page' => $ultima, 'per_page' => $porPagina, 'total' => count($contas)];
            $contas = array_slice($contas, ($pagina - 1) * $porPagina, $porPagina);
        }

        $linhas = array_map(fn ($c) => [
            'id' => $c['id'],
            'nome' => $c['nome'],
            'tipo' => self::TIPO_CONTA[$c['tipo']] ?? $c['tipo'],
            'ativa' => $c['ativa'] ? 'Ativa' : 'Inativa',
            'saldo' => $c['saldo'],
        ], $contas);

        return new RelatorioResultado(
            relatorio: 'saldos',
            titulo: CatalogoRelatorios::nome('saldos'),
            anoMes: $anoMes,
            escopo: 'todos',
            colunas: [
                ['chave' => 'id', 'rotulo' => 'ID', 'tipo' => 'inteiro'],
                ['chave' => 'nome', 'rotulo' => 'Conta', 'tipo' => 'texto'],
                ['chave' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'texto'],
                ['chave' => 'ativa', 'rotulo' => 'Situação', 'tipo' => 'texto'],
                ['chave' => 'saldo', 'rotulo' => 'Saldo', 'tipo' => 'dinheiro'],
            ],
            linhas: $linhas,
            totais: [$this->total('total', 'Saldo total', 'dinheiro', $total)],
            filtros: $this->filtrosAplicados('saldos', $filtros) + ['saldo_referencia' => $saldos['referencia']],
            paginacao: $paginacao,
        );
    }

    // ================================================================== apoio

    /**
     * Ordenação por campos permitidos (mapa campo => coluna); `id` desempata sempre, na direção do primeiro campo
     * (mesmo padrão das listagens do projeto).
     *
     * @param  array<string, string>  $mapa
     */
    private function ordenar($query, string $ordenar, array $mapa, string $colunaId): void
    {
        $campos = array_values(array_filter(explode(',', $ordenar), fn ($campo) => ltrim($campo, '-') !== 'id'));
        $direcaoPadrao = 'asc';

        foreach ($campos as $i => $campo) {
            $direcao = str_starts_with($campo, '-') ? 'desc' : 'asc';
            if ($i === 0) {
                $direcaoPadrao = $direcao;
            }
            if (isset($mapa[ltrim($campo, '-')])) {
                $query->orderBy($mapa[ltrim($campo, '-')], $direcao);
            }
        }

        $query->orderBy($colunaId, $direcaoPadrao);
    }

    /**
     * @return array{0: list<object>, 1: ?array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    private function coletar($query, ?int $porPagina, int $pagina, ?int $limite): array
    {
        if ($porPagina !== null) {
            $paginador = $query->paginate($porPagina, ['*'], 'page', $pagina);

            return [$paginador->items(), [
                'current_page' => $paginador->currentPage(),
                'last_page' => $paginador->lastPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
            ]];
        }

        $itens = $limite === null ? $query->get() : $query->limit($limite + 1)->get();
        if ($limite !== null && $itens->count() > $limite) {
            throw new ExportacaoMuitoGrandeException($limite);
        }

        return [$itens->all(), null];
    }

    /** @return array<string, mixed> filtros aplicados a ESTE relatório (sem nulos; sem `ordenar`) */
    private function filtrosAplicados(string $relatorio, array $filtros): array
    {
        $aplicados = ['ano_mes' => $filtros['ano_mes']];
        foreach (CatalogoRelatorios::filtros($relatorio) as $filtro) {
            if (($filtros[$filtro] ?? null) !== null) {
                $aplicados[$filtro] = $filtros[$filtro];
            }
        }

        return $aplicados;
    }

    /** @return array{chave: string, rotulo: string, tipo: string, valor: mixed} */
    private function total(string $chave, string $rotulo, string $tipo, mixed $valor): array
    {
        return ['chave' => $chave, 'rotulo' => $rotulo, 'tipo' => $tipo, 'valor' => $valor];
    }

    private function dinheiro(mixed $valor): string
    {
        return bcadd((string) $valor, '0', 2);
    }

    /** created_at vem em UTC do banco: devolve ISO-8601 UTC (a tela/arquivo convertem para America/Sao_Paulo). */
    private function instante(?string $valor): ?string
    {
        return $valor === null ? null : Carbon::parse($valor, 'UTC')->toIso8601ZuluString();
    }
}
