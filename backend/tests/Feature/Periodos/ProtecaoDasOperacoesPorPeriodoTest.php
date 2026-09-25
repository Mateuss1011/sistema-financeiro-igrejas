<?php

namespace Tests\Feature\Periodos;

use App\Enums\PerfilSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 9: liga o fechamento/reabertura (via API real, não `fecharPeriodo()` cru) ao mecanismo de
 * proteção que já existe desde as Fases 6–8. Entradas só têm criação + estorno (imutáveis, sem
 * edição/pagamento/cancelamento/exclusão — isso nunca existiu no EntradaService).
 */
class ProtecaoDasOperacoesPorPeriodoTest extends TestCase
{
    use RefreshDatabase, CenarioPeriodos;

    // ---------------- entradas ----------------

    public function test_criar_entrada_bloqueada_em_periodo_fechado_e_liberada_apos_reabrir(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Pe1');
        $categoria = $this->categoria('Dízimo Pe1');
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-03-10']))
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertDatabaseCount('entradas', 0);

        $this->reabrirApi($pastor, '2026-03')->assertOk();
        $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-03-10']))
            ->assertStatus(201);
    }

    public function test_estornar_entrada_bloqueado_pela_competencia_da_original(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Pe2', 'banco', '500.00');
        $categoria = $this->categoria('Dízimo Pe2');
        $entrada = $this->entrada($conta, $categoria, $pastor, '50.00', '2026-03-10');

        $this->fecharApi($pastor, '2026-03')->assertOk();
        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$entrada->id}/estornar", ['justificativa' => 'Lançada errada'])
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        $this->reabrirApi($pastor, '2026-03')->assertOk();
        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$entrada->id}/estornar", ['justificativa' => 'Lançada errada'])
            ->assertStatus(201);
    }

    // ---------------- despesas ----------------

    public function test_criar_despesa_bloqueada_em_periodo_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('Energia Pe3');
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->actingAs($pastor)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => '2026-03-10']))
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertDatabaseCount('despesas', 0);
    }

    public function test_editar_despesa_bloqueada_pela_competencia_atual_e_pela_nova(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('Energia Pe4');
        $pendenteEmMesFechado = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-03-10']);
        $pendenteEmMesAberto = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-05-10']);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        // Competência ATUAL da despesa está fechada.
        $this->actingAs($pastor)->putJson("/api/v1/despesas/{$pendenteEmMesFechado->id}", ['valor' => '9.99'])
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        // Competência atual aberta, mas a NOVA data cai num mês fechado (exercita garantirAbertoTodos
        // com dois ano_mes distintos na mesma transação).
        $this->actingAs($pastor)->putJson("/api/v1/despesas/{$pendenteEmMesAberto->id}", ['data_competencia' => '2026-03-15'])
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertSame('2026-05-10', $pendenteEmMesAberto->fresh()->data_competencia->format('Y-m-d'));
    }

    public function test_pagar_despesa_bloqueado_pela_competencia_ou_pelo_pagamento_em_meses_diferentes(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Pe5', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa('Energia Pe5');
        $this->fecharApi($pastor, '2026-03')->assertOk();

        // Competência em mês fechado (03), pagamento num mês aberto (05).
        $competenciaFechada = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-03-05']);
        $this->pagar($pastor, $competenciaFechada, $this->corpoPagamento($conta, ['data_pagamento' => '2026-05-10']))
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        // Competência aberta (05), pagamento num mês fechado (03) — exercita os dois ano_mes distintos.
        $pagamentoFechado = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-05-05']);
        $this->pagar($pastor, $pagamentoFechado, $this->corpoPagamento($conta, ['data_pagamento' => '2026-03-10']))
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        $this->assertSame('pendente', $this->statusDe($competenciaFechada->fresh()));
        $this->assertSame('pendente', $this->statusDe($pagamentoFechado->fresh()));

        // Com os dois meses abertos, paga normalmente.
        $ambosAbertos = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-05-06']);
        $this->pagar($pastor, $ambosAbertos, $this->corpoPagamento($conta, ['data_pagamento' => '2026-05-20']))->assertStatus(200);
    }

    public function test_cancelar_despesa_bloqueado_em_periodo_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('Energia Pe6');
        $pendente = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-03-05']);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->cancelar($pastor, $pendente)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertSame('pendente', $this->statusDe($pendente->fresh()));
    }

    public function test_estornar_despesa_paga_bloqueado_so_pela_competencia_nao_pelo_pagamento(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Pe7', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa('Energia Pe7');
        $paga = $this->despesaPaga($conta, $categoria, $pastor, ['data_competencia' => '2026-03-05', 'data_pagamento' => '2026-03-08']);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->estornarDespesa($pastor, $paga)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        // Competência em mês aberto, pagamento em mês fechado: estorno NÃO é bloqueado (regra já
        // decidida na Fase 7 — só a competência conta no estorno). Prova de regressão, não regra nova.
        $outra = $this->despesaPaga($conta, $categoria, $pastor, ['data_competencia' => '2026-05-05', 'data_pagamento' => '2026-03-08']);
        $this->estornarDespesa($pastor, $outra)->assertStatus(201);
    }

    public function test_excluir_despesa_bloqueado_mesmo_para_o_pastor(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('Energia Pe8');
        $pendente = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-03-05']);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        // O Pastor só ignora "outro criador"/"janela de 48h" — período fechado nunca é ignorado.
        $this->actingAs($pastor)->deleteJson("/api/v1/despesas/{$pendente->id}")
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertDatabaseHas('despesas', ['id' => $pendente->id]);
    }

    // ---------------- transferências ----------------

    public function test_criar_e_estornar_transferencia_bloqueadas_em_periodo_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Banco Pe9', 'banco', '1000.00');
        $destino = $this->conta('Caixa Pe9', 'caixa', '0.00');
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->transferir($pastor, $origem, $destino, ['data_transferencia' => '2026-03-10'])
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        $transferencia = $this->transferenciaDireta($origem, $destino, $pastor, '50.00', '2026-05-10');
        $this->fecharApi($pastor, '2026-05')->assertOk();
        $this->estornarTransferencia($pastor, $transferencia)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
    }

    // ---------------- ajustes ----------------

    public function test_criar_ajuste_bloqueado_em_periodo_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Pe10', 'banco', '1000.00');
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->ajustar($pastor, $conta, ['data_ajuste' => '2026-03-10'])
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertDatabaseCount('ajustes_saldo', 0);
    }

    // ---------------- reabertura libera tudo de novo ----------------

    public function test_reabrir_libera_novamente_criacao_de_entrada_despesa_transferencia_e_ajuste(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Pe11', 'banco', '1000.00');
        $destino = $this->conta('Caixa Pe11', 'caixa', '0.00');
        $categoriaEntrada = $this->categoria('Dízimo Pe11');
        $categoriaDespesa = $this->categoriaDespesa('Energia Pe11');
        $this->fecharApi($pastor, '2026-03')->assertOk();
        $this->reabrirApi($pastor, '2026-03', 'Liberar lançamentos de março')->assertOk();

        $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $categoriaEntrada, ['data_competencia' => '2026-03-10']))->assertStatus(201);
        $this->actingAs($pastor)->postJson('/api/v1/despesas', $this->payloadDespesa($categoriaDespesa, ['data_competencia' => '2026-03-10']))->assertStatus(201);
        $this->transferir($pastor, $conta, $destino, ['data_transferencia' => '2026-03-10'])->assertStatus(201);
        $this->ajustar($pastor, $conta, ['data_ajuste' => '2026-03-10'])->assertStatus(201);
    }
}
