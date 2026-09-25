<?php

namespace Tests\Feature\Auth;

use App\Enums\PerfilSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeELogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_sem_autenticacao_retorna_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_me_autenticado_retorna_dados_sem_senha(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $response = $this->actingAs($usuario)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.id', $usuario->id);
        $response->assertJsonMissingPath('data.password');
    }

    public function test_logout_encerra_sessao_e_audita(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->actingAs($usuario)
            ->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'acao' => 'logout',
            'user_id' => $usuario->id,
        ]);
    }

    public function test_csrf_cookie_endpoint_funciona_e_retorna_cookies(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertStatus(204);
        $response->assertCookie('XSRF-TOKEN');
    }
}
