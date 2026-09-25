<?php

namespace App\Services;

use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\User;
use App\Support\AnoMes;
use App\Support\Dashboard;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FONTE ÚNICA dos indicadores financeiros do mês (Fases 11 e 12). Dashboard, Relatórios (tela/API) e Exportações
 * (CSV/XLSX) consomem exatamente estes métodos — não existe segundo SQL financeiro em lugar nenhum. Somente leitura.
 *
 * Regras (aprovadas na Fase 11, mantidas):
 *  - entradas do mês: `data_competencia` dentro do mês, só originais em vigor (`confirmada`);
 *  - despesas pagas do mês: `data_pagamento` dentro do mês, só originais em vigor (`paga`);
 *  - despesas pendentes: `data_competencia` dentro do mês, status `pendente` (canceladas nunca entram);
 *  - saldos: SEMPRE via SaldoService (mês corrente = `saldosAtuais()`, mês anterior = `saldosAte()` no último dia);
 *  - situação do período: PeriodoFinanceiroService.
 *
 * "Originais em vigor" equivale ao líquido "originais − estornos" do SaldoService, porque toda linha de estorno copia
 * as datas da original (as duas caem no mesmo mês e se anulam) — e por isso o recorte do Auxiliar (que só enxerga o
 * que criou) continua correto mesmo quando o estorno foi criado por outra pessoa.
 *
 * Os métodos `entradasEmVigor()`, `despesasPagasEmVigor()` e `despesasPendentes()` devolvem o Builder BASE (com o
 * predicado financeiro já aplicado, colunas qualificadas por tabela). O Dashboard soma esse builder; os Relatórios
 * acrescentam joins/filtros/paginação sobre o MESMO builder — assim lista, total e Dashboard nunca divergem.
 */
class IndicadoresFinanceirosService
{
    public function __construct(private SaldoService $saldos, private PeriodoFinanceiroService $periodos)
    {
    }

    // ------------------------------------------------------------------ predicados (fonte única)

    /** Entradas originais em vigor do mês. `$criadoPor` = recorte do Auxiliar (só as que ele criou). */
    public function entradasEmVigor(string $anoMes, ?int $criadoPor = null): Builder
    {
        [$inicio, $fim] = AnoMes::limites($anoMes);

        return DB::table('entradas')
            ->whereNull('entradas.entrada_estornada_id')
            ->where('entradas.status', 'confirmada')
            ->whereBetween('entradas.data_competencia', [$inicio, $fim])
            ->when($criadoPor !== null, fn ($q) => $q->where('entradas.criado_por', $criadoPor));
    }

    /** Despesas pagas originais em vigor cujo PAGAMENTO caiu no mês (nunca a competência). */
    public function despesasPagasEmVigor(string $anoMes, ?int $criadoPor = null): Builder
    {
        return $this->aplicarPagas(DB::table('despesas'), $anoMes)
            ->when($criadoPor !== null, fn ($q) => $q->where('despesas.criado_por', $criadoPor));
    }

    /** Despesas Pendentes cuja COMPETÊNCIA está no mês (uma Pendente não tem data de pagamento). */
    public function despesasPendentes(string $anoMes, ?int $criadoPor = null): Builder
    {
        return $this->aplicarPendentes(DB::table('despesas'), $anoMes)
            ->when($criadoPor !== null, fn ($q) => $q->where('despesas.criado_por', $criadoPor));
    }

    /**
     * Despesas (originais, nunca linhas de estorno) que pertencem ao mês para LISTAGEM: pagas pelo pagamento,
     * pendentes e canceladas pela competência, estornadas pelo pagamento. Os dois primeiros usam exatamente os
     * mesmos predicados dos indicadores.
     */
    public function despesasDoMes(string $anoMes, ?int $criadoPor = null): Builder
    {
        [$inicio, $fim] = AnoMes::limites($anoMes);

        return DB::table('despesas')
            ->whereNull('despesas.despesa_estornada_id')
            ->where(function ($q) use ($anoMes, $inicio, $fim) {
                $q->where(fn ($w) => $this->aplicarPagas($w, $anoMes))
                    ->orWhere(fn ($w) => $this->aplicarPendentes($w, $anoMes))
                    ->orWhere(fn ($w) => $w->where('despesas.status', 'estornada')->whereBetween('despesas.data_pagamento', [$inicio, $fim]))
                    ->orWhere(fn ($w) => $w->where('despesas.status', 'cancelada')->whereBetween('despesas.data_competencia', [$inicio, $fim]));
            })
            ->when($criadoPor !== null, fn ($q) => $q->where('despesas.criado_por', $criadoPor));
    }

    /**
     * Transferências originais em vigor do mês (`data_transferencia`). Uma transferência estornada e a sua linha de estorno
     * (mesma data, sentido inverso) se anulam e ficam de fora — como nas entradas/despesas. Transferência NÃO é receita
     * nem despesa: nunca entra nos totais de entradas/despesas.
     */
    public function transferenciasEmVigor(string $anoMes): Builder
    {
        [$inicio, $fim] = AnoMes::limites($anoMes);

        return DB::table('transferencias')
            ->whereNull('transferencias.transferencia_estornada_id')
            ->where('transferencias.status', 'confirmada')
            ->whereBetween('transferencias.data_transferencia', [$inicio, $fim]);
    }

