<?php

namespace Tests\Feature\Periodos;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Conta;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concorrência REAL da Fase 9: cada worker é um processo PHP com conexão própria ao MariaDB.
 * Objetivo central: provar que fechar/reabrir nunca disputa de forma insegura com uma operação
 * financeira no mesmo mês (o problema que não existia até a Fase 8, porque nada mais escrevia em
 * `periodos_financeiros`) — sem deadlock, sem lançamento "vazando" para depois do fechamento, sem
 * estado impossível na linha do período.
 */
class ConcorrenciaDePeriodosTest extends TestCase
{
    use CenarioPeriodos;

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

    /** O estado da linha nunca pode contradizer o CHECK do banco (chk_periodos_ciclo), mesmo sob corrida. */
    private function assertEstadoNuncaImpossivel(string $anoMes): void
    {
        $linha = DB::table('periodos_financeiros')->where('ano_mes', $anoMes)->first();
        if ($linha === null) {
            $this->assertTrue(true); // sem linha = aberto, estado sempre válido.
            return;
        }
        if ($linha->status === 'fechado') {
            $this->assertNotNull($linha->fechado_por);
            $this->assertNotNull($linha->fechado_em);
            $this->assertNull($linha->reaberto_por);
            $this->assertNull($linha->reaberto_em);
            $this->assertNull($linha->justificativa_reabertura);
        } else {
            $this->assertNotNull($linha->fechado_por);
            $this->assertNotNull($linha->fechado_em);
            $this->assertNotNull($linha->reaberto_por);
            $this->assertNotNull($linha->reaberto_em);
            $this->assertNotNull($linha->justificativa_reabertura);
        }
    }

    /** id do audit_log de 'created' de uma despesa/entrada, ou null se não foi criada. */
    private function auditIdCriacao(string $modulo, ?int $registroId): ?int
    {
        if ($registroId === null) {
            return null;
        }

        return AuditLog::where('modulo', $modulo)->where('acao', 'created')->where('registro_id', $registroId)->value('id');
    }

    private function auditIdFechamento(string $anoMes): ?int
    {
        $periodoId = DB::table('periodos_financeiros')->where('ano_mes', $anoMes)->value('id');

        return $periodoId === null ? null : AuditLog::where('modulo', 'periodos_financeiros')->where('acao', 'closed')->where('registro_id', $periodoId)->value('id');
    }

    private function auditIdReabertura(string $anoMes): ?int
    {
        $periodoId = DB::table('periodos_financeiros')->where('ano_mes', $anoMes)->value('id');

        return $periodoId === null ? null : AuditLog::where('modulo', 'periodos_financeiros')->where('acao', 'reopened')->where('registro_id', $periodoId)->value('id');
    }

    // =================================================================== 1

