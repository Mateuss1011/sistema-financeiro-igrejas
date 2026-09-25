<?php

namespace Tests\Feature\Periodos;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\PeriodoFinanceiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FechamentoEReaberturaDePeriodosTest extends TestCase
{
    use RefreshDatabase, CenarioPeriodos;

    // ---------------- listagem ----------------

    public function test_listagem_inclui_o_mes_corrente_sintetico_quando_nunca_foi_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $mesAtual = now('America/Sao_Paulo')->format('Y-m');

        $resposta = $this->listarPeriodosApi($pastor)->assertOk();
        $linha = collect($resposta->json('data'))->firstWhere('ano_mes', $mesAtual);

        $this->assertNotNull($linha);
        $this->assertNull($linha['id']);
        $this->assertSame('aberto', $linha['status']);
        $this->assertTrue($resposta->json('meta.permissoes.fechar'));
        $this->assertTrue($resposta->json('meta.permissoes.reabrir'));
    }

    public function test_listagem_traz_permissoes_corretas_para_tesoureiro_e_administrador(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $admin = $this->como(PerfilSlug::Administrador);

        $this->listarPeriodosApi($tesoureiro)->assertOk()
            ->assertJsonPath('meta.permissoes.fechar', true)
            ->assertJsonPath('meta.permissoes.reabrir', false);

        $this->listarPeriodosApi($admin)->assertOk()
            ->assertJsonPath('meta.permissoes.fechar', false)
            ->assertJsonPath('meta.permissoes.reabrir', false);
    }

    // ---------------- A. período ----------------

    public function test_fechar_periodo_nunca_tocado_cria_a_linha(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->assertSame('aberto', $this->statusPeriodo('2026-03'));

        $resposta = $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->assertSame('fechado', $resposta->json('data.status'));
        $this->assertSame($pastor->id, $resposta->json('data.fechado_por.id'));
        $this->assertNotNull($resposta->json('data.fechado_em'));
        $this->assertNull($resposta->json('data.reaberto_por'));
        $this->assertSame('fechado', $this->statusPeriodo('2026-03'));
    }

    public function test_fechar_periodo_ja_fechado_retorna_409(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->fecharApi($pastor, '2026-03')->assertStatus(409)->assertJsonPath('code', 'PERIODO_JA_FECHADO');
        $this->assertSame(1, PeriodoFinanceiro::where('ano_mes', '2026-03')->count());
    }

    public function test_reabrir_periodo_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $resposta = $this->reabrirApi($pastor, '2026-03', 'Precisa lançar uma despesa esquecida')->assertOk();

        $this->assertSame('aberto', $resposta->json('data.status'));
        $this->assertSame($pastor->id, $resposta->json('data.reaberto_por.id'));
        $this->assertSame('Precisa lançar uma despesa esquecida', $resposta->json('data.justificativa_reabertura'));
        // fechado_por/fechado_em do último fechamento são preservados (histórico do ciclo mais recente).
        $this->assertNotNull($resposta->json('data.fechado_por.id'));
        $this->assertNotNull($resposta->json('data.fechado_em'));
        $this->assertSame('aberto', $this->statusPeriodo('2026-03'));
    }

    public function test_reabrir_periodo_ja_aberto_retorna_409(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        // Nunca foi fechado: já está aberto por padrão.
        $this->reabrirApi($pastor, '2026-03')->assertStatus(409)->assertJsonPath('code', 'PERIODO_JA_ABERTO');

        $this->fecharApi($pastor, '2026-04')->assertOk();
        $this->reabrirApi($pastor, '2026-04')->assertOk();
        $this->reabrirApi($pastor, '2026-04')->assertStatus(409)->assertJsonPath('code', 'PERIODO_JA_ABERTO');
    }

    public function test_ano_mes_mal_formatado_na_url_nao_casa_com_a_rota(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->postJson('/api/v1/periodos-financeiros/2026-13/fechar')->assertStatus(404);
        $this->actingAs($pastor)->postJson('/api/v1/periodos-financeiros/2026-9/fechar')->assertStatus(404);
        $this->actingAs($pastor)->postJson('/api/v1/periodos-financeiros/lixo/fechar')->assertStatus(404);
    }

    public function test_fechar_de_novo_apos_reabrir_limpa_os_campos_de_reabertura(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();
        $this->reabrirApi($pastor, '2026-03')->assertOk();

        $this->fecharApi($pastor, '2026-03')->assertOk();

        $periodo = PeriodoFinanceiro::where('ano_mes', '2026-03')->sole();
        $this->assertSame('fechado', $periodo->status->value);
        $this->assertNull($periodo->reaberto_por);
        $this->assertNull($periodo->reaberto_em);
        $this->assertNull($periodo->justificativa_reabertura);
        $this->assertNotNull($periodo->fechado_por);
    }

    public function test_multiplos_ciclos_de_fechamento_e_reabertura_sao_preservados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        for ($i = 0; $i < 3; $i++) {
            $this->fecharApi($pastor, '2026-03')->assertOk();
            $this->reabrirApi($pastor, '2026-03', "Ciclo {$i}")->assertOk();
        }
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->assertSame(1, PeriodoFinanceiro::where('ano_mes', '2026-03')->count());
        $this->assertSame('fechado', $this->statusPeriodo('2026-03'));
        // 4 fechamentos + 3 reaberturas = 7 eventos no histórico imutável, mesmo a tabela guardando só o último ciclo.
        $this->assertSame(7, AuditLog::where('modulo', 'periodos_financeiros')->where('registro_id', PeriodoFinanceiro::where('ano_mes', '2026-03')->value('id'))->count());
    }

    // ---------------- C. justificativa ----------------

    public function test_reabertura_sem_justificativa_retorna_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->actingAs($pastor)->postJson('/api/v1/periodos-financeiros/2026-03/reabrir', [])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');
    }

    public function test_justificativa_vazia_ou_com_2_caracteres_retorna_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->reabrirApi($pastor, '2026-03', '')->assertStatus(422)->assertJsonValidationErrors('justificativa');
        $this->reabrirApi($pastor, '2026-03', 'ab')->assertStatus(422)->assertJsonValidationErrors('justificativa');
    }

    public function test_justificativa_com_3_e_com_500_caracteres_e_aceita(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->fecharApi($pastor, '2026-03')->assertOk();
        $this->reabrirApi($pastor, '2026-03', 'abc')->assertOk();

        $this->fecharApi($pastor, '2026-04')->assertOk();
        $this->reabrirApi($pastor, '2026-04', str_repeat('x', 500))->assertOk();
    }

    public function test_justificativa_com_501_caracteres_retorna_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->reabrirApi($pastor, '2026-03', str_repeat('x', 501))->assertStatus(422)->assertJsonValidationErrors('justificativa');
    }

    public function test_fechamento_nao_aceita_nem_precisa_de_justificativa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        // Fechar não tem body nenhum; mandar um corpo extra não muda nada nem quebra.
        $resposta = $this->actingAs($pastor)->postJson('/api/v1/periodos-financeiros/2026-03/fechar', ['justificativa' => 'ignorada']);
        $resposta->assertOk();
        $this->assertNull(AuditLog::where('modulo', 'periodos_financeiros')->where('acao', 'closed')->sole()->justificativa);
    }

    // ---------------- D. auditoria ----------------

    public function test_fechamento_gera_audit_log_com_ano_mes_usuario_e_estados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $id = $this->fecharApi($pastor, '2026-03')->json('data.id');

        $log = AuditLog::where('modulo', 'periodos_financeiros')->where('acao', 'closed')->sole();
        $this->assertSame($id, $log->registro_id);
        $this->assertSame($pastor->id, $log->user_id);
        $this->assertSame(['status' => 'aberto'], $log->dados_anteriores);
        $this->assertSame(['ano_mes' => '2026-03', 'status' => 'fechado'], $log->dados_novos);
        $this->assertNull($log->justificativa);
        $this->assertNotNull($log->created_at);
    }

    public function test_reabertura_gera_audit_log_com_justificativa_e_estados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $id = $this->fecharApi($pastor, '2026-03')->json('data.id');

        $this->reabrirApi($pastor, '2026-03', 'Correção necessária')->assertOk();

        $log = AuditLog::where('modulo', 'periodos_financeiros')->where('acao', 'reopened')->sole();
        $this->assertSame($id, $log->registro_id);
        $this->assertSame($pastor->id, $log->user_id);
        $this->assertSame(['status' => 'fechado'], $log->dados_anteriores);
        $this->assertSame(['ano_mes' => '2026-03', 'status' => 'aberto'], $log->dados_novos);
        $this->assertSame('Correção necessária', $log->justificativa);
    }

    public function test_rejeicoes_nao_sao_auditadas_como_sucesso(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();
        $totalApos1Fechamento = AuditLog::where('modulo', 'periodos_financeiros')->count();

        $this->fecharApi($pastor, '2026-03')->assertStatus(409);
        $this->reabrirApi($this->como(PerfilSlug::Tesoureiro), '2026-03', 'tentativa')->assertStatus(403);

        $this->assertSame($totalApos1Fechamento, AuditLog::where('modulo', 'periodos_financeiros')->count());
    }

    // ---------------- F. sem cascata ----------------

    public function test_fechar_um_mes_nao_fecha_automaticamente_outro(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-09')->assertOk();

        $this->assertSame('aberto', $this->statusPeriodo('2026-08'));
        $this->assertSame('aberto', $this->statusPeriodo('2026-10'));
    }

    public function test_reabrir_periodo_antigo_nao_obriga_reabrir_nem_fecha_periodos_posteriores(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-08')->assertOk();
        $this->fecharApi($pastor, '2026-09')->assertOk();

        $this->reabrirApi($pastor, '2026-08', 'Correção de um mês antigo')->assertOk();

        $this->assertSame('aberto', $this->statusPeriodo('2026-08'));
        $this->assertSame('fechado', $this->statusPeriodo('2026-09'), 'setembro continua fechado mesmo com agosto reaberto');
    }

    public function test_pastor_pode_reabrir_periodo_muito_antigo_sem_limite(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2019-01')->assertOk();

        $this->reabrirApi($pastor, '2019-01', 'Auditoria fiscal retroativa')->assertOk();
        $this->assertSame('aberto', $this->statusPeriodo('2019-01'));
    }

    public function test_periodos_podem_ser_fechados_fora_de_ordem(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        // Fecha setembro sem fechar agosto antes (decisão D2: ordem livre).
        $this->fecharApi($pastor, '2026-09')->assertOk();
        $this->assertSame('aberto', $this->statusPeriodo('2026-08'));

        $this->fecharApi($pastor, '2026-08')->assertOk();
        $this->assertSame('fechado', $this->statusPeriodo('2026-08'));
        $this->assertSame('fechado', $this->statusPeriodo('2026-09'));
    }
}
