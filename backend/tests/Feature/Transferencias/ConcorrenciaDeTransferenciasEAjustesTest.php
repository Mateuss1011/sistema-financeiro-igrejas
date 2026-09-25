<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\AjusteSaldo;
use App\Models\Conta;
use App\Models\Transferencia;
use App\Services\SaldoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concorrência REAL da Fase 8: cada worker é um processo PHP com conexão própria ao MariaDB.
 * Grava de verdade (sem RefreshDatabase) e limpa as tabelas ao final; só roda em sfg_testing.
 * Objetivo: zero deadlock, zero saldo incorreto, zero duplicação.
 */
class ConcorrenciaDeTransferenciasEAjustesTest extends TestCase
{
    use CenarioTransferencias;

    private const TABELAS = ['transferencias', 'ajustes_saldo', 'despesas', 'entradas', 'periodos_financeiros', 'permissoes_excecao', 'audit_logs', 'contas', 'categorias', 'users'];

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

    /** @param  list<array<string, string|int>>  $jobs */
    private function dispararEmParalelo(array $jobs): array
    {
        $inicio = (int) (microtime(true) * 1000) + 3000;
        $script = base_path('tests/Support/transferencias_worker.php');
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

    private function contar(array $r, bool $ok, ?string $code = null): int
    {
        return count(array_filter($r, fn ($x) => $x['ok'] === $ok && ($code === null || ($x['code'] ?? null) === $code)));
    }

    private function limpo(array $r): void
    {
        $this->assertSame(0, $this->contar($r, false, 'ERRO_INESPERADO'), json_encode($r));
        $this->assertSame(0, $this->contar($r, false, 'SAIDA_INVALIDA'), json_encode($r));
    }

    private function jobTransf($user, Conta $o, Conta $d, string $valor, array $extra = []): array
    {
        return ['acao' => 'transferir', 'user' => $user->id, 'origem' => $o->id, 'destino' => $d->id, 'valor' => $valor, 'data' => $this->hoje()] + $extra;
    }

    private function jobAjuste($user, Conta $c, string $valor, string $sentido, array $extra = []): array
    {
        return ['acao' => 'ajustar', 'user' => $user->id, 'conta' => $c->id, 'valor' => $valor, 'sentido' => $sentido, 'data' => $this->hoje()] + $extra;
    }

    // 1) duas transferências simultâneas A -> B usando a mesma conta de origem
    public function test_01_transferencias_simultaneas_da_mesma_origem_a_para_b(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Caixa A', 'caixa', '100.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobTransf($tes, $a, $b, '60.00'), range(1, 5)));

        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(4, $this->contar($r, false, 'SALDO_INSUFICIENTE'), json_encode($r));
        $this->assertSame('40.00', $this->saldoDe($a));
        $this->assertSame('60.00', $this->saldoDe($b));
        $this->assertSame(1, Transferencia::count());
    }

    // 2) duas (várias) transferências simultâneas B -> A: mesma ordem de trava, todas concluem
    public function test_02_transferencias_simultaneas_b_para_a_concluem_sem_deadlock(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '0.00');
        $b = $this->conta('Banco B', 'banco', '1000.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobTransf($tes, $b, $a, '25.00'), range(1, 6)));

        $this->limpo($r);
        $this->assertSame(6, $this->contar($r, true), json_encode($r));
        $this->assertSame('150.00', $this->saldoDe($a));
        $this->assertSame('850.00', $this->saldoDe($b));
    }

    // 3) A -> B simultâneo com B -> A (o caso clássico de deadlock): várias rodadas
    public function test_03_a_para_b_simultaneo_com_b_para_a_nao_gera_deadlock(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);

        foreach (range(1, 3) as $rodada) {
            $a = $this->conta("Banco A$rodada", 'banco', '1000.00');
            $b = $this->conta("Banco B$rodada", 'banco', '1000.00');
            $jobs = [];
            foreach (range(1, 4) as $i) {
                $jobs[] = $this->jobTransf($tes, $a, $b, '10.00');
                $jobs[] = $this->jobTransf($tes, $b, $a, '10.00');
            }

            $r = $this->dispararEmParalelo($jobs);

            $this->limpo($r);
            $this->assertSame(8, $this->contar($r, true), "rodada $rodada: " . json_encode($r));
            $this->assertSame('1000.00', $this->saldoDe($a), "rodada $rodada");
            $this->assertSame('1000.00', $this->saldoDe($b), "rodada $rodada");
        }
        $this->assertSame(24, Transferencia::count());
    }

    // 4) duas transferências diferentes consumindo o mesmo saldo de CAIXA
    public function test_04_transferencias_para_destinos_diferentes_consumindo_o_mesmo_caixa(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Unico', 'caixa', '100.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $c = $this->conta('Banco C', 'banco', '0.00');

        $r = $this->dispararEmParalelo([
            $this->jobTransf($tes, $caixa, $b, '60.00'),
            $this->jobTransf($tes, $caixa, $c, '60.00'),
            $this->jobTransf($tes, $caixa, $b, '60.00'),
            $this->jobTransf($tes, $caixa, $c, '60.00'),
        ]);

        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(3, $this->contar($r, false, 'SALDO_INSUFICIENTE'), json_encode($r));
        $this->assertSame('40.00', $this->saldoDe($caixa));
        $this->assertSame('60.00', bcadd($this->saldoDe($b), $this->saldoDe($c), 2));
    }

    // 5) transferência de BANCO que exige confirmação de saldo negativo
    public function test_05_transferencias_de_banco_sem_e_com_confirmacao(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $dest = $this->conta('Caixa Dest', 'caixa', '0.00');

        $banco = $this->conta('Banco Conf', 'banco', '100.00');
        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobTransf($tes, $banco, $dest, '60.00'), range(1, 5)));
        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(4, $this->contar($r, false, 'SALDO_NEGATIVO_REQUER_CONFIRMACAO'), json_encode($r));
        $this->assertSame('40.00', $this->saldoDe($banco));

        $banco2 = $this->conta('Banco Conf 2', 'banco', '0.00');
        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobTransf($tes, $banco2, $dest, '25.00', ['confirmar' => '1']), range(1, 4)));
        $this->limpo($r);
        $this->assertSame(4, $this->contar($r, true), json_encode($r));
        $this->assertSame('-100.00', $this->saldoDe($banco2));
    }

    // 6) duas (várias) tentativas de estorno da mesma transferência
    public function test_06_estornos_simultaneos_da_mesma_transferencia(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $original = $this->transferenciaDireta($a, $b, $pastor, '100.00');

        $r = $this->dispararEmParalelo(array_map(fn () => ['acao' => 'estornar_transferencia', 'transferencia' => $original->id, 'user' => $pastor->id], range(1, 6)));

        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(5, $this->contar($r, false, 'TRANSFERENCIA_JA_ESTORNADA'), json_encode($r));
        $this->assertSame(1, Transferencia::where('transferencia_estornada_id', $original->id)->count());
        $this->assertSame('estornada', $original->fresh()->status->value);
        $this->assertSame('1000.00', $this->saldoDe($a));
        $this->assertSame('0.00', $this->saldoDe($b));
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'reversed')->count());
    }

    // 7) estorno simultâneo com outra transferência usando a mesma conta
    public function test_07_estorno_versus_outra_transferencia_da_mesma_conta(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (range(1, 3) as $rodada) {
            $a = $this->conta("Banco A$rodada", 'banco', '1000.00');
            $b = $this->conta("Caixa B$rodada", 'caixa', '0.00');
            $c = $this->conta("Banco C$rodada", 'banco', '0.00');
            $original = $this->transferenciaDireta($a, $b, $pastor, '100.00'); // B recebeu 100

            // O estorno tira 100 de B; a outra transferência tira 60 de B: só um cabe.
            $r = $this->dispararEmParalelo([
                ['acao' => 'estornar_transferencia', 'transferencia' => $original->id, 'user' => $pastor->id],
                $this->jobTransf($pastor, $b, $c, '60.00'),
            ]);

            $this->limpo($r);
            $this->assertSame(1, $this->contar($r, true), "rodada $rodada: " . json_encode($r));
            $this->assertSame(1, $this->contar($r, false, 'SALDO_INSUFICIENTE'), "rodada $rodada: " . json_encode($r));
            $this->assertTrue(bccomp($this->saldoDe($b), '0', 2) >= 0, "rodada $rodada: o caixa ficou negativo");
        }
    }

    // 8) ajuste de débito concorrente na mesma CAIXA
    public function test_08_ajustes_de_debito_concorrentes_na_mesma_caixa(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Aj', 'caixa', '100.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobAjuste($tes, $caixa, '60.00', 'debito'), range(1, 5)));

        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(4, $this->contar($r, false, 'SALDO_INSUFICIENTE'), json_encode($r));
        $this->assertSame('40.00', $this->saldoDe($caixa));
        $this->assertSame(1, AjusteSaldo::count());
    }

    // 9) ajuste de crédito concorrente
    public function test_09_ajustes_de_credito_concorrentes_somam_exatamente(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Cr', 'caixa', '0.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobAjuste($tes, $caixa, '10.10', 'credito'), range(1, 6)));

        $this->limpo($r);
        $this->assertSame(6, $this->contar($r, true), json_encode($r));
        $this->assertSame('60.60', $this->saldoDe($caixa));
        $this->assertSame(6, AjusteSaldo::count());
    }

    // 10) ajuste de débito versus transferência
    public function test_10_ajuste_de_debito_versus_transferencia(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);

        foreach (range(1, 3) as $rodada) {
            $caixa = $this->conta("Caixa Ajt$rodada", 'caixa', '100.00');
            $b = $this->conta("Banco Ajt$rodada", 'banco', '0.00');

            $r = $this->dispararEmParalelo([$this->jobAjuste($tes, $caixa, '60.00', 'debito'), $this->jobTransf($tes, $caixa, $b, '60.00')]);

            $this->limpo($r);
            $this->assertSame(1, $this->contar($r, true), "rodada $rodada: " . json_encode($r));
            $this->assertSame(1, $this->contar($r, false, 'SALDO_INSUFICIENTE'), "rodada $rodada: " . json_encode($r));
            $this->assertSame('40.00', $this->saldoDe($caixa), "rodada $rodada");
        }
    }

    // 11) transferência versus pagamento de despesa
    public function test_11_transferencia_versus_pagamento_de_despesa(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        foreach (range(1, 3) as $rodada) {
            $caixa = $this->conta("Caixa Dsp$rodada", 'caixa', '100.00');
            $b = $this->conta("Banco Dsp$rodada", 'banco', '0.00');
            $despesa = $this->despesaPendente($categoria, $tes, ['valor' => '60.00']);

            $r = $this->dispararEmParalelo([
                $this->jobTransf($tes, $caixa, $b, '60.00'),
                ['acao' => 'pagar_despesa', 'despesa' => $despesa->id, 'user' => $tes->id, 'conta' => $caixa->id, 'data' => $this->hoje()],
            ]);

            $this->limpo($r);
            $this->assertSame(1, $this->contar($r, true), "rodada $rodada: " . json_encode($r));
            $this->assertSame(1, $this->contar($r, false, 'SALDO_INSUFICIENTE'), "rodada $rodada: " . json_encode($r));
            $this->assertSame('40.00', $this->saldoDe($caixa), "rodada $rodada");
        }
    }

    // 12) transferência versus estorno de entrada
    public function test_12_transferencia_versus_estorno_de_entrada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $catEntrada = $this->categoria();

        foreach (range(1, 3) as $rodada) {
            $caixa = $this->conta("Caixa Ent$rodada", 'caixa', '0.00');
            $b = $this->conta("Banco Ent$rodada", 'banco', '0.00');
            $entrada = $this->entrada($caixa, $catEntrada, $pastor, '100.00');

            // Estornar a entrada exige 100 no caixa; a transferência exige 60: só um cabe.
            $r = $this->dispararEmParalelo([
                $this->jobTransf($pastor, $caixa, $b, '60.00'),
                ['acao' => 'estornar_entrada', 'entrada' => $entrada->id, 'user' => $pastor->id],
            ]);

            $this->limpo($r);
            $this->assertSame(1, $this->contar($r, true), "rodada $rodada: " . json_encode($r));
            $this->assertSame(1, $this->contar($r, false, 'SALDO_INSUFICIENTE'), "rodada $rodada: " . json_encode($r));
            $this->assertTrue(bccomp($this->saldoDe($caixa), '0', 2) >= 0, "rodada $rodada: o caixa ficou negativo");
        }
    }

    // 13) exclusão/inativação de conta concorrente com transferência
    public function test_13_exclusao_de_conta_concorrente_com_transferencia_nunca_deixa_conta_excluida_com_movimento(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Origem fixa', 'banco', '10000.00');

        foreach (range(1, 3) as $rodada) {
            $alvo = $this->conta("Alvo $rodada", 'banco', '0.00');

            $r = $this->dispararEmParalelo([
                $this->jobTransf($pastor, $origem, $alvo, '10.00'),
                ['acao' => 'excluir_conta', 'conta' => $alvo->id, 'user' => $pastor->id],
            ]);

            $this->limpo($r);
            $emUso = Transferencia::where('conta_destino_id', $alvo->id)->exists();
            $excluida = DB::table('contas')->where('id', $alvo->id)->whereNotNull('deleted_at')->exists();
            $this->assertFalse($emUso && $excluida, "rodada $rodada: conta excluída com transferência vinculada — " . json_encode($r));
            $this->assertSame(1, $this->contar($r, true), "rodada $rodada: " . json_encode($r));
        }
    }

    public function test_13b_inativacao_de_conta_concorrente_com_transferencia_e_serializada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Origem fixa', 'banco', '10000.00');

        foreach (range(1, 3) as $rodada) {
            $alvo = $this->conta("Alvo inat $rodada", 'banco', '0.00');

            $r = $this->dispararEmParalelo([
                $this->jobTransf($pastor, $origem, $alvo, '10.00'),
                ['acao' => 'inativar_conta', 'conta' => $alvo->id, 'user' => $pastor->id],
            ]);

            $this->limpo($r);
            $this->assertFalse((bool) DB::table('contas')->where('id', $alvo->id)->value('ativa'), "rodada $rodada: a inativação sempre conclui");
            // A transferência ou passou (antes da inativação) ou foi barrada com CONTA_INATIVA; a inativação sempre conclui.
            $this->assertSame(Transferencia::where('conta_destino_id', $alvo->id)->count(), $this->contar($r, true) - 1, "rodada $rodada: transferência gravada só se passou");
        }
    }

    // 14) exclusão/inativação de conta concorrente com ajuste
    public function test_14_exclusao_e_inativacao_de_conta_concorrente_com_ajuste(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (range(1, 3) as $rodada) {
            $alvo = $this->conta("Alvo ajuste $rodada", 'banco', '0.00');
            $r = $this->dispararEmParalelo([
                $this->jobAjuste($pastor, $alvo, '10.00', 'credito'),
                ['acao' => 'excluir_conta', 'conta' => $alvo->id, 'user' => $pastor->id],
            ]);
            $this->limpo($r);
            $emUso = AjusteSaldo::where('conta_id', $alvo->id)->exists();
            $excluida = DB::table('contas')->where('id', $alvo->id)->whereNotNull('deleted_at')->exists();
            $this->assertFalse($emUso && $excluida, "rodada $rodada: conta excluída com ajuste vinculado — " . json_encode($r));
        }

        $inativa = $this->conta('Alvo ajuste inativo', 'banco', '0.00');
        $r = $this->dispararEmParalelo([
            $this->jobAjuste($pastor, $inativa, '10.00', 'credito'),
            ['acao' => 'inativar_conta', 'conta' => $inativa->id, 'user' => $pastor->id],
        ]);
        $this->limpo($r);
        $this->assertFalse((bool) DB::table('contas')->where('id', $inativa->id)->value('ativa'));
        $this->assertSame(AjusteSaldo::where('conta_id', $inativa->id)->count(), $this->contar($r, true) - 1);
    }

    // 15) mesma Idempotency-Key em processos simultâneos
    public function test_15_mesma_idempotency_key_em_transferencias_simultaneas(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobTransf($tes, $a, $b, '10.00', ['chave' => 'chave-corrida']), range(1, 6)));

        $this->limpo($r);
        $this->assertSame(6, $this->contar($r, true), json_encode($r));
        $this->assertSame(1, count(array_filter($r, fn ($x) => $x['replay'] === false)), json_encode($r));
        $this->assertSame(5, count(array_filter($r, fn ($x) => $x['replay'] === true)), json_encode($r));
        $this->assertSame(1, count(array_unique(array_column($r, 'id'))));
        $this->assertSame(1, Transferencia::count());
        $this->assertSame('990.00', $this->saldoDe($a)); // debitou UMA vez
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'created')->count());
    }

    public function test_15b_mesma_idempotency_key_em_ajustes_simultaneos_e_chaves_diferentes(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Chave', 'caixa', '0.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobAjuste($tes, $caixa, '10.00', 'credito', ['chave' => 'aj-corrida']), range(1, 6)));
        $this->limpo($r);
        $this->assertSame(6, $this->contar($r, true), json_encode($r));
        $this->assertSame(1, count(array_filter($r, fn ($x) => $x['replay'] === false)), json_encode($r));
        $this->assertSame(1, AjusteSaldo::count());
        $this->assertSame('10.00', $this->saldoDe($caixa));

        $r = $this->dispararEmParalelo(array_map(fn ($i) => $this->jobAjuste($tes, $caixa, '1.00', 'credito', ['chave' => "aj-$i"]), range(1, 5)));
        $this->limpo($r);
        $this->assertSame(5, $this->contar($r, true), json_encode($r));
        $this->assertSame('15.00', $this->saldoDe($caixa));
    }

    // 16) com saldo apertado, a idempotência + saldo continuam corretos (consulta de chave só DEPOIS da trava)
    public function test_16_chave_repetida_com_saldo_apertado_debita_uma_vez_e_o_resto_e_replay(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Apertada', 'caixa', '100.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $r = $this->dispararEmParalelo(array_map(fn () => $this->jobTransf($tes, $caixa, $b, '100.00', ['chave' => 'apertada']), range(1, 6)));

        $this->limpo($r);
        $this->assertSame(6, $this->contar($r, true), json_encode($r)); // 1 criada + 5 replays; NENHUM SALDO_INSUFICIENTE espúrio
        $this->assertSame('0.00', $this->saldoDe($caixa));
        $this->assertSame(1, Transferencia::count());
    }

    // 16b) chaves DISTINTAS + saldo apertado: a consulta de idempotência (leitura comum) antes da trava
    //      fixaria o snapshot e o saldo lido seria velho — este é o detector do risco de snapshot.
    public function test_16b_chaves_distintas_com_saldo_apertado_nao_ultrapassam_o_caixa(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);

        $caixa = $this->conta('Caixa Chaves', 'caixa', '100.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $r = $this->dispararEmParalelo(array_map(fn ($i) => $this->jobTransf($tes, $caixa, $b, '60.00', ['chave' => "t-$i"]), range(1, 6)));
        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(5, $this->contar($r, false, 'SALDO_INSUFICIENTE'), json_encode($r));
        $this->assertSame('40.00', $this->saldoDe($caixa));

        $caixa2 = $this->conta('Caixa Chaves 2', 'caixa', '100.00');
        $r = $this->dispararEmParalelo(array_map(fn ($i) => $this->jobAjuste($tes, $caixa2, '60.00', 'debito', ['chave' => "a-$i"]), range(1, 6)));
        $this->limpo($r);
        $this->assertSame(1, $this->contar($r, true), json_encode($r));
        $this->assertSame(5, $this->contar($r, false, 'SALDO_INSUFICIENTE'), json_encode($r));
        $this->assertSame('40.00', $this->saldoDe($caixa2));
    }

    // 17) muitas contas e operações cruzadas: detecta deadlock
    public function test_17_muitas_contas_com_operacoes_cruzadas_sem_deadlock_e_saldo_total_intacto(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $contas = array_map(fn ($i) => $this->conta("Multi $i", 'banco', '1000.00'), range(1, 5));
        $totalAntes = '5000.00';

        $jobs = [];
        foreach (range(0, 4) as $i) {
            foreach ([1, 2, 3] as $passo) { // pares em ambos os sentidos, incluindo os cruzados
                $j = ($i + $passo) % 5;
                $jobs[] = $this->jobTransf($tes, $contas[$i], $contas[$j], '10.00');
            }
        }
        $this->assertCount(15, $jobs);

        $r = $this->dispararEmParalelo($jobs);

        $this->limpo($r);
        $this->assertSame(15, $this->contar($r, true), json_encode($r));
        $total = array_reduce($contas, fn ($c, $conta) => bcadd($c, $this->saldoDe($conta), 2), '0');
        $this->assertSame($totalAntes, $total, 'o saldo consolidado não muda');
        $this->assertSame(15, Transferencia::count());
    }

    // 18) misto: transferência A->B, B->A, ajuste e despesa sobre as mesmas duas contas
    public function test_18_operacoes_mistas_sobre_as_mesmas_duas_contas(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Mix A', 'banco', '1000.00');
        $b = $this->conta('Mix B', 'banco', '1000.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tes, ['valor' => '10.00']);

        $r = $this->dispararEmParalelo([
            $this->jobTransf($tes, $a, $b, '50.00'),
            $this->jobTransf($tes, $b, $a, '30.00'),
            $this->jobAjuste($tes, $a, '5.00', 'credito'),
            $this->jobAjuste($tes, $b, '5.00', 'debito'),
            ['acao' => 'pagar_despesa', 'despesa' => $despesa->id, 'user' => $tes->id, 'conta' => $a->id, 'data' => $this->hoje()],
            $this->jobTransf($tes, $a, $b, '20.00'),
        ]);

        $this->limpo($r);
        $this->assertSame(6, $this->contar($r, true), json_encode($r));
        // A: 1000 −50 +30 +5 −10 −20 = 955 | B: 1000 +50 −30 −5 +20 = 1035
        $this->assertSame('955.00', $this->saldoDe($a));
        $this->assertSame('1035.00', $this->saldoDe($b));
    }
}