    /** Ajustes de saldo do mês (`data_ajuste`). Ajuste não tem estorno (corrige-se com outro ajuste de sentido oposto). */
    public function ajustesDoMes(string $anoMes): Builder
    {
        [$inicio, $fim] = AnoMes::limites($anoMes);

        return DB::table('ajustes_saldo')->whereBetween('ajustes_saldo.data_ajuste', [$inicio, $fim]);
    }

    private function aplicarPagas($query, string $anoMes)
    {
        [$inicio, $fim] = AnoMes::limites($anoMes);

        return $query->whereNull('despesas.despesa_estornada_id')
            ->where('despesas.status', 'paga')
            ->whereBetween('despesas.data_pagamento', [$inicio, $fim]);
    }

    private function aplicarPendentes($query, string $anoMes)
    {
        [$inicio, $fim] = AnoMes::limites($anoMes);

        return $query->where('despesas.status', 'pendente')
            ->whereBetween('despesas.data_competencia', [$inicio, $fim]);
    }

    // ------------------------------------------------------------------ agregados

    /** Soma de `valor` (string com 2 casas) do builder. */
    public function total(Builder $query, string $coluna = 'valor'): string
    {
        return bcadd((string) (clone $query)->selectRaw("COALESCE(SUM({$coluna}), 0) AS total")->value('total'), '0', 2);
    }

    /** @return array{quantidade: int, valor: string} */
    public function quantidadeETotal(Builder $query, string $coluna = 'valor'): array
    {
        $linha = (clone $query)->selectRaw("COUNT(*) AS quantidade, COALESCE(SUM({$coluna}), 0) AS valor")->first();

        return ['quantidade' => (int) $linha->quantidade, 'valor' => bcadd((string) $linha->valor, '0', 2)];
    }

    /**
     * Saldo total e por conta (todas as contas não excluídas, ativas e inativas: uma conta inativa ainda pode guardar
     * dinheiro). Mês corrente = saldo atual; mês anterior = saldo até o último dia do mês. A limitação da Fase 9
     * (o `saldo_inicial` de uma conta criada depois de um mês passado ainda entra) é mantida.
     *
     * @return array{referencia: string, total: string, contas: list<array<string, mixed>>}
     */
    public function saldos(string $anoMes): array
    {
        [, $fim] = AnoMes::limites($anoMes);
        $contas = Conta::query()->orderBy('tipo')->orderBy('nome')->orderBy('id')->get();
        $atual = $anoMes === AnoMes::corrente();

        $saldos = $atual ? $this->saldos->saldosAtuais($contas) : $this->saldos->saldosAte($contas, $fim);

        $total = '0.00';
        $linhas = [];
        foreach ($contas as $conta) {
            $saldo = $saldos[$conta->id];
            $total = bcadd($total, $saldo, 2);
            $linhas[] = [
                'id' => $conta->id,
                'nome' => $conta->nome,
                'tipo' => $conta->tipo->value,
                'ativa' => $conta->ativa,
                'saldo' => $saldo,
            ];
        }

        return ['referencia' => $atual ? 'atual' : $fim, 'total' => $total, 'contas' => $linhas];
    }

    /** 'aberto' ou 'fechado' (período sem linha = aberto). */
    public function situacaoDoPeriodo(string $anoMes): string
    {
        return $this->periodos->estaFechado($anoMes) ? 'fechado' : 'aberto';
    }

    // ------------------------------------------------------------------ resumo (Dashboard e Relatório "Resumo")

    /**
     * Escopo do ator: `null` = vê tudo; id = só o que ele criou. Reaproveita `viewAll` de EntradaPolicy/DespesaPolicy
     * (Auxiliar = só os próprios) — nenhuma regra nova.
     *
     * @return array{entradas: ?int, despesas: ?int}
     */
    public function escopoDe(User $ator): array
    {
        return [
            'entradas' => $ator->can('viewAll', Entrada::class) ? null : $ator->id,
            'despesas' => $ator->can('viewAll', Despesa::class) ? null : $ator->id,
        ];
    }

    /**
     * Todos os indicadores do mês, no formato do Dashboard (Fase 11). Leituras numa única transação: em REPEATABLE READ
     * (InnoDB) elas enxergam o mesmo snapshot, então os números são coerentes entre si.
     *
     * @return array<string, mixed>
     */
    public function resumo(User $ator, string $anoMes): array
    {
        $completo = $ator->can('viewCompleto', Dashboard::class);
        $escopo = $this->escopoDe($ator);

        return DB::transaction(function () use ($anoMes, $completo, $escopo) {
            $painel = [
                'ano_mes' => $anoMes,
                'visao' => $completo ? 'completo' : 'parcial',
                'escopo' => ($escopo['entradas'] === null && $escopo['despesas'] === null) ? 'todos' : 'proprios',
                'entradas' => ['total' => $this->total($this->entradasEmVigor($anoMes, $escopo['entradas']))],
                'despesas_pagas' => ['total' => $this->total($this->despesasPagasEmVigor($anoMes, $escopo['despesas']))],
                'despesas_pendentes' => $this->quantidadeETotal($this->despesasPendentes($anoMes, $escopo['despesas'])),
            ];

            if ($completo) {
                $painel['saldo'] = $this->saldos($anoMes);
                $painel['periodo'] = ['ano_mes' => $anoMes, 'status' => $this->situacaoDoPeriodo($anoMes)];
            }

            return $painel;
        });
    }
}
