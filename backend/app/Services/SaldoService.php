<?php

namespace App\Services;

use App\Models\Conta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fonte ÚNICA do cálculo de saldo. O saldo atual nunca é persistido.
 *
 * Fórmula final (Fases 6–8):
 *   saldo_inicial + Σ entradas − Σ estornos de entradas − Σ despesas pagas + Σ estornos de despesas
 *   + Σ transferências recebidas − Σ transferências enviadas + Σ ajustes de crédito − Σ ajustes de débito
 *
 * Entradas e estornos vivem na mesma tabela: a linha original soma; a linha de estorno
 * (entrada_estornada_id preenchido, valor positivo) subtrai. O cálculo NÃO depende da
 * coluna `status`, então original + estorno resultam sempre em efeito líquido zero.
 * Despesas: só linhas com `conta_id` movimentam saldo (os CHECKs da tabela garantem que Pendente e
 * Cancelada nunca têm conta). A original paga subtrai; a linha de estorno (despesa_estornada_id
 * preenchido, valor positivo) soma de volta — também sem depender de `status`.
 * Transferências: TODA linha de `transferencias` (original ou estorno) soma na conta de destino e
 * subtrai na de origem — o estorno é uma transferência inversa, então não há CASE nem dependência de
 * `status`, e o efeito líquido nas duas contas é sempre zero (o saldo consolidado não muda).
 * Ajustes: crédito soma, débito subtrai (valor sempre positivo + `sentido`).
 * Valores são strings decimais com 2 casas; aritmética somente com BCMath.
 */
class SaldoService
{
    public function saldoAtual(Conta $conta): string
    {
        return $this->saldosAtuais([$conta])[$conta->id];
    }

    /**
     * Saldo de várias contas com UMA consulta agregada (evita N+1 nas listagens).
     *
     * @param  iterable<Conta>  $contas
     * @return array<int, string> id da conta => saldo atual
     */
    public function saldosAtuais(iterable $contas): array
    {
        $contas = collect($contas)->keyBy('id');

        if ($contas->isEmpty()) {
            return [];
        }

        $liquidoEntradas = DB::table('entradas')
            ->whereIn('conta_id', $contas->keys()->all())
            ->groupBy('conta_id')
            ->selectRaw('conta_id, SUM(CASE WHEN entrada_estornada_id IS NULL THEN valor ELSE -valor END) AS liquido')
            ->pluck('liquido', 'conta_id');

        $liquidoDespesas = DB::table('despesas')
            ->whereIn('conta_id', $contas->keys()->all())
            ->groupBy('conta_id')
            ->selectRaw('conta_id, SUM(CASE WHEN despesa_estornada_id IS NULL THEN -valor ELSE valor END) AS liquido')
            ->pluck('liquido', 'conta_id');

        $ids = $contas->keys()->all();

        // Transferências: lado destino (+) e lado origem (−) em UMA consulta (UNION ALL).
        $liquidoTransferencias = [];
        $linhasTransferencias = DB::table('transferencias')
            ->selectRaw('conta_destino_id AS conta_id, SUM(valor) AS liquido')
            ->whereIn('conta_destino_id', $ids)
            ->groupBy('conta_destino_id')
            ->unionAll(
                DB::table('transferencias')
                    ->selectRaw('conta_origem_id AS conta_id, -SUM(valor) AS liquido')
                    ->whereIn('conta_origem_id', $ids)
                    ->groupBy('conta_origem_id')
            )
            ->get();
        foreach ($linhasTransferencias as $linha) {
            $liquidoTransferencias[$linha->conta_id] = bcadd($liquidoTransferencias[$linha->conta_id] ?? '0', $this->normalizar($linha->liquido), 2);
        }

        $liquidoAjustes = DB::table('ajustes_saldo')
            ->whereIn('conta_id', $ids)
            ->groupBy('conta_id')
            ->selectRaw("conta_id, SUM(CASE WHEN sentido = 'credito' THEN valor ELSE -valor END) AS liquido")
            ->pluck('liquido', 'conta_id');

        return $contas->map(function (Conta $conta) use ($liquidoEntradas, $liquidoDespesas, $liquidoTransferencias, $liquidoAjustes) {
            $saldo = $this->normalizar($conta->saldo_inicial);
            $saldo = bcadd($saldo, $this->normalizar($liquidoEntradas[$conta->id] ?? '0'), 2);

            $saldo = bcadd($saldo, $this->normalizar($liquidoDespesas[$conta->id] ?? '0'), 2);

            $saldo = bcadd($saldo, $this->normalizar($liquidoTransferencias[$conta->id] ?? '0'), 2);
            $saldo = bcadd($saldo, $this->normalizar($liquidoAjustes[$conta->id] ?? '0'), 2);

            return $saldo;
        })->all();
    }

    public function saldoAte(Conta $conta, CarbonInterface|string $data): string
    {
        return $this->saldosAte([$conta], $data)[$conta->id];
    }

