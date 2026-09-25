<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PerfilSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Auxiliar só enxerga os lançamentos que ele mesmo criou (plano §4; EntradaPolicy/DespesaPolicy::viewAll e o
 * filtro `criado_por` das listagens). O dashboard parcial segue exatamente a mesma regra — nenhuma regra nova.
 */
class EscopoDoAuxiliarNoDashboardTest extends TestCase
{
    use RefreshDatabase, CenarioDashboard;

    private string $mes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mes = $this->mesPassado();
    }

    public function test_entradas_pagas_e_pendentes_do_auxiliar_so_contam_as_dele(): void
    {
        $eu = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $outro = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Aux', 'banco', '5000.00');
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();

        $this->entrada($conta, $catE, $eu, '10.00', $this->mes . '-05');
        $this->entrada($conta, $catE, $outro, '200.00', $this->mes . '-05');
        $this->entrada($conta, $catE, $pastor, '3000.00', $this->mes . '-05');

        $this->despesaPaga($conta, $catD, $eu, ['valor' => '4.00', 'data_competencia' => $this->mes . '-06', 'data_pagamento' => $this->mes . '-06']);
        $this->despesaPaga($conta, $catD, $outro, ['valor' => '500.00', 'data_competencia' => $this->mes . '-06', 'data_pagamento' => $this->mes . '-06']);

        $this->despesaPendente($catD, $eu, ['valor' => '7.50', 'data_competencia' => $this->mes . '-07']);
        $this->despesaPendente($catD, $eu, ['valor' => '2.50', 'data_competencia' => $this->mes . '-08']);
        $this->despesaPendente($catD, $outro, ['valor' => '900.00', 'data_competencia' => $this->mes . '-07']);
        $this->despesaPendente($catD, $pastor, ['valor' => '800.00', 'data_competencia' => $this->mes . '-07']);

        $this->dashboardApi($eu, ['ano_mes' => $this->mes])->assertOk()
            ->assertJsonPath('data.escopo', 'proprios')
            ->assertJsonPath('data.entradas.total', '10.00')
            ->assertJsonPath('data.despesas_pagas.total', '4.00')
            ->assertJsonPath('data.despesas_pendentes.quantidade', 2)
            ->assertJsonPath('data.despesas_pendentes.valor', '10.00');
    }

    public function test_perfis_completos_somam_tudo_de_todos_os_criadores(): void
    {
        $eu = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $outro = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Todos', 'banco', '5000.00');
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();

        $this->entrada($conta, $catE, $eu, '10.00', $this->mes . '-05');
        $this->entrada($conta, $catE, $outro, '200.00', $this->mes . '-05');
        $this->entrada($conta, $catE, $pastor, '3000.00', $this->mes . '-05');
        $this->despesaPendente($catD, $eu, ['valor' => '7.50', 'data_competencia' => $this->mes . '-07']);
        $this->despesaPendente($catD, $outro, ['valor' => '900.00', 'data_competencia' => $this->mes . '-07']);

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $this->dashboardApi($this->como($perfil), ['ano_mes' => $this->mes])->assertOk()
                ->assertJsonPath('data.entradas.total', '3210.00')
                ->assertJsonPath('data.despesas_pendentes.quantidade', 2)
                ->assertJsonPath('data.despesas_pendentes.valor', '907.50');
        }
    }

    public function test_estorno_feito_por_outro_usuario_de_lancamento_do_auxiliar_e_descontado_do_total_dele(): void
    {
        $eu = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Est', 'banco', '5000.00');
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();

        // Entrada do auxiliar (estornada pelo Pastor: a linha de estorno tem criado_por = Pastor).
        $this->entrada($conta, $catE, $eu, '100.00', $this->mes . '-05');
        $estornada = $this->entrada($conta, $catE, $eu, '60.00', $this->mes . '-06', ['status' => 'estornada']);
        $this->entrada($conta, $catE, $pastor, '60.00', $this->mes . '-06', ['entrada_estornada_id' => $estornada->id, 'motivo_estorno' => 'setup']);
        // Despesa paga do auxiliar, também estornada pelo Pastor.
        $this->despesaPaga($conta, $catD, $eu, ['valor' => '30.00', 'data_competencia' => $this->mes . '-07', 'data_pagamento' => $this->mes . '-07']);
        $despesa = $this->despesaEstornada($conta, $catD, $pastor);
        $despesa->update(['criado_por' => $eu->id, 'data_competencia' => $this->mes . '-08', 'data_pagamento' => $this->mes . '-08', 'valor' => '45.00']);
        \Illuminate\Support\Facades\DB::table('despesas')->where('despesa_estornada_id', $despesa->id)
            ->update(['data_competencia' => $this->mes . '-08', 'data_pagamento' => $this->mes . '-08', 'valor' => '45.00']);

        $this->dashboardApi($eu, ['ano_mes' => $this->mes])->assertOk()
            ->assertJsonPath('data.entradas.total', '100.00')
            ->assertJsonPath('data.despesas_pagas.total', '30.00');
    }

    public function test_auxiliar_sem_lancamentos_proprios_ve_zeros_mesmo_com_muito_movimento_de_outros(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Mov', 'banco', '0.00');
        $this->entrada($conta, $this->categoria(), $pastor, '9999.00', $this->mes . '-05');
        $this->despesaPendente($this->categoriaDespesa(), $pastor, ['valor' => '888.00', 'data_competencia' => $this->mes . '-05']);

        $this->dashboardApi($this->como(PerfilSlug::AuxiliarFinanceiro), ['ano_mes' => $this->mes])->assertOk()
            ->assertJsonPath('data.entradas.total', '0.00')
            ->assertJsonPath('data.despesas_pagas.total', '0.00')
            ->assertJsonPath('data.despesas_pendentes.quantidade', 0)
            ->assertJsonPath('data.despesas_pendentes.valor', '0.00');
    }

    public function test_o_escopo_do_dashboard_e_o_mesmo_das_listagens_do_auxiliar(): void
    {
        $eu = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $outro = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $conta = $this->conta('Banco Lst', 'banco', '0.00');
        $categoria = $this->categoria();
        $this->entrada($conta, $categoria, $eu, '11.00', $this->mes . '-05');
        $this->entrada($conta, $categoria, $eu, '22.00', $this->mes . '-06');
        $this->entrada($conta, $categoria, $outro, '500.00', $this->mes . '-06');

        $listagem = $this->actingAs($eu)->getJson('/api/v1/entradas?' . http_build_query(['data_de' => $this->mes . '-01', 'data_ate' => $this->ultimoDiaDe($this->mes)]))->assertOk()->json('data');
        $somaListagem = '0.00';
        foreach ($listagem as $linha) {
            $somaListagem = bcadd($somaListagem, $linha['valor'], 2);
        }

        $this->assertSame('33.00', $somaListagem);
        $this->dashboardApi($eu, ['ano_mes' => $this->mes])->assertJsonPath('data.entradas.total', $somaListagem);
    }
}