    public function test_01_dois_fechamentos_simultaneos_do_mesmo_periodo_nunca_tocado(): void
    {
        for ($rodada = 0; $rodada < 3; $rodada++) {
            $this->limpar();
            $pastor = $this->como(PerfilSlug::Pastor);
            $tesoureiro = $this->como(PerfilSlug::Tesoureiro);

            $r = $this->dispararEmParalelo([
                ['acao' => 'fechar_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id],
                ['acao' => 'fechar_periodo', 'ano_mes' => '2026-03', 'user' => $tesoureiro->id],
            ]);

            $this->limpo($r);
            $this->assertSame(1, $this->contar($r, true), json_encode($r));
            $this->assertSame(1, $this->contar($r, false, 'PERIODO_JA_FECHADO'), json_encode($r));
            $this->assertSame(1, DB::table('periodos_financeiros')->where('ano_mes', '2026-03')->count());
            $this->assertSame('fechado', $this->statusPeriodo('2026-03'));
            $this->assertEstadoNuncaImpossivel('2026-03');
        }
    }

    // =================================================================== 2

    public function test_02_fechamento_vs_criacao_de_despesa_no_mesmo_mes(): void
    {
        for ($rodada = 0; $rodada < 5; $rodada++) {
            $this->limpar();
            $pastor = $this->como(PerfilSlug::Pastor);
            $categoria = $this->categoriaDespesa("Energia C2-{$rodada}");

            $r = $this->dispararEmParalelo([
                ['acao' => 'fechar_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id],
                ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-15'],
            ]);

            $this->limpo($r);
            $fechar = $r[0];
            $despesa = $r[1];

            $this->assertTrue($fechar['ok'], json_encode($r));
            $this->assertEstadoNuncaImpossivel('2026-03');

            if ($despesa['ok']) {
                // A despesa só pode ter sido criada ANTES do fechamento se tornar durável.
                $idDespesa = $this->auditIdCriacao('despesas', $despesa['id']);
                $idFechamento = $this->auditIdFechamento('2026-03');
                $this->assertNotNull($idDespesa);
                $this->assertNotNull($idFechamento);
                $this->assertLessThan($idFechamento, $idDespesa, 'despesa criada depois do fechamento se tornar durável: ' . json_encode($r));
            } else {
                $this->assertSame('PERIODO_FECHADO', $despesa['code'], json_encode($r));
                $this->assertDatabaseMissing('despesas', ['categoria_id' => $categoria->id]);
            }
        }
    }

    // =================================================================== 2b (entrada, transferência, ajuste)

    public function test_02b_fechamento_vs_criacao_de_entrada_transferencia_e_ajuste(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco C2b', 'banco', '10000.00');
        $destino = $this->conta('Caixa C2b', 'caixa', '0.00');
        $categoria = $this->categoria('Dízimo C2b');

        $r1 = $this->dispararEmParalelo([
            ['acao' => 'fechar_periodo', 'ano_mes' => '2026-04', 'user' => $pastor->id],
            ['acao' => 'criar_entrada', 'conta' => $conta->id, 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-04-10'],
        ]);
        $this->limpo($r1);
        $this->assertTrue($r1[0]['ok']);
        if ($r1[1]['ok']) {
            $this->assertLessThan($this->auditIdFechamento('2026-04'), $this->auditIdCriacao('entradas', $r1[1]['id']));
        } else {
            $this->assertSame('PERIODO_FECHADO', $r1[1]['code']);
        }

        $r2 = $this->dispararEmParalelo([
            ['acao' => 'fechar_periodo', 'ano_mes' => '2026-05', 'user' => $pastor->id],
            ['acao' => 'transferir', 'user' => $pastor->id, 'origem' => $conta->id, 'destino' => $destino->id, 'valor' => '10.00', 'data' => '2026-05-10'],
        ]);
        $this->limpo($r2);
        $this->assertTrue($r2[0]['ok']);
        if ($r2[1]['ok']) {
            $this->assertLessThan($this->auditIdFechamento('2026-05'), $this->auditIdCriacao('transferencias', $r2[1]['id']));
        } else {
            $this->assertSame('PERIODO_FECHADO', $r2[1]['code']);
        }

        $r3 = $this->dispararEmParalelo([
            ['acao' => 'fechar_periodo', 'ano_mes' => '2026-06', 'user' => $pastor->id],
            ['acao' => 'ajustar', 'user' => $pastor->id, 'conta' => $conta->id, 'valor' => '10.00', 'sentido' => 'credito', 'data' => '2026-06-10'],
        ]);
        $this->limpo($r3);
        $this->assertTrue($r3[0]['ok']);
        if ($r3[1]['ok']) {
            $this->assertLessThan($this->auditIdFechamento('2026-06'), $this->auditIdCriacao('ajustes_saldo', $r3[1]['id']));
        } else {
            $this->assertSame('PERIODO_FECHADO', $r3[1]['code']);
        }
    }

    // =================================================================== 3

    public function test_03_reabertura_vs_criacao_de_despesa_no_mesmo_mes(): void
    {
        for ($rodada = 0; $rodada < 5; $rodada++) {
            $this->limpar();
            $pastor = $this->como(PerfilSlug::Pastor);
            $categoria = $this->categoriaDespesa("Energia C3-{$rodada}");
            $this->fecharApi($pastor, '2026-03')->assertOk();

            $r = $this->dispararEmParalelo([
                ['acao' => 'reabrir_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id],
                ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-15'],
            ]);

            $this->limpo($r);
            $reabrir = $r[0];
            $despesa = $r[1];

            $this->assertTrue($reabrir['ok'], json_encode($r));
            $this->assertEstadoNuncaImpossivel('2026-03');

            if ($despesa['ok']) {
                // Só pôde ter sido criada DEPOIS da reabertura se tornar durável.
                $idDespesa = $this->auditIdCriacao('despesas', $despesa['id']);
                $idReabertura = $this->auditIdReabertura('2026-03');
                $this->assertNotNull($idDespesa);
                $this->assertNotNull($idReabertura);
                $this->assertGreaterThan($idReabertura, $idDespesa, 'despesa criada antes da reabertura se tornar durável: ' . json_encode($r));
            } else {
                $this->assertSame('PERIODO_FECHADO', $despesa['code'], json_encode($r));
            }
        }
    }

    // =================================================================== 4

    public function test_04_duas_reaberturas_simultaneas_do_mesmo_periodo(): void
    {
        for ($rodada = 0; $rodada < 3; $rodada++) {
            $this->limpar();
            $pastor = $this->como(PerfilSlug::Pastor);
            $this->fecharApi($pastor, '2026-03')->assertOk();

            $r = $this->dispararEmParalelo([
                ['acao' => 'reabrir_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id, 'justificativa' => 'Rodada A'],
                ['acao' => 'reabrir_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id, 'justificativa' => 'Rodada B'],
            ]);

            $this->limpo($r);
            $this->assertSame(1, $this->contar($r, true), json_encode($r));
            $this->assertSame(1, $this->contar($r, false, 'PERIODO_JA_ABERTO'), json_encode($r));
            $this->assertSame('aberto', $this->statusPeriodo('2026-03'));
            $this->assertEstadoNuncaImpossivel('2026-03');
        }
    }

    // =================================================================== 5

    public function test_05_fechamento_vs_reabertura_simultaneos_de_periodo_nunca_fechado(): void
    {
        for ($rodada = 0; $rodada < 5; $rodada++) {
            $this->limpar();
            $pastor = $this->como(PerfilSlug::Pastor);

            $r = $this->dispararEmParalelo([
                ['acao' => 'fechar_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id],
                ['acao' => 'reabrir_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id],
            ]);

            $this->limpo($r);
            $fechar = $r[0];
            $reabrir = $r[1];

            // Só existe UM fechador nesta corrida: fechar nunca pode ver "já fechado" por outra
            // via, então sempre tem sucesso.
            $this->assertTrue($fechar['ok'], json_encode($r));
            $this->assertEstadoNuncaImpossivel('2026-03');

            // reabrir só tem sucesso se rodou DEPOIS do fechamento se tornar durável; senão, o
            // período ainda estava aberto (era o padrão) e reabrir corretamente rejeita.
            if ($reabrir['ok']) {
                $this->assertSame('aberto', $this->statusPeriodo('2026-03'));
            } else {
                $this->assertSame('PERIODO_JA_ABERTO', $reabrir['code'], json_encode($r));
            }
        }
    }

    // =================================================================== 6

    public function test_06_cinco_operacoes_simultaneas_em_periodo_ja_fechado_sao_todas_rejeitadas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('Energia C6');
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $r = $this->dispararEmParalelo([
            ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-05'],
            ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-10'],
            ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-15'],
            ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-20'],
            ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-25'],
        ]);

        $this->limpo($r);
        $this->assertSame(0, $this->contar($r, true), json_encode($r));
        $this->assertSame(5, $this->contar($r, false, 'PERIODO_FECHADO'), json_encode($r));
        $this->assertDatabaseCount('despesas', 0);
    }

    // =================================================================== 7

    public function test_07_fechamento_mais_tres_criacoes_simultaneas_no_mesmo_mes_nunca_fechado(): void
    {
        for ($rodada = 0; $rodada < 5; $rodada++) {
            $this->limpar();
            $pastor = $this->como(PerfilSlug::Pastor);
            $categoria = $this->categoriaDespesa("Energia C7-{$rodada}");

            $r = $this->dispararEmParalelo([
                ['acao' => 'fechar_periodo', 'ano_mes' => '2026-03', 'user' => $pastor->id],
                ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-05'],
                ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-10'],
                ['acao' => 'criar_despesa', 'categoria' => $categoria->id, 'user' => $pastor->id, 'data' => '2026-03-15'],
            ]);

            $this->limpo($r);
            $this->assertTrue($r[0]['ok'], json_encode($r));
            $this->assertEstadoNuncaImpossivel('2026-03');
            $idFechamento = $this->auditIdFechamento('2026-03');

            foreach (array_slice($r, 1) as $resultado) {
                if ($resultado['ok']) {
                    $idDespesa = $this->auditIdCriacao('despesas', $resultado['id']);
                    $this->assertNotNull($idDespesa);
                    $this->assertLessThan($idFechamento, $idDespesa, json_encode($r));
                } else {
                    $this->assertSame('PERIODO_FECHADO', $resultado['code'], json_encode($r));
                }
            }
        }
    }

    // =================================================================== 8 (bônus: ordem dos 2 períodos de uma despesa)

    /**
     * Prova de ausência de deadlock da ordem crescente de ano_mes usada em `garantirAbertoTodos()`
     * (DespesaService::pagar): duas despesas pagas ao mesmo tempo, uma cruzando agosto→setembro e
     * a outra setembro→agosto — se a ordem não fosse determinística, cada uma travaria um período
     * primeiro e o outro depois, em direções opostas, e um dos dois travaria esperando o outro
     * (deadlock). Com os dois meses abertos, as duas devem ter sucesso.
     */
    public function test_08_pagamentos_cruzados_entre_dois_meses_nao_causam_deadlock(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco C8', 'banco', '10000.00');
        $categoria = $this->categoriaDespesa('Energia C8');
        $despesaAgoSet = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-08-05']);
        $despesaSetAgo = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-09-05']);

        $r = $this->dispararEmParalelo([
            ['acao' => 'pagar_despesa', 'despesa' => $despesaAgoSet->id, 'user' => $pastor->id, 'conta' => $conta->id, 'data' => '2026-09-10'],
            ['acao' => 'pagar_despesa', 'despesa' => $despesaSetAgo->id, 'user' => $pastor->id, 'conta' => $conta->id, 'data' => '2026-08-10'],
        ]);

        $this->limpo($r);
        $this->assertSame(2, $this->contar($r, true), json_encode($r));
    }
}
