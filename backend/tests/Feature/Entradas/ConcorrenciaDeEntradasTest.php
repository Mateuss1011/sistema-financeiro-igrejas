<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use App\Models\Entrada;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concorrência REAL: cada worker é um processo PHP com conexão própria ao MariaDB.
 * Estes testes gravam de verdade (sem RefreshDatabase) e limpam as tabelas ao final;
 * só rodam contra o banco sfg_testing.
 */
class ConcorrenciaDeEntradasTest extends TestCase
{
    use CenarioEntradas;

    private const TABELAS = ['entradas', 'periodos_financeiros', 'permissoes_excecao', 'audit_logs', 'contas', 'categorias', 'users'];

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
     * Dispara os workers ao mesmo tempo e devolve os resultados decodificados.
     *
     * @param  list<list<string>>  $argumentosDosWorkers
     */
    private function dispararEmParalelo(array $argumentosDosWorkers): array
    {
        $inicio = (int) (microtime(true) * 1000) + 2500; // folga para todos os processos subirem
        $script = base_path('tests/Support/concorrencia_worker.php');
        $ambiente = array_merge(getenv(), ['DB_DATABASE' => 'sfg_testing', 'APP_ENV' => 'testing']);
        $processos = [];

        foreach ($argumentosDosWorkers as $i => $argumentos) {
            $argumentos[] = (string) $inicio; // instante de início vai por último; o worker o lê pela posição
            $comando = [PHP_BINARY, $script, ...$this->posicionarInicio($argumentos)];
            $processos[$i] = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);
            $processos[$i] = ['proc' => $processos[$i], 'pipes' => $pipes];
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

    /** Move o instante de início para a posição que o worker espera de acordo com a ação. */
    private function posicionarInicio(array $argumentos): array
    {
        $inicio = array_pop($argumentos);

        if ($argumentos[0] === 'estornar') {
            // estornar <entradaId> <userId> <inicio> [confirmar]
            return array_merge(array_slice($argumentos, 0, 3), [$inicio], array_slice($argumentos, 3));
        }

        // criar <userId> <chave> <contaId> <categoriaId> <inicio>
        return array_merge($argumentos, [$inicio]);
    }

    private function contar(array $resultados, bool $ok, ?string $code = null): int
    {
        return count(array_filter($resultados, fn ($r) => $r['ok'] === $ok && ($code === null || ($r['code'] ?? null) === $code)));
    }

    public function test_estornos_simultaneos_da_mesma_entrada_geram_um_unico_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Conc 1', 'banco', '0.00');
        $original = $this->entrada($conta, $this->categoria('Conc Cat 1'), $pastor, '100.00', '2026-01-10');

        $resultados = $this->dispararEmParalelo(array_fill(0, 6, ['estornar', (string) $original->id, (string) $pastor->id]));

        $this->assertSame(1, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(5, $this->contar($resultados, false, 'ENTRADA_JA_ESTORNADA'), json_encode($resultados));
        $this->assertSame(1, Entrada::where('entrada_estornada_id', $original->id)->count());
        $this->assertSame(2, Entrada::count());
        $this->assertSame('estornada', $original->fresh()->status->value);
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'reversed')->count());
    }

    public function test_estornos_simultaneos_de_entradas_diferentes_na_mesma_conta_serializam_sem_deadlock(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $caixa = $this->conta('Caixa Conc', 'caixa', '0.00');
        $categoria = $this->categoria('Conc Cat 2');
        $entradas = collect(range(1, 4))->map(fn () => $this->entrada($caixa, $categoria, $pastor, '25.00', '2026-01-10'));

        $resultados = $this->dispararEmParalelo($entradas->map(fn ($e) => ['estornar', (string) $e->id, (string) $pastor->id])->all());

        // O saldo (100,00) comporta os quatro estornos: todos passam, um por vez, sem deadlock.
        $this->assertSame(4, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame('0.00', app(\App\Services\SaldoService::class)->saldoAtual($caixa->fresh()));
        $this->assertSame(4, Entrada::whereNotNull('entrada_estornada_id')->count());
    }

    public function test_criacoes_simultaneas_com_a_mesma_chave_geram_uma_unica_entrada(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Conc 3', 'banco', '0.00');
        $categoria = $this->categoria('Conc Cat 3');

        $resultados = $this->dispararEmParalelo(array_fill(0, 6, ['criar', (string) $tesoureiro->id, 'chave-corrida', (string) $conta->id, (string) $categoria->id]));

        $this->assertSame(6, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(1, count(array_filter($resultados, fn ($r) => $r['replay'] === false)), json_encode($resultados));
        $this->assertSame(5, count(array_filter($resultados, fn ($r) => $r['replay'] === true)), json_encode($resultados));
        $this->assertSame(1, count(array_unique(array_column($resultados, 'id'))));
        $this->assertSame(1, Entrada::count());
        $this->assertSame(1, DB::table('audit_logs')->where('acao', 'created')->count());
    }

    public function test_criacoes_simultaneas_com_chaves_diferentes_criam_todas(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Conc 4', 'banco', '0.00');
        $categoria = $this->categoria('Conc Cat 4');

        $args = [];
        foreach (range(1, 5) as $i) {
            $args[] = ['criar', (string) $tesoureiro->id, "chave-$i", (string) $conta->id, (string) $categoria->id];
        }
        $resultados = $this->dispararEmParalelo($args);

        $this->assertSame(5, $this->contar($resultados, true), json_encode($resultados));
        $this->assertSame(5, Entrada::count());
        $this->assertSame('50.00', app(\App\Services\SaldoService::class)->saldoAtual($conta->fresh()));
    }

    public function test_exclusao_de_conta_e_criacao_simultanea_nunca_deixam_conta_excluida_com_entrada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria('Conc Cat 5');

        for ($rodada = 1; $rodada <= 3; $rodada++) {
            $conta = $this->conta("Conc Corrida $rodada", 'banco', '0.00');
            $inicio = (int) (microtime(true) * 1000) + 2500;
            $script = base_path('tests/Support/concorrencia_worker.php');
            $ambiente = array_merge(getenv(), ['DB_DATABASE' => 'sfg_testing', 'APP_ENV' => 'testing']);

            $criar = proc_open([PHP_BINARY, $script, 'criar', (string) $pastor->id, "k-$rodada", (string) $conta->id, (string) $categoria->id, (string) $inicio], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA, base_path(), $ambiente);
            $excluir = proc_open([PHP_BINARY, '-r', $this->codigoExcluir($conta->id, $pastor->id, $inicio)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB, base_path(), $ambiente);
            stream_get_contents($pipesA[1]);
            stream_get_contents($pipesB[1]);
            proc_close($criar);
            proc_close($excluir);

            $emUso = Entrada::where('conta_id', $conta->id)->exists();
            $excluida = DB::table('contas')->where('id', $conta->id)->whereNotNull('deleted_at')->exists();
            $this->assertFalse($emUso && $excluida, "rodada $rodada: conta excluída com entrada vinculada");
        }
    }

    private function codigoExcluir(int $contaId, int $userId, int $inicio): string
    {
        $raiz = var_export(base_path(), true);

        return <<<PHP
        require {$raiz} . '/vendor/autoload.php';
        \$app = require {$raiz} . '/bootstrap/app.php';
        \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        while ((int) (microtime(true) * 1000) < {$inicio}) { usleep(200); }
        try {
            \$app->make(App\Services\ContaService::class)->excluir(App\Models\Conta::findOrFail({$contaId}), App\Models\User::findOrFail({$userId}));
        } catch (Throwable \$e) {
        }
        PHP;
    }
}
