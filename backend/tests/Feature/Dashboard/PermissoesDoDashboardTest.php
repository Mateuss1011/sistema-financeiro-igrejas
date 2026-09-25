<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Matriz aprovada: completo (Pastor/Administrador/Tesoureiro), parcial (Auxiliar), sem acesso (Secretário). */
class PermissoesDoDashboardTest extends TestCase
{
    use RefreshDatabase, CenarioDashboard;

    public function test_pastor_administrador_e_tesoureiro_recebem_o_dashboard_completo(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $resposta = $this->dashboardApi($this->como($perfil))->assertOk();

            $resposta->assertJsonPath('data.visao', 'completo')
                ->assertJsonPath('data.escopo', 'todos')
                ->assertJsonStructure(['data' => ['ano_mes', 'entradas' => ['total'], 'despesas_pagas' => ['total'], 'despesas_pendentes' => ['quantidade', 'valor'], 'saldo' => ['referencia', 'total', 'contas'], 'periodo' => ['ano_mes', 'status']]]);
        }
    }

    public function test_auxiliar_recebe_o_dashboard_parcial_sem_saldo_nem_periodo(): void
    {
        $this->conta('Conta Secreta', 'banco', '9999.00');
        $resposta = $this->dashboardApi($this->como(PerfilSlug::AuxiliarFinanceiro))->assertOk();

        $resposta->assertJsonPath('data.visao', 'parcial')
            ->assertJsonPath('data.escopo', 'proprios')
            ->assertJsonStructure(['data' => ['ano_mes', 'entradas' => ['total'], 'despesas_pagas' => ['total'], 'despesas_pendentes' => ['quantidade', 'valor']]])
            ->assertJsonMissingPath('data.saldo')
            ->assertJsonMissingPath('data.periodo');
        $this->assertSame(
            ['ano_mes', 'despesas_pagas', 'despesas_pendentes', 'entradas', 'escopo', 'visao'],
            collect(array_keys($resposta->json('data')))->sort()->values()->all()
        );
        // Nada do saldo/conta vaza em lugar nenhum da resposta.
        $this->assertStringNotContainsString('Conta Secreta', $resposta->getContent());
        $this->assertStringNotContainsString('9999', $resposta->getContent());
        $this->assertStringNotContainsString('saldo', $resposta->getContent());
    }

    public function test_secretario_nao_tem_acesso(): void
    {
        $this->dashboardApi($this->como(PerfilSlug::Secretario))->assertStatus(403)->assertJsonMissingPath('data');
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_nenhuma_excecao_existente_libera_o_dashboard_para_o_secretario_nem_completa_o_do_auxiliar(): void
    {
        $secretario = $this->comExcecoes(PerfilSlug::Secretario, PermissaoExcecaoChave::valores());
        $this->dashboardApi($secretario)->assertStatus(403);

        $auxiliar = $this->comExcecoes(PerfilSlug::AuxiliarFinanceiro, PermissaoExcecaoChave::valores());
        $this->dashboardApi($auxiliar)->assertOk()
            ->assertJsonPath('data.visao', 'parcial')
            ->assertJsonMissingPath('data.saldo')
            ->assertJsonMissingPath('data.periodo');
    }

    public function test_administrador_sem_excecao_alguma_ve_o_dashboard_completo(): void
    {
        // Consulta não depende de exceção (só operar/estornar dependem).
        $this->dashboardApi($this->como(PerfilSlug::Administrador))->assertOk()->assertJsonPath('data.visao', 'completo');
    }

    public function test_autorizacao_vem_antes_da_validacao_do_filtro(): void
    {
        $secretario = $this->como(PerfilSlug::Secretario);

        $this->dashboardApi($secretario, ['ano_mes' => 'lixo'])->assertStatus(403);
        $this->dashboardApi($secretario, ['ano_mes' => '2999-01'])->assertStatus(403);
    }

    public function test_a_permissao_e_decidida_pelo_backend_nao_pelo_que_o_frontend_envia(): void
    {
        // Parâmetros que tentariam forçar visão/escopo/perfil são simplesmente ignorados.
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);

        $this->dashboardApi($auxiliar, ['visao' => 'completo', 'escopo' => 'todos', 'perfil' => 'pastor'])->assertOk()
            ->assertJsonPath('data.visao', 'parcial')
            ->assertJsonPath('data.escopo', 'proprios')
            ->assertJsonMissingPath('data.saldo');
    }
}
