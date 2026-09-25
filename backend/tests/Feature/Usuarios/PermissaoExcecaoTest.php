<?php

namespace Tests\Feature\Usuarios;

use App\Enums\PerfilSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissaoExcecaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pastor_concede_excecao_e_isso_e_auditado(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $administrador = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $response = $this->actingAs($pastor)->postJson(
            "/api/v1/usuarios/{$administrador->id}/permissoes-excecao",
            ['permissao' => 'usuarios.gerenciar_privilegiado']
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('permissoes_excecao', [
            'user_id' => $administrador->id,
            'permissao' => 'usuarios.gerenciar_privilegiado',
            'concedida_por' => $pastor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'acao' => 'permission_granted',
            'registro_id' => $administrador->id,
        ]);
    }

    public function test_administrador_nao_pode_conceder_excecao(): void
    {
        $administrador = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $outroAdministrador = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $response = $this->actingAs($administrador)->postJson(
            "/api/v1/usuarios/{$outroAdministrador->id}/permissoes-excecao",
            ['permissao' => 'usuarios.gerenciar_privilegiado']
        );

        $response->assertStatus(403);
    }

    public function test_pastor_revoga_excecao_e_isso_e_auditado(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $administrador = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $this->actingAs($pastor)->postJson(
            "/api/v1/usuarios/{$administrador->id}/permissoes-excecao",
            ['permissao' => 'usuarios.gerenciar_privilegiado']
        )->assertStatus(201);

        $response = $this->actingAs($pastor)->deleteJson(
            "/api/v1/usuarios/{$administrador->id}/permissoes-excecao/usuarios.gerenciar_privilegiado"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('permissoes_excecao', [
            'user_id' => $administrador->id,
            'permissao' => 'usuarios.gerenciar_privilegiado',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'acao' => 'permission_revoked',
            'registro_id' => $administrador->id,
        ]);
    }
}
