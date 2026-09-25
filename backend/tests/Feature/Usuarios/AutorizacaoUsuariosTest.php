<?php

namespace Tests\Feature\Usuarios;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutorizacaoUsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_pastor_pode_criar_usuario_administrador(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $perfilAdmin = Perfil::where('slug', PerfilSlug::Administrador->value)->first();

        $response = $this->actingAs($pastor)->postJson('/api/v1/usuarios', [
            'name' => 'Novo Admin',
            'email' => 'admin@example.com',
            'password' => 'senha-valida-123',
            'perfil_id' => $perfilAdmin->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
    }

    public function test_administrador_nao_pode_criar_outro_administrador_sem_excecao(): void
    {
        $administrador = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $perfilAdmin = Perfil::where('slug', PerfilSlug::Administrador->value)->first();

        $response = $this->actingAs($administrador)->postJson('/api/v1/usuarios', [
            'name' => 'Outro Admin',
            'email' => 'outro-admin@example.com',
            'password' => 'senha-valida-123',
            'perfil_id' => $perfilAdmin->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_administrador_com_excecao_pode_criar_administrador(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $administrador = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $perfilAdmin = Perfil::where('slug', PerfilSlug::Administrador->value)->first();

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$administrador->id}/permissoes-excecao", [
            'permissao' => 'usuarios.gerenciar_privilegiado',
        ])->assertStatus(201);

        $response = $this->actingAs($administrador->refresh())->postJson('/api/v1/usuarios', [
            'name' => 'Admin Via Excecao',
            'email' => 'vialexcecao@example.com',
            'password' => 'senha-valida-123',
            'perfil_id' => $perfilAdmin->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_tesoureiro_nao_acessa_listagem_de_usuarios(): void
    {
        $tesoureiro = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->actingAs($tesoureiro)->getJson('/api/v1/usuarios')->assertStatus(403);
    }

    public function test_secretario_consegue_visualizar_mas_nao_criar_usuarios(): void
    {
        $secretario = User::factory()->comPerfil(PerfilSlug::Secretario)->create();
        $perfilTesoureiro = Perfil::where('slug', PerfilSlug::Tesoureiro->value)->first();

        $this->actingAs($secretario)->getJson('/api/v1/usuarios')->assertOk();

        $this->actingAs($secretario)->postJson('/api/v1/usuarios', [
            'name' => 'Tentativa',
            'email' => 'tentativa@example.com',
            'password' => 'senha-valida-123',
            'perfil_id' => $perfilTesoureiro->id,
        ])->assertStatus(403);
    }
}
