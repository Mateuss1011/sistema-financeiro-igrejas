<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PerfilSlug;
use App\Models\Conta;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Saldo total/por conta (sempre via SaldoService) e situação do período (PeriodoFinanceiroService). */
class SaldoEPeriodoDoDashboardTest extends TestCase
{
    use RefreshDatabase, CenarioDashboard;

    private function saldos(): SaldoService
    {
        return app(SaldoService::class);
    }

    public function test_mes_corrente_usa_o_saldo_atual_do_saldo_service(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '100.00');
        $b = $this->conta('Caixa B', 'caixa', '50.00');
        $categoria = $this->categoria();
        $this->entrada($a, $categoria, $autor, '25.00', $this->mesAtual() . '-01');
        $this->despesaPaga($b, $this->categoriaDespesa(), $autor, ['valor' => '10.00', 'data_competencia' => $this->mesAtual() . '-01', 'data_pagamento' => $this->mesAtual() . '-01']);

        $resposta = $this->dashboardApi($autor)->assertOk();

        $resposta->assertJsonPath('data.saldo.referencia', 'atual');
        $resposta->assertJsonPath('data.saldo.total', '165.00');
        $this->assertSame($this->saldos()->saldoAtual($a->fresh()), collect($resposta->json('data.saldo.contas'))->firstWhere('id', $a->id)['saldo']);
        $this->assertSame($this->saldos()->saldoAtual($b->fresh()), collect($resposta->json('data.saldo.contas'))->firstWhere('id', $b->id)['saldo']);
        $this->assertSame('125.00', collect($resposta->json('data.saldo.contas'))->firstWhere('id', $a->id)['saldo']);
        $this->assertSame('40.00', collect($resposta->json('data.saldo.contas'))->firstWhere('id', $b->id)['saldo']);
    }

    public function test_informar_o_mes_corrente_explicitamente_equivale_a_omitir(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $this->conta('Banco X', 'banco', '77.00');

        $this->assertSame(
            $this->dashboardApi($autor)->json('data'),
            $this->dashboardApi($autor, ['ano_mes' => $this->mesAtual()])->json('data')
        );
    }

    public function test_mes_passado_usa_saldo_ate_o_ultimo_dia_do_mes(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $mes = $this->mesPassado(3);
        $conta = $this->conta('Banco H', 'banco', '1000.00');
        $categoria = $this->categoria();
        $catD = $this->categoriaDespesa();

        $this->entrada($conta, $categoria, $autor, '200.00', $mes . '-10');                       // dentro
        $this->entrada($conta, $categoria, $autor, '300.00', $this->mesSeguinte($mes) . '-01');   // depois do corte
        $this->despesaPaga($conta, $catD, $autor, ['valor' => '50.00', 'data_competencia' => $mes . '-05', 'data_pagamento' => $this->ultimoDiaDe($mes)]); // no último dia: conta
        $this->despesaPaga($conta, $catD, $autor, ['valor' => '70.00', 'data_competencia' => $mes . '-05', 'data_pagamento' => $this->mesSeguinte($mes) . '-02']); // depois: não conta

        $resposta = $this->dashboardApi($autor, ['ano_mes' => $mes])->assertOk();

        $resposta->assertJsonPath('data.saldo.referencia', $this->ultimoDiaDe($mes));
        $resposta->assertJsonPath('data.saldo.total', '1150.00');
        $this->assertSame('1150.00', $this->saldos()->saldoAte($conta->fresh(), $this->ultimoDiaDe($mes)));
        $this->assertNotSame($this->saldos()->saldoAtual($conta->fresh()), collect($resposta->json('data.saldo.contas'))->firstWhere('id', $conta->id)['saldo']);
    }

    public function test_saldo_total_e_a_soma_exata_dos_saldos_por_conta(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $this->conta('C1', 'banco', '0.10');
        $this->conta('C2', 'banco', '0.20');
        $this->conta('C3', 'banco', '-50.05');
        $this->conta('C4', 'caixa', '1000.00');

        $resposta = $this->dashboardApi($autor)->assertOk();

        $soma = '0.00';
        foreach ($resposta->json('data.saldo.contas') as $conta) {
            $soma = bcadd($soma, $conta['saldo'], 2);
        }
        $this->assertSame('950.25', $soma);
        $resposta->assertJsonPath('data.saldo.total', '950.25');
    }

    public function test_contas_inativas_entram_e_excluidas_nao_entram_e_a_ordem_e_tipo_e_nome(): void
    {
        $autor = $this->como(PerfilSlug::Pastor);
        $this->conta('Zeta', 'banco', '10.00');
        $this->conta('Alfa', 'banco', '20.00');
        $this->conta('Inativa com saldo', 'caixa', '30.00', false);
        $excluida = $this->conta('Excluida', 'banco', '999.00');
        $excluida->delete();

        $resposta = $this->dashboardApi($autor)->assertOk();

        $nomes = array_column($resposta->json('data.saldo.contas'), 'nome');
        $this->assertSame(['Alfa', 'Zeta', 'Inativa com saldo'], $nomes);
        $inativa = collect($resposta->json('data.saldo.contas'))->firstWhere('nome', 'Inativa com saldo');
        $this->assertFalse($inativa['ativa']);
        $this->assertSame('caixa', $inativa['tipo']);
        $resposta->assertJsonPath('data.saldo.total', '60.00');
        $this->assertSame(1, Conta::onlyTrashed()->count());
    }

    public function test_cada_conta_traz_somente_id_nome_tipo_ativa_e_saldo(): void
    {
        $this->conta('Banco Campos', 'banco', '5.00');

        $conta = $this->dashboardApi($this->como(PerfilSlug::Pastor))->assertOk()->json('data.saldo.contas.0');

        $this->assertSame(['ativa', 'id', 'nome', 'saldo', 'tipo'], collect(array_keys($conta))->sort()->values()->all());
        $this->assertIsString($conta['saldo']);
    }

    public function test_sem_contas_o_saldo_e_zero_e_a_lista_vazia(): void
    {
        $this->dashboardApi($this->como(PerfilSlug::Pastor))->assertOk()
            ->assertJsonPath('data.saldo.total', '0.00')
            ->assertJsonPath('data.saldo.contas', []);
    }

    // ---------------------------------------------------------------- período

    public function test_periodo_sem_linha_e_aberto_fechado_apos_fechar_e_aberto_apos_reabrir(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $mes = $this->mesPassado(4);

        $this->dashboardApi($pastor, ['ano_mes' => $mes])->assertOk()
            ->assertJsonPath('data.periodo', ['ano_mes' => $mes, 'status' => 'aberto']);

        $this->fecharApi($pastor, $mes)->assertOk();
        $this->dashboardApi($pastor, ['ano_mes' => $mes])->assertJsonPath('data.periodo.status', 'fechado');

        $this->reabrirApi($pastor, $mes)->assertOk();
        $this->dashboardApi($pastor, ['ano_mes' => $mes])->assertJsonPath('data.periodo.status', 'aberto');
    }

    public function test_situacao_do_periodo_e_por_mes_e_nao_vaza_para_os_vizinhos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $mes = $this->mesPassado(4);
        $this->fecharApi($pastor, $mes)->assertOk();

        $this->dashboardApi($pastor, ['ano_mes' => $this->mesAnterior($mes)])->assertJsonPath('data.periodo.status', 'aberto');
        $this->dashboardApi($pastor, ['ano_mes' => $this->mesSeguinte($mes)])->assertJsonPath('data.periodo.status', 'aberto');
        $this->dashboardApi($pastor, ['ano_mes' => $mes])->assertJsonPath('data.periodo.status', 'fechado');
    }

    public function test_periodo_corrente_fechado_aparece_como_fechado_sem_ano_mes(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $this->fecharApi($tesoureiro, $this->mesAtual())->assertOk();

        $this->dashboardApi($tesoureiro)->assertOk()
            ->assertJsonPath('data.periodo', ['ano_mes' => $this->mesAtual(), 'status' => 'fechado']);
    }

    public function test_administrador_e_tesoureiro_veem_a_situacao_do_periodo(): void
    {
        $this->fecharApi($this->como(PerfilSlug::Pastor), $this->mesAtual())->assertOk();

        foreach ([PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $this->dashboardApi($this->como($perfil))->assertJsonPath('data.periodo.status', 'fechado');
        }
    }
}
