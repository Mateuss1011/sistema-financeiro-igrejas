<?php

namespace Tests\Feature\Contas;

use App\Models\Conta;
use App\Services\SaldoService;
use App\Support\Dinheiro;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Saldo (Service/BCMath) e integridade da migration real no MariaDB. */
class SaldoEIntegridadeTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome, string $tipo, string $saldo): Conta
    {
        return Conta::create(['nome' => $nome, 'tipo' => $tipo, 'saldo_inicial' => $saldo]);
    }

    // ---------- SaldoService ----------

    public function test_saldo_atual_e_calculado_pelo_service_e_igual_ao_saldo_inicial_nesta_fase(): void
    {
        $service = app(SaldoService::class);

        foreach (['0.00', '0.10', '10.05', '-1234.56', '999999999999.99', '-999999999999.99'] as $i => $saldo) {
            $conta = $this->conta("Conta $i", 'banco', $saldo);

            $this->assertSame($saldo, $service->saldoAtual($conta->fresh()));
            $this->assertIsString($service->saldoAtual($conta));
        }
    }

    public function test_saldo_atual_nao_e_persistido(): void
    {
        $colunas = collect(DB::select('SHOW COLUMNS FROM contas'))->pluck('Field')->all();

        $this->assertNotContains('saldo_atual', $colunas);
        $this->assertContains('saldo_inicial', $colunas);
    }

    // ---------- Dinheiro (sem float) ----------

    public function test_dinheiro_normaliza_e_rejeita_sem_arredondar(): void
    {
        $validos = ['1' => '1.00', '1.5' => '1.50', '-0.5' => '-0.50', '0' => '0.00', '-0' => '0.00', '-0.00' => '0.00', '999999999999.99' => '999999999999.99'];
        foreach ($validos as $entrada => $esperado) {
            $this->assertSame($esperado, Dinheiro::normalizar($entrada), "entrada $entrada");
        }

        $this->assertSame('1500.50', Dinheiro::normalizar(1500.5));
        $this->assertSame('0.10', Dinheiro::normalizar(0.1));
        $this->assertSame('10.00', Dinheiro::normalizar(10));

        foreach (['1.005', '1,5', 'abc', '', '1e5', '1234567890123', '--1', null, true, [], 0.1 + 0.2] as $invalido) {
            $this->assertNull(Dinheiro::normalizar($invalido), 'deveria rejeitar: ' . json_encode($invalido));
        }
    }

    public function test_aritmetica_bcmath_e_exata_onde_float_falharia(): void
    {
        $this->assertSame('0.30', bcadd('0.10', '0.20', 2));   // float: 0.30000000000000004
        $this->assertSame('999999999999.98', bcsub('999999999999.99', '0.01', 2));
        $this->assertTrue(Dinheiro::ehNegativo('-0.01'));
        $this->assertFalse(Dinheiro::ehNegativo('0.00'));
    }

    // ---------- Integridade da migration real (MariaDB) ----------

    public function test_colunas_e_tipos_da_tabela_contas(): void
    {
        $colunas = collect(DB::select('SHOW COLUMNS FROM contas'))->keyBy('Field');

        $this->assertSame('decimal(14,2)', $colunas['saldo_inicial']->Type);
        $this->assertSame("enum('banco','caixa')", $colunas['tipo']->Type);
        $this->assertSame('varchar(100)', $colunas['nome']->Type);
        $this->assertSame('NO', $colunas['nome']->Null);
        $this->assertSame('NO', $colunas['saldo_inicial']->Null);
        $this->assertSame('YES', $colunas['deleted_at']->Null);
        $this->assertStringContainsString('VIRTUAL', strtoupper($colunas['nome_ativo']->Extra));
        $this->assertNotContains('criado_por', $colunas->keys()->all());
    }

    public function test_indices_unique_em_nome_ativo_e_composto_tipo_ativa(): void
    {
        $indices = collect(DB::select('SHOW INDEX FROM contas'))->groupBy('Key_name');

        $this->assertSame('0', (string) $indices['contas_nome_ativo_unique'][0]->Non_unique);
        $this->assertSame('nome_ativo', $indices['contas_nome_ativo_unique'][0]->Column_name);
        $this->assertSame(['tipo', 'ativa'], $indices['contas_tipo_ativa_index']->pluck('Column_name')->all());
    }

    public function test_banco_impede_nome_duplicado_entre_contas_ativas_mesmo_sem_validacao(): void
    {
        $this->conta('Banco Itaú', 'banco', '0');

        $this->expectException(QueryException::class);
        $this->conta('BANCO ITAU', 'banco', '0');
    }

    public function test_banco_libera_o_nome_apos_soft_delete(): void
    {
        $primeira = $this->conta('Caixa X', 'caixa', '0');
        $primeira->delete();

        $this->assertNull(DB::table('contas')->where('id', $primeira->id)->value('nome_ativo'));

        $segunda = $this->conta('Caixa X', 'caixa', '0');
        $this->assertSame('Caixa X', DB::table('contas')->where('id', $segunda->id)->value('nome_ativo'));
    }

    public function test_check_do_banco_impede_caixa_negativo_mesmo_sem_validacao(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('contas_caixa_saldo_inicial_nao_negativo');

        DB::table('contas')->insert(['nome' => 'Caixa Neg', 'tipo' => 'caixa', 'saldo_inicial' => '-0.01']);
    }

    public function test_check_do_banco_impede_update_que_torne_caixa_negativo(): void
    {
        $caixa = $this->conta('Caixa Y', 'caixa', '10.00');

        $this->expectException(QueryException::class);
        DB::table('contas')->where('id', $caixa->id)->update(['saldo_inicial' => '-5.00']);
    }

    public function test_check_do_banco_permite_banco_negativo(): void
    {
        $conta = $this->conta('Banco Neg', 'banco', '-0.01');

        $this->assertSame('-0.01', $conta->fresh()->saldo_inicial);
    }

    public function test_decimal_rejeita_estouro_e_preserva_extremos_exatos(): void
    {
        $max = $this->conta('Max', 'banco', '999999999999.99');
        $min = $this->conta('Min', 'banco', '-999999999999.99');

        $this->assertSame('999999999999.99', $max->fresh()->saldo_inicial);
        $this->assertSame('-999999999999.99', $min->fresh()->saldo_inicial);

        $this->expectException(QueryException::class);
        DB::table('contas')->insert(['nome' => 'Estouro', 'tipo' => 'banco', 'saldo_inicial' => '1000000000000.00']);
    }

    public function test_soma_no_banco_e_exata(): void
    {
        $this->conta('S1', 'banco', '0.10');
        $this->conta('S2', 'banco', '0.20');

        $this->assertSame('0.30', (string) DB::table('contas')->selectRaw('SUM(saldo_inicial) AS s')->value('s'));
    }
}