    /**
     * Fase 9: saldo histórico até uma data de corte (inclusive), sem persistir nada.
     * Mesma fórmula de `saldosAtuais()`, com um `<= data` em cada soma — mas a coluna de data usada
     * NÃO é a mesma em todo mundo: é a data em que o dinheiro realmente se move.
     *   - entradas: `data_competencia` (é a própria data do recebimento);
     *   - despesas: `data_pagamento`, NÃO `data_competencia` — o dinheiro só sai da conta quando é
     *     paga (só despesas pagas/estornadas têm `conta_id`; Pendente/Cancelada nunca entram na
     *     soma, igual a `saldosAtuais()`, porque `whereIn('conta_id', ...)` já as exclui);
     *   - transferências: `data_transferencia`;
     *   - ajustes: `data_ajuste`.
     * Implementação separada de `saldosAtuais()` (não compartilhada) para não arriscar o cálculo do
     * saldo atual, já coberto pela regressão das Fases 6–8. `saldoAte(conta, hoje) === saldoAtual(conta)`
     * é uma propriedade verificada em teste, não presumida.
     *
     * @param  iterable<Conta>  $contas
     * @return array<int, string> id da conta => saldo até a data
     */
    public function saldosAte(iterable $contas, CarbonInterface|string $data): array
    {
        $contas = collect($contas)->keyBy('id');

        if ($contas->isEmpty()) {
            return [];
        }

        $dataCorte = $data instanceof CarbonInterface ? $data->format('Y-m-d') : $data;
        $ids = $contas->keys()->all();

        $liquidoEntradas = DB::table('entradas')
            ->whereIn('conta_id', $ids)
            ->where('data_competencia', '<=', $dataCorte)
            ->groupBy('conta_id')
            ->selectRaw('conta_id, SUM(CASE WHEN entrada_estornada_id IS NULL THEN valor ELSE -valor END) AS liquido')
            ->pluck('liquido', 'conta_id');

        $liquidoDespesas = DB::table('despesas')
            ->whereIn('conta_id', $ids)
            ->where('data_pagamento', '<=', $dataCorte)
            ->groupBy('conta_id')
            ->selectRaw('conta_id, SUM(CASE WHEN despesa_estornada_id IS NULL THEN -valor ELSE valor END) AS liquido')
            ->pluck('liquido', 'conta_id');

        $liquidoTransferencias = [];
        $linhasTransferencias = DB::table('transferencias')
            ->selectRaw('conta_destino_id AS conta_id, SUM(valor) AS liquido')
            ->whereIn('conta_destino_id', $ids)
            ->where('data_transferencia', '<=', $dataCorte)
            ->groupBy('conta_destino_id')
            ->unionAll(
                DB::table('transferencias')
                    ->selectRaw('conta_origem_id AS conta_id, -SUM(valor) AS liquido')
                    ->whereIn('conta_origem_id', $ids)
                    ->where('data_transferencia', '<=', $dataCorte)
                    ->groupBy('conta_origem_id')
            )
            ->get();
        foreach ($linhasTransferencias as $linha) {
            $liquidoTransferencias[$linha->conta_id] = bcadd($liquidoTransferencias[$linha->conta_id] ?? '0', $this->normalizar($linha->liquido), 2);
        }

        $liquidoAjustes = DB::table('ajustes_saldo')
            ->whereIn('conta_id', $ids)
            ->where('data_ajuste', '<=', $dataCorte)
            ->groupBy('conta_id')
            ->selectRaw("conta_id, SUM(CASE WHEN sentido = 'credito' THEN valor ELSE -valor END) AS liquido")
            ->pluck('liquido', 'conta_id');

        return $contas->map(function (Conta $conta) use ($liquidoEntradas, $liquidoDespesas, $liquidoTransferencias, $liquidoAjustes) {
            $saldo = $this->normalizar($conta->saldo_inicial);
            $saldo = bcadd($saldo, $this->normalizar($liquidoEntradas[$conta->id] ?? '0'), 2);
            $saldo = bcadd($saldo, $this->normalizar($liquidoDespesas[$conta->id] ?? '0'), 2);
            $saldo = bcadd($saldo, $this->normalizar($liquidoTransferencias[$conta->id] ?? '0'), 2);
            $saldo = bcadd($saldo, $this->normalizar($liquidoAjustes[$conta->id] ?? '0'), 2);

            return $saldo;
        })->all();
    }

    /** Calcula o saldo de todas as contas informadas e o anexa (só em memória) para o ContaResource. */
    public function anexarSaldos(iterable $contas): void
    {
        $contas = collect($contas);
        $saldos = $this->saldosAtuais($contas);

        foreach ($contas as $conta) {
            $conta->saldoCalculado = $saldos[$conta->id];
        }
    }

    /**
     * Trava a linha da conta (SELECT ... FOR UPDATE). Toda operação que valida saldo ou
     * estado da conta deve chamar isto dentro da transação, ANTES de ler o saldo.
     * Retorna null se a conta não existir (ou tiver sido excluída).
     */
    public function travarConta(int $contaId): ?Conta
    {
        return Conta::query()->whereKey($contaId)->lockForUpdate()->first();
    }

    /**
     * Trava VÁRIAS contas em ORDEM CRESCENTE de id (SELECT ... WHERE id IN (...) ORDER BY id FOR UPDATE).
     * A ordem determinística evita deadlock entre transferências cruzadas (A→B e B→A travam A e depois B).
     * Devolve as contas indexadas por id, na ordem travada; contas inexistentes/excluídas não aparecem.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, Conta>
     */
    public function travarContas(array $ids): \Illuminate\Support\Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return Conta::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function normalizar(string|int|float|null $valor): string
    {
        return bcadd((string) ($valor ?? '0'), '0', 2);
    }
}
