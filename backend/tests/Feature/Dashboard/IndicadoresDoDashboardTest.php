<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PerfilSlug;
use App\Models\Despesa;
use App\Models\Entrada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Regras de cálculo aprovadas: entradas por data_competencia, despesas pagas por data_pagamento, pendentes. */
class IndicadoresDoDashboardTest extends TestCase
{
    use RefreshDatabase, CenarioDashboard;

    private string $mes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mes = $this->mesPassado();
    }

    private function painel(array $query = [], PerfilSlug $perfil = PerfilSlug::Pastor)
    {
        return $this->dashboardApi($this->como($perfil), $query + ['ano_mes' => $this->mes]);
    }

    public function test_mes_sem_lancamentos_devolve_zeros_formatados(): void
    {
        $this->painel()->assertOk()
            ->assertJsonPath('data.entradas.total', '0.00')
            ->assertJsonPath('data.despesas_pagas.total', '0.00')
            ->assertJsonPath('data.despesas_pendentes.quantidade', 0)
            ->assertJsonPath('data.despesas_pendentes.valor', '0.00')
            ->assertJsonPath('data.saldo.total', '0.00');
    }

    // ---------------------------------------------------------------- entradas

    public function test_entradas_somam_por_data_competencia_dentro_do_mes_inclusive_nas_bordas(): void
    {
        $autor = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $anterior = $this->mesAnterior($this->mes);
        $seguinte = $this->mesSeguinte($this->mes);

        $this->entrada($conta, $categoria, $autor, '10.00', $anterior . '-' . substr($this->ultimoDiaDe($anterior), 8)); // último dia do mês anterior: fora
        $this->entrada($conta, $categoria, $autor, '20.00', $this->mes . '-01');                                          // primeiro dia: dentro
        $this->entrada($conta, $categoria, $autor, '30.50', $this->mes . '-15');                                          // meio: dentro
        $this->entrada($conta, $categoria, $autor, '40.25', $this->ultimoDiaDe($this->mes));                              // último dia: dentro
        $this->entrada($conta, $categoria, $autor, '99.00', $seguinte . '-01');                                           // primeiro dia do seguinte: fora

        $this->painel()->assertOk()->assertJsonPath('data.entradas.total', '90.75');
    }

    public function test_entrada_estornada_nao_conta_e_seu_estorno_nao_subtrai_de_novo(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $this->entrada($conta, $categoria, $autor, '100.00', $this->mes . '-10');
        $estornada = $this->entrada($conta, $categoria, $autor, '60.00', $this->mes . '-11', ['status' => 'estornada']);
        $this->entrada($conta, $categoria, $autor, '60.00', $this->mes . '-11', ['entrada_estornada_id' => $estornada->id, 'motivo_estorno' => 'setup']);

        $this->painel()->assertOk()->assertJsonPath('data.entradas.total', '100.00');
    }

    public function test_entradas_nao_dependem_da_data_de_criacao_do_registro(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $entrada = $this->entrada($this->conta(), $this->categoria(), $autor, '55.00', $this->mes . '-05');
        // created_at hoje (fora do mês consultado) não interfere: vale data_competencia.
        $this->assertTrue($entrada->created_at->format('Y-m') !== $this->mes);

        $this->painel()->assertOk()->assertJsonPath('data.entradas.total', '55.00');
    }

    public function test_precisao_decimal_sem_erro_de_ponto_flutuante(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        foreach (['0.10', '0.20', '0.10', '0.20', '0.10', '0.20', '0.10'] as $valor) {
            $this->entrada($conta, $categoria, $autor, $valor, $this->mes . '-02');
        }
        $this->entrada($conta, $categoria, $autor, '999999999999.99', $this->mes . '-03');

        $this->painel()->assertOk()->assertJsonPath('data.entradas.total', '1000000000000.99');
    }

    // ---------------------------------------------------------------- despesas pagas

    public function test_despesas_pagas_somam_por_data_pagamento_nao_por_competencia(): void
    {
        $autor = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco P', 'banco', '5000.00');
        $categoria = $this->categoriaDespesa();
        $anterior = $this->mesAnterior($this->mes);
        $seguinte = $this->mesSeguinte($this->mes);

        // competência FORA do mês, pagamento DENTRO: conta.
        $this->despesaPaga($conta, $categoria, $autor, ['valor' => '70.00', 'data_competencia' => $anterior . '-20', 'data_pagamento' => $this->mes . '-03']);
        // competência DENTRO do mês, pagamento FORA: não conta.
        $this->despesaPaga($conta, $categoria, $autor, ['valor' => '300.00', 'data_competencia' => $this->mes . '-20', 'data_pagamento' => $seguinte . '-02']);
        // ambas dentro, nas bordas.
        $this->despesaPaga($conta, $categoria, $autor, ['valor' => '11.11', 'data_competencia' => $this->mes . '-01', 'data_pagamento' => $this->mes . '-01']);
        $this->despesaPaga($conta, $categoria, $autor, ['valor' => '22.22', 'data_competencia' => $this->mes . '-28', 'data_pagamento' => $this->ultimoDiaDe($this->mes)]);

        $this->painel()->assertOk()->assertJsonPath('data.despesas_pagas.total', '103.33');
    }

    public function test_despesa_estornada_nao_conta_como_paga(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco E', 'banco', '5000.00');
        $categoria = $this->categoriaDespesa();
        $this->despesaPaga($conta, $categoria, $autor, ['valor' => '40.00', 'data_competencia' => $this->mes . '-10', 'data_pagamento' => $this->mes . '-10']);
        $estornada = $this->despesaEstornada($conta, $categoria, $autor);
        $estornada->update(['data_competencia' => $this->mes . '-12', 'data_pagamento' => $this->mes . '-12']);
        DB::table('despesas')->where('despesa_estornada_id', $estornada->id)->update(['data_competencia' => $this->mes . '-12', 'data_pagamento' => $this->mes . '-12']);

        $this->painel()->assertOk()->assertJsonPath('data.despesas_pagas.total', '40.00');
    }

    public function test_pendente_e_cancelada_nunca_entram_no_total_de_pagas(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $this->despesaPendente($categoria, $autor, ['valor' => '80.00', 'data_competencia' => $this->mes . '-10']);
        $this->despesaCancelada($categoria, $autor, ['valor' => '90.00', 'data_competencia' => $this->mes . '-10']);

        $this->painel()->assertOk()->assertJsonPath('data.despesas_pagas.total', '0.00');
    }

    // ---------------------------------------------------------------- pendentes

    public function test_pendentes_contam_quantidade_e_valor_pela_competencia_no_mes(): void
    {
        $autor = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoriaDespesa();
        $anterior = $this->mesAnterior($this->mes);
        $seguinte = $this->mesSeguinte($this->mes);

        $this->despesaPendente($categoria, $autor, ['valor' => '10.10', 'data_competencia' => $this->mes . '-01']);
        $this->despesaPendente($categoria, $autor, ['valor' => '20.20', 'data_competencia' => $this->ultimoDiaDe($this->mes)]);
        $this->despesaPendente($categoria, $autor, ['valor' => '500.00', 'data_competencia' => $anterior . '-30']);   // fora
        $this->despesaPendente($categoria, $autor, ['valor' => '600.00', 'data_competencia' => $seguinte . '-01']);   // fora
        $this->despesaCancelada($categoria, $autor, ['valor' => '700.00', 'data_competencia' => $this->mes . '-10']); // cancelada: fora
        $this->despesaPaga($conta, $categoria, $autor, ['valor' => '800.00', 'data_competencia' => $this->mes . '-10', 'data_pagamento' => $this->mes . '-10']); // paga: fora

        $this->painel()->assertOk()
            ->assertJsonPath('data.despesas_pendentes.quantidade', 2)
            ->assertJsonPath('data.despesas_pendentes.valor', '30.30');
    }

    public function test_despesa_paga_deixa_de_ser_pendente(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $autor, ['valor' => '50.00', 'data_competencia' => $this->mes . '-10']);
        $this->painel()->assertJsonPath('data.despesas_pendentes.quantidade', 1);

        $despesa->update(['status' => 'paga', 'conta_id' => $this->conta()->id, 'data_pagamento' => $this->mes . '-11', 'pago_por' => $autor->id, 'pago_em' => now()]);

        $this->painel()->assertOk()
            ->assertJsonPath('data.despesas_pendentes.quantidade', 0)
            ->assertJsonPath('data.despesas_pagas.total', '50.00');
    }

    // ---------------------------------------------------------------- consistência com o SaldoService

    public function test_totais_batem_com_o_liquido_originais_menos_estornos_do_ledger(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco L', 'banco', '0.00');
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();

        $this->entrada($conta, $catE, $autor, '100.00', $this->mes . '-03');
        $e2 = $this->entrada($conta, $catE, $autor, '35.00', $this->mes . '-04', ['status' => 'estornada']);
        $this->entrada($conta, $catE, $autor, '35.00', $this->mes . '-04', ['entrada_estornada_id' => $e2->id, 'motivo_estorno' => 'setup']);
        $this->entrada($conta, $catE, $autor, '12.34', $this->mes . '-20');

        $this->despesaPaga($conta, $catD, $autor, ['valor' => '15.15', 'data_competencia' => $this->mes . '-05', 'data_pagamento' => $this->mes . '-05']);
        $d2 = $this->despesaPaga($conta, $catD, $autor, ['valor' => '44.00', 'data_competencia' => $this->mes . '-06', 'data_pagamento' => $this->mes . '-06', 'status' => 'estornada']);
        Despesa::create([
            'categoria_id' => $catD->id, 'conta_id' => $conta->id, 'valor' => '44.00',
            'data_competencia' => $this->mes . '-06', 'data_pagamento' => $this->mes . '-06',
            'status' => 'paga', 'despesa_estornada_id' => $d2->id, 'motivo_estorno' => 'setup', 'criado_por' => $autor->id,
        ]);

        $inicio = $this->mes . '-01';
        $fim = $this->ultimoDiaDe($this->mes);
        // Fórmula "linha a linha" do SaldoService: original soma, linha de estorno subtrai.
        $liquidoEntradas = (string) DB::table('entradas')->whereBetween('data_competencia', [$inicio, $fim])
            ->selectRaw('SUM(CASE WHEN entrada_estornada_id IS NULL THEN valor ELSE -valor END) AS t')->value('t');
        $liquidoDespesas = (string) DB::table('despesas')->whereNotNull('conta_id')->whereBetween('data_pagamento', [$inicio, $fim])
            ->selectRaw('SUM(CASE WHEN despesa_estornada_id IS NULL THEN valor ELSE -valor END) AS t')->value('t');

        $this->assertSame('112.34', bcadd($liquidoEntradas, '0', 2));
        $this->assertSame('15.15', bcadd($liquidoDespesas, '0', 2));
        $this->painel()->assertOk()
            ->assertJsonPath('data.entradas.total', bcadd($liquidoEntradas, '0', 2))
            ->assertJsonPath('data.despesas_pagas.total', bcadd($liquidoDespesas, '0', 2));
    }

    public function test_transferencias_e_ajustes_nao_alteram_entradas_nem_despesas_mas_alteram_o_saldo(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Conta A', 'banco', '1000.00');
        $b = $this->conta('Conta B', 'banco', '0.00');
        $this->transferenciaDireta($a, $b, $autor, '250.00', $this->mes . '-10');
        $this->ajusteDireto($a, $autor, '30.00', 'debito', ['data_ajuste' => $this->mes . '-11']);

        $resposta = $this->painel()->assertOk()
            ->assertJsonPath('data.entradas.total', '0.00')
            ->assertJsonPath('data.despesas_pagas.total', '0.00')
            ->assertJsonPath('data.saldo.total', '970.00');
        $porNome = collect($resposta->json('data.saldo.contas'))->pluck('saldo', 'nome')->all();
        $this->assertSame(['Conta A' => '720.00', 'Conta B' => '250.00'], $porNome);
    }

    public function test_um_lancamento_novo_e_refletido_na_proxima_consulta(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $this->painel()->assertJsonPath('data.entradas.total', '0.00');

        $this->entrada($conta, $categoria, $autor, '15.00', $this->mes . '-10');

        $this->painel()->assertJsonPath('data.entradas.total', '15.00');
        $this->assertSame(1, Entrada::count());
    }
}
