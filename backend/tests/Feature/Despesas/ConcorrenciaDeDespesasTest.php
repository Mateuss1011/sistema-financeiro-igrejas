<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Services\SaldoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C10 — Concorrência REAL: cada worker é um processo PHP com conexão própria ao MariaDB.
 * Grava de verdade (sem RefreshDatabase) e limpa as tabelas ao final; só roda em sfg_testing.
 */
class ConcorrenciaDeDespesasTest extends TestCase
{
    use CenarioDespesas;

    private const TABELAS = ['despesas', 'entradas', 'periodos_financeiros', 'permissoes_excecao', 'audit_logs', 'contas', 'categorias', 'users'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sfg_testing', DB::getDatabaseName(), 'Teste de concorrência só roda no banco de testes.');
        $this->limpar();
    }

    protected function tearDown(): void
    {
        $this->limpar();
        parent::tearDown();
    }

    private function limpar(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABELAS as $tabela) {
            DB::table($tabela)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param  list<array<string, string|int>>  $jobs  cada job: ['acao' => ..., chaves do worker...]
     * @return list<array<string, mixed>>
     */
    private function dispararEmParalelo(array $jobs): array
    {
        $inicio = (int) (microtime(true) * 1000) + 3000; // folga para todos os processos subirem
        $script = base_path('tests/Support/despesas_worker.php');
        $ambiente = array_merge(getenv(), ['DB_DATABASE' => 'sfg_testing', 'APP_ENV' => 'testing']);
        $processos = [];

        foreach ($jobs as $i => $job) {
            $argumentos = [];
            foreach (($job + ['inicio' => $inicio]) as $k => $v) {
                $argumentos[] = "$k=$v";
            }
            $proc = proc_open([PHP_BINARY, $script, ...$argumentos], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);
            $processos[$i] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $resultados = [];
        foreach ($processos as $i => $p) {
            $saida = stream_get_contents($p['pipes'][1]);
            $erro = stream_get_contents($p['pipes'][2]);
            proc_close($p['proc']);
            $resultados[$i] = json_decode($saida, true) ?? ['ok' => false, 'code' => 'SAIDA_INVALIDA', 'detalhe' => $saida . $erro];
        }

        return $resultados;
    }

    private function contar(array $resultados, bool $ok, ?string $code = null): int
    {
        return count(array_filter($resultados, fn ($r) => $r['ok'] === $ok && ($code === null || ($r['code'] ?? null) === $code)));
    }

    private function saldo($conta): string
    {
        return app(SaldoService::class)->saldoAtual($conta->fresh());
    }

    private function semErrosInesperados(array $resultados): void
    {
        $this->assertSame(0, $this->contar($resultados, false, 'ERRO_INESPERADO'), json_encode($resultados));
        $this->assertSame(0, $this->contar($resultados, false, 'SAIDA_INVALIDA'), json_encode($resultados));
    }

    // 1) duas (ou mais) despesas concorrentes no caixa com saldo para somente uma
    public function test_pagamentos_concorrentes_no_caixa_com_saldo_para_uma_so_despesa(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Conc', 'caixa', '100.00');
        $categoria = $this->categoriaDespesa();
        $jobs = [];
        foreach (range(1, 5) as $i) {
            $d = $this->despesaPendente($categoria, $tes, ['valor' => '60.00']);
            $jobs[] = ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $caixa->id, 'data' => $this->hoje()];
        }

        $resultados = $this->dispararEmParalelo($jobs);

        $this->semErrosInesperados($resultados);
        $this->assertSame(1, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(4, $this->contar($resultados, false, 'SALDO_INSUFICIENTE'), json_encode($resultados));
        $this->assertSame('40.00', $this->saldo($caixa));
        $this->assertSame(1, Despesa::where('status', 'paga')->count());
        $this->assertSame(4, Despesa::where('status', 'pendente')->whereNull('conta_id')->count());
    }

    // 2) duas despesas concorrentes em banco
    public function test_pagamentos_concorrentes_no_banco_sem_e_com_confirmacao(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        // Sem confirmação: saldo 100, cinco despesas de 60 → uma paga (fica 40); as demais exigiriam ficar negativas.
        $banco = $this->conta('Banco Conc', 'banco', '100.00');
        $jobs = [];
        foreach (range(1, 5) as $i) {
            $d = $this->despesaPendente($categoria, $tes, ['valor' => '60.00']);
            $jobs[] = ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $banco->id, 'data' => $this->hoje()];
        }
        $resultados = $this->dispararEmParalelo($jobs);
        $this->semErrosInesperados($resultados);
        $this->assertSame(1, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(4, $this->contar($resultados, false, 'SALDO_NEGATIVO_REQUER_CONFIRMACAO'), json_encode($resultados));
        $this->assertSame('40.00', $this->saldo($banco));

        // Com confirmação explícita: todas pagam, serializadas, e o saldo final é exato.
        $banco2 = $this->conta('Banco Conc 2', 'banco', '0.00');
        $jobs = [];
        foreach (range(1, 4) as $i) {
            $d = $this->despesaPendente($categoria, $tes, ['valor' => '25.00']);
            $jobs[] = ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $banco2->id, 'data' => $this->hoje(), 'confirmar' => '1'];
        }
        $resultados = $this->dispararEmParalelo($jobs);
        $this->semErrosInesperados($resultados);
        $this->assertSame(4, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame('-100.00', $this->saldo($banco2));
    }

    // 3) estorno de entrada versus pagamento de despesa (o saldo só comporta um dos dois)
    public function test_estorno_de_entrada_versus_pagamento_de_despesa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $catEntrada = $this->categoria();

        foreach (['A', 'B', 'C'] as $rodada) {
            $caixa = $this->conta("Caixa Mix $rodada", 'caixa', '0.00');
            $entrada = $this->entrada($caixa, $catEntrada, $pastor, '100.00');
            $despesa = $this->despesaPendente($categoria, $pastor, ['valor' => '60.00']);

            $resultados = $this->dispararEmParalelo([
                ['acao' => 'estornar_entrada', 'entrada' => $entrada->id, 'user' => $pastor->id],
                ['acao' => 'pagar', 'despesa' => $despesa->id, 'user' => $pastor->id, 'conta' => $caixa->id, 'data' => $this->hoje()],
            ]);

            $this->semErrosInesperados($resultados);
            $this->assertSame(1, $this->contar($resultados, true), "rodada $rodada: " . json_encode($resultados));
            $this->assertSame(1, $this->contar($resultados, false, 'SALDO_INSUFICIENTE'), "rodada $rodada: " . json_encode($resultados));
            $this->assertTrue(bccomp($this->saldo($caixa), '0', 2) >= 0, "rodada $rodada: caixa ficou negativo");
        }
    }

    // 4) pagamento simultâneo da mesma despesa
    public function test_pagamento_simultaneo_da_mesma_despesa_mesmo_pagador_e_replay_e_debita_uma_vez(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Rp', 'banco', '1000.00');
        $d = $this->despesaPendente($this->categoriaDespesa(), $tes, ['valor' => '100.00']);

        $resultados = $this->dispararEmParalelo(array_fill(0, 5, ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $conta->id, 'data' => $this->hoje()]));

        $this->semErrosInesperados($resultados);
        $this->assertSame(5, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(1, count(array_filter($resultados, fn ($r) => $r['replay'] === false)), json_encode($resultados));
        $this->assertSame(4, count(array_filter($resultados, fn ($r) => $r['replay'] === true)), json_encode($resultados));
        $this->assertSame('900.00', $this->saldo($conta));
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'paid')->count());
    }

    public function test_pagamento_simultaneo_da_mesma_despesa_com_parametros_diferentes_so_um_vence(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Df', 'banco', '1000.00');
        $d = $this->despesaPendente($this->categoriaDespesa(), $tes, ['valor' => '100.00']);
        $jobs = [];
        foreach ([$tes, $pastor, $tes, $pastor, $tes] as $i => $ator) {
            $jobs[] = ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $ator->id, 'conta' => $conta->id, 'data' => '2026-01-0' . ($i + 1)]; // datas diferentes: nenhum é replay
        }

        $resultados = $this->dispararEmParalelo($jobs);

        $this->semErrosInesperados($resultados);
        $this->assertSame(1, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(4, $this->contar($resultados, false, 'DESPESA_NAO_PENDENTE'), json_encode($resultados));
        $this->assertSame('900.00', $this->saldo($conta));
    }

    // 5) estorno simultâneo
    public function test_estornos_simultaneos_da_mesma_despesa_geram_um_unico_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Es', 'banco', '1000.00');
        $paga = $this->despesaPaga($conta, $this->categoriaDespesa(), $pastor, ['valor' => '100.00']);

        $resultados = $this->dispararEmParalelo(array_fill(0, 6, ['acao' => 'estornar_despesa', 'despesa' => $paga->id, 'user' => $pastor->id]));

        $this->semErrosInesperados($resultados);
        $this->assertSame(1, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(5, $this->contar($resultados, false, 'DESPESA_JA_ESTORNADA'), json_encode($resultados));
        $this->assertSame(1, Despesa::where('despesa_estornada_id', $paga->id)->count());
        $this->assertSame('estornada', $paga->fresh()->status->value);
        $this->assertSame('1000.00', $this->saldo($conta)); // 1000 − 100 (original paga) + 100 (estorno) = efeito líquido zero
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'reversed')->count());
    }

    // 6) cancelar versus pagar
    public function test_cancelar_versus_pagar_a_mesma_despesa_exatamente_uma_vence_e_o_estado_e_consistente(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Cp', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();

        foreach (range(1, 3) as $rodada) {
            $d = $this->despesaPendente($categoria, $tes, ['valor' => '100.00']);
            $resultados = $this->dispararEmParalelo([
                ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $conta->id, 'data' => $this->hoje()],
                ['acao' => 'cancelar', 'despesa' => $d->id, 'user' => $tes->id],
                ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $conta->id, 'data' => '2026-01-01'],
                ['acao' => 'cancelar', 'despesa' => $d->id, 'user' => $tes->id],
            ]);

            $this->semErrosInesperados($resultados);
            $atual = $d->fresh();
            if ($atual->status->value === 'paga') {
                $this->assertNotNull($atual->conta_id, "rodada $rodada");
                $this->assertNull($atual->motivo_cancelamento, "rodada $rodada");
            } else {
                $this->assertSame('cancelada', $atual->status->value, "rodada $rodada");
                $this->assertNull($atual->conta_id, "rodada $rodada");
            }
            // Só uma operação de mudança de estado teve efeito (os replays do mesmo pagador contam como ok).
            $this->assertLessThanOrEqual(1, DB::table('audit_logs')->where('registro_id', $d->id)->whereIn('acao', ['paid', 'canceled'])->count(), "rodada $rodada");
        }
        // O saldo reflete exatamente as despesas efetivamente pagas.
        $pagas = Despesa::where('status', 'paga')->count();
        $this->assertSame(bcsub('1000.00', bcmul('100.00', (string) $pagas, 2), 2), $this->saldo($conta));
    }

    // 7) despesas em contas diferentes (e mistura com entradas) sem deadlock
    public function test_pagamentos_em_contas_diferentes_sem_deadlock(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $contas = [];
        $jobs = [];
        foreach (range(1, 4) as $i) {
            $contas[$i] = $this->conta("Conta Dl $i", 'banco', '1000.00');
            foreach (range(1, 2) as $j) { // duas por conta: disputa dentro da mesma conta e entre contas
                $d = $this->despesaPendente($categoria, $tes, ['valor' => '10.00']);
                $jobs[] = ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $tes->id, 'conta' => $contas[$i]->id, 'data' => $this->hoje()];
            }
        }

        $resultados = $this->dispararEmParalelo($jobs);

        $this->semErrosInesperados($resultados);
        $this->assertSame(8, $this->contar($resultados, true), json_encode($resultados));
        foreach ($contas as $conta) {
            $this->assertSame('980.00', $this->saldo($conta));
        }
    }

    // 8) criação idempotente concorrente
    public function test_criacoes_simultaneas_com_a_mesma_chave_geram_uma_unica_despesa(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        $resultados = $this->dispararEmParalelo(array_fill(0, 6, ['acao' => 'criar', 'user' => $tes->id, 'chave' => 'chave-corrida', 'categoria' => $categoria->id]));

        $this->semErrosInesperados($resultados);
        $this->assertSame(6, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(1, count(array_filter($resultados, fn ($r) => $r['replay'] === false)), json_encode($resultados));
        $this->assertSame(5, count(array_filter($resultados, fn ($r) => $r['replay'] === true)), json_encode($resultados));
        $this->assertSame(1, count(array_unique(array_column($resultados, 'id'))));
        $this->assertSame(1, Despesa::count());
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'created')->count());
    }

    public function test_criacoes_simultaneas_com_chaves_diferentes_criam_todas(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $jobs = [];
        foreach (range(1, 5) as $i) {
            $jobs[] = ['acao' => 'criar', 'user' => $tes->id, 'chave' => "chave-$i", 'categoria' => $categoria->id];
        }

        $resultados = $this->dispararEmParalelo($jobs);

        $this->semErrosInesperados($resultados);
        $this->assertSame(5, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(5, Despesa::count());
    }

    // 9) exclusão de conta/categoria concorrente com operações de despesas
    public function test_exclusao_de_conta_concorrente_com_pagamento_nunca_deixa_conta_excluida_com_despesa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();

        foreach (range(1, 3) as $rodada) {
            $conta = $this->conta("Conta Ex $rodada", 'banco', '1000.00');
            $d = $this->despesaPendente($categoria, $pastor, ['valor' => '10.00']);

            $resultados = $this->dispararEmParalelo([
                ['acao' => 'pagar', 'despesa' => $d->id, 'user' => $pastor->id, 'conta' => $conta->id, 'data' => $this->hoje()],
                ['acao' => 'excluir_conta', 'conta' => $conta->id, 'user' => $pastor->id],
            ]);

            $this->semErrosInesperados($resultados);
            $emUso = Despesa::where('conta_id', $conta->id)->exists();
            $excluida = DB::table('contas')->where('id', $conta->id)->whereNotNull('deleted_at')->exists();
            $this->assertFalse($emUso && $excluida, "rodada $rodada: conta excluída com despesa vinculada — " . json_encode($resultados));
        }
    }

    public function test_exclusao_de_categoria_concorrente_com_criacao_nunca_deixa_despesa_sem_categoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (range(1, 3) as $rodada) {
            $categoria = $this->categoriaDespesa("Cat Ex $rodada");

            $resultados = $this->dispararEmParalelo([
                ['acao' => 'criar', 'user' => $pastor->id, 'chave' => "cat-$rodada", 'categoria' => $categoria->id],
                ['acao' => 'excluir_categoria', 'categoria' => $categoria->id, 'user' => $pastor->id],
            ]);

            $this->semErrosInesperados($resultados);
            $existe = DB::table('categorias')->where('id', $categoria->id)->exists();
            $usada = Despesa::where('categoria_id', $categoria->id)->exists();
            $this->assertTrue($existe || ! $usada, "rodada $rodada: despesa apontando para categoria excluída — " . json_encode($resultados));
        }
    }

    public function test_exclusao_de_conta_concorrente_com_estorno_de_despesa_tambem_e_segura(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $conta = $this->conta('Conta Ee', 'banco', '1000.00');
        $paga = $this->despesaPaga($conta, $categoria, $pastor);

        $resultados = $this->dispararEmParalelo([
            ['acao' => 'estornar_despesa', 'despesa' => $paga->id, 'user' => $pastor->id],
            ['acao' => 'excluir_conta', 'conta' => $conta->id, 'user' => $pastor->id],
        ]);

        $this->semErrosInesperados($resultados);
        $this->assertSame(1, $this->contar($resultados, true), json_encode($resultados)); // o estorno vence; a exclusão é barrada (CONTA_EM_USO)
        $this->assertSame(1, $this->contar($resultados, false, 'CONTA_EM_USO'), json_encode($resultados));
        $this->assertNull(DB::table('contas')->where('id', $conta->id)->value('deleted_at'));
    }
}
