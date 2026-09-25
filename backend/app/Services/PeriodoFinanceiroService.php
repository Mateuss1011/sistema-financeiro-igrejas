<?php

namespace App\Services;

use App\Enums\StatusPeriodo;
use App\Exceptions\RegraNegocioException;
use App\Models\PeriodoFinanceiro;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase 9: consulta, fecha e reabre períodos. O período de um lançamento é derivado da sua data
 * relevante; período sem linha na tabela é considerado ABERTO.
 *
 * CONCORRÊNCIA (crítico): até a Fase 8, `garantirAberto()` fazia uma leitura COMUM (sem trava) —
 * seguro enquanto nada mais escrevia em `periodos_financeiros`. A partir da Fase 9, fechar/reabrir
 * escrevem nessa tabela, então a checagem de período precisa disputar a MESMA linha que o
 * fechamento trava, ou um lançamento pode ser commitado depois que o mês já foi fechado.
 *
 * Estratégia leitor/escritor na linha do período (`ano_mes` é UNIQUE):
 *   - toda operação financeira que precisa que o período esteja aberto trava a linha com
 *     LOCK IN SHARE MODE (`sharedLock()`): várias operações no mesmo mês aberto nunca se
 *     bloqueiam entre si;
 *   - fechar/reabrir travam a linha com FOR UPDATE (`lockForUpdate()`, exclusivo): esperam
 *     qualquer leitura compartilhada em voo terminar de commitar, e qualquer leitura
 *     compartilhada que comece depois do commit já vê o status novo.
 *   - Mesmo quando a linha ainda não existe (mês nunca fechado), o InnoDB do MariaDB, em
 *     REPEATABLE READ, toma um gap lock no índice único `ano_mes` ao travar uma busca sem
 *     resultado — o que também serializa um `SELECT ... FOR UPDATE` de fechamento contra um
 *     `SELECT ... LOCK IN SHARE MODE` de lançamento no mesmo mês nunca tocado. Comportamento
 *     comprovado pelos testes de concorrência reais desta fase (não é só suposição).
 *   - Nem `garantirAberto()`/`garantirAbertoTodos()` nem fechar/reabrir tocam linha de conta,
 *     documento ou categoria — cada transação de fechamento/reabertura trava UM único recurso
 *     (a linha do período), então nunca pode fazer parte de um ciclo de deadlock.
 */
class PeriodoFinanceiroService
{
    public function __construct(private AuditoriaService $auditoria)
    {
    }

    public function estaFechado(CarbonInterface|string $dataCompetencia): bool
    {
        return PeriodoFinanceiro::query()
            ->where('ano_mes', $this->anoMesDe($dataCompetencia))
            ->where('status', StatusPeriodo::Fechado->value)
            ->exists();
    }

    /** Lança 409 PERIODO_FECHADO se o período da data estiver fechado (período sem linha = aberto). */
    public function garantirAberto(CarbonInterface|string $data): void
    {
        $this->garantirAbertoTodos([$data]);
    }

    /**
     * Mesma regra de `garantirAberto()`, mas para VÁRIAS datas checadas na MESMA transação (ex.:
     * despesa que valida competência e pagamento juntos). Os `ano_mes` distintos são travados em
     * ORDEM CRESCENTE (texto 'YYYY-MM' ordena corretamente) para nunca cruzar com outra transação
     * que também precise de dois períodos — mesmo princípio da ordem crescente de id das contas
     * usada nas transferências (Fase 8).
     *
     * @param  list<CarbonInterface|string>  $datas
     */
    public function garantirAbertoTodos(array $datas): void
    {
        $anosMeses = collect($datas)->map(fn ($data) => $this->anoMesDe($data))->unique()->sort()->values();

        foreach ($anosMeses as $anoMes) {
            $periodo = PeriodoFinanceiro::query()->where('ano_mes', $anoMes)->sharedLock()->first();

            if ($periodo !== null && $periodo->status === StatusPeriodo::Fechado) {
                throw new RegraNegocioException('O período financeiro desta data está fechado.', 'PERIODO_FECHADO');
            }
        }
    }

