<?php

namespace Tests\Feature\Periodos;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\PermissaoExcecao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Matriz de permissões da Fase 9 (D1: Pastor/Tesoureiro fecham; só Pastor reabre; Administrador nunca, mesmo com exceção). */
class PermissoesDePeriodosTest extends TestCase
{
    use RefreshDatabase, CenarioPeriodos;

    public function test_pastor_fecha(): void
    {
        $this->fecharApi($this->como(PerfilSlug::Pastor), '2026-03')->assertOk();
    }

    public function test_tesoureiro_fecha(): void
    {
        $this->fecharApi($this->como(PerfilSlug::Tesoureiro), '2026-03')->assertOk();
    }

    public function test_administrador_nao_fecha(): void
    {
        $this->fecharApi($this->como(PerfilSlug::Administrador), '2026-03')->assertStatus(403);
        $this->assertSame('aberto', $this->statusPeriodo('2026-03'));
    }

    public function test_administrador_com_qualquer_excecao_existente_ainda_nao_fecha(): void
    {
        // D1: fechamento/reabertura não têm mecanismo de exceção — nem exceções de outros módulos valem.
        $admin = $this->comExcecoes(PerfilSlug::Administrador, [
            PermissaoExcecaoChave::TransferenciasOperar->value,
            PermissaoExcecaoChave::AjustesOperar->value,
            PermissaoExcecaoChave::DespesasOperar->value,
            PermissaoExcecaoChave::EntradasOperar->value,
        ]);

        $this->fecharApi($admin, '2026-03')->assertStatus(403);
    }

    public function test_auxiliar_nao_fecha(): void
    {
        $this->fecharApi($this->como(PerfilSlug::AuxiliarFinanceiro), '2026-03')->assertStatus(403);
    }

    public function test_secretario_nao_fecha(): void
    {
        $this->fecharApi($this->como(PerfilSlug::Secretario), '2026-03')->assertStatus(403);
    }

    public function test_pastor_reabre(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->reabrirApi($pastor, '2026-03')->assertOk();
    }

    public function test_tesoureiro_nao_reabre(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->reabrirApi($tesoureiro, '2026-03')->assertStatus(403);
        $this->assertSame('fechado', $this->statusPeriodo('2026-03'));
    }

    public function test_administrador_nao_reabre_mesmo_com_excecao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $admin = $this->comExcecoes(PerfilSlug::Administrador, [PermissaoExcecaoChave::TransferenciasOperar->value]);
        $this->reabrirApi($admin, '2026-03')->assertStatus(403);
    }

    public function test_auxiliar_nao_reabre(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->fecharApi($pastor, '2026-03')->assertOk();

        $this->reabrirApi($this->como(PerfilSlug::AuxiliarFinanceiro), '2026-03')->assertStatus(403);
    }

    public function test_secretario_nao_ganha_acesso_indevido_nem_para_consultar(): void
    {
        $secretario = $this->como(PerfilSlug::Secretario);

        $this->listarPeriodosApi($secretario)->assertStatus(403);
        $this->fecharApi($secretario, '2026-03')->assertStatus(403);
        $this->reabrirApi($secretario, '2026-03')->assertStatus(403);
    }

    public function test_auxiliar_nao_acessa_nem_a_listagem(): void
    {
        $this->listarPeriodosApi($this->como(PerfilSlug::AuxiliarFinanceiro))->assertStatus(403);
    }

    public function test_pastor_administrador_e_tesoureiro_consultam(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $this->listarPeriodosApi($this->como($perfil))->assertOk();
        }
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/periodos-financeiros')->assertStatus(401);
        $this->postJson('/api/v1/periodos-financeiros/2026-03/fechar')->assertStatus(401);
        $this->postJson('/api/v1/periodos-financeiros/2026-03/reabrir', ['justificativa' => 'x'])->assertStatus(401);
    }
}