    /**
     * Fecha o período. Cria a linha se ainda não existir (primeiro fechamento daquele mês) ou
     * atualiza a linha existente (reabertura anterior é sempre limpa, como o CHECK do banco exige).
     * Sem justificativa (decisão de negócio da Fase 9: só a reabertura exige).
     */
    public function fechar(string $anoMes, User $ator): PeriodoFinanceiro
    {
        // 3 tentativas: o PRIMEIRO fechamento de um `ano_mes` nunca tocado é um SELECT...FOR UPDATE
        // sem linha (trava de GAP) seguido de INSERT. Gaps são compatíveis entre transações
        // diferentes — então dois fechamentos concorrentes do MESMO mês nunca fechado podem os
        // dois tomar o gap e só then disputar o INSERT, e o MariaDB resolve isso com um deadlock
        // real (SQLSTATE 40001) em vez de simplesmente serializar. `$attempts` faz o Laravel
        // re-executar a transação da vítima automaticamente; na segunda tentativa a linha já existe
        // (criada pela transação vencedora) e o SELECT...FOR UPDATE normal a encontra, devolvendo
        // corretamente PERIODO_JA_FECHADO. Comprovado com o teste de concorrência real desta fase
        // (sem o retry, ~3 em cada 4 execuções repetidas do cenário "dois fechamentos simultâneos"
        // falhavam com QueryException em vez de resolver limpo).
        return DB::transaction(function () use ($anoMes, $ator) {
            $periodo = PeriodoFinanceiro::query()->where('ano_mes', $anoMes)->lockForUpdate()->first();

            if ($periodo !== null && $periodo->status === StatusPeriodo::Fechado) {
                throw new RegraNegocioException('Este período já está fechado.', 'PERIODO_JA_FECHADO');
            }

            if ($periodo === null) {
                $periodo = PeriodoFinanceiro::create([
                    'ano_mes' => $anoMes,
                    'status' => StatusPeriodo::Fechado->value,
                    'fechado_por' => $ator->id,
                    'fechado_em' => now(),
                ]);
            } else {
                $periodo->fill([
                    'status' => StatusPeriodo::Fechado->value,
                    'fechado_por' => $ator->id,
                    'fechado_em' => now(),
                    // O CHECK do banco exige estes três NULL quando status='fechado'.
                    'reaberto_por' => null,
                    'reaberto_em' => null,
                    'justificativa_reabertura' => null,
                ]);
                $periodo->save();
            }
            $periodo->refresh();

            $this->auditoria->registrar(
                acao: 'closed',
                modulo: 'periodos_financeiros',
                registroId: $periodo->id,
                dadosAnteriores: ['status' => StatusPeriodo::Aberto->value],
                dadosNovos: ['ano_mes' => $periodo->ano_mes, 'status' => StatusPeriodo::Fechado->value],
                usuario: $ator,
            );

            return $periodo;
        }, 3);
    }

    /** Reabre o período (só quem chama a Policy permitindo Pastor decide quem pode chegar aqui). */
    public function reabrir(string $anoMes, User $ator, string $justificativa): PeriodoFinanceiro
    {
        return DB::transaction(function () use ($anoMes, $ator, $justificativa) {
            $periodo = PeriodoFinanceiro::query()->where('ano_mes', $anoMes)->lockForUpdate()->first();

            if ($periodo === null || $periodo->status === StatusPeriodo::Aberto) {
                throw new RegraNegocioException('Este período já está aberto.', 'PERIODO_JA_ABERTO');
            }

            $periodo->fill([
                'status' => StatusPeriodo::Aberto->value,
                'reaberto_por' => $ator->id,
                'reaberto_em' => now(),
                'justificativa_reabertura' => $justificativa,
            ]);
            $periodo->save();
            $periodo->refresh();

            $this->auditoria->registrar(
                acao: 'reopened',
                modulo: 'periodos_financeiros',
                registroId: $periodo->id,
                dadosAnteriores: ['status' => StatusPeriodo::Fechado->value],
                dadosNovos: ['ano_mes' => $periodo->ano_mes, 'status' => StatusPeriodo::Aberto->value],
                justificativa: $justificativa,
                usuario: $ator,
            );

            return $periodo;
        });
    }

    /**
     * Todos os períodos conhecidos (linhas reais, mais recente primeiro) + o mês corrente, se
     * ele ainda não tiver linha (para o Pastor/Tesoureiro terem o que fechar sem digitar o mês
     * de cabeça). Não cria nenhuma linha no banco — o mês corrente sintético só existe em memória.
     */
    public function listarTodos(): Collection
    {
        $reais = PeriodoFinanceiro::query()->with(['fechadoPor', 'reabertoPor'])->get()->keyBy('ano_mes');
        $mesAtual = now('America/Sao_Paulo')->format('Y-m');

        if (! $reais->has($mesAtual)) {
            $reais->put($mesAtual, new PeriodoFinanceiro(['ano_mes' => $mesAtual, 'status' => StatusPeriodo::Aberto->value]));
        }

        return $reais->sortByDesc('ano_mes')->values();
    }

    private function anoMesDe(CarbonInterface|string $data): string
    {
        return $data instanceof CarbonInterface ? $data->format('Y-m') : substr($data, 0, 7);
    }
}
