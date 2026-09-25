<?php

namespace Tests\Feature\Auth;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O middleware EnsureFrontendRequestsAreStateful só ativa a sessão de cookie
     * quando a requisição é reconhecida como vinda do frontend (Origin/Referer
     * dentro de SANCTUM_STATEFUL_DOMAINS) — simulamos isso aqui como o navegador faria.
     */
    private function postLogin(array $payload)
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/login', $payload);
    }

    public function test_login_com_credenciais_validas_autentica_e_audita(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create([
            'password' => 'senha-correta-123',
        ]);

        $response = $this->postLogin([
            'email' => $usuario->email,
            'password' => 'senha-correta-123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', $usuario->email);
        $this->assertAuthenticatedAs($usuario);

        $this->assertDatabaseHas('audit_logs', [
            'acao' => 'login',
            'modulo' => 'auth',
            'user_id' => $usuario->id,
        ]);

        $usuario->refresh();
        $this->assertNotNull($usuario->ultimo_login_em);
    }

    public function test_login_com_senha_invalida_falha_e_audita_sem_expor_senha(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create([
            'password' => 'senha-correta-123',
        ]);

        $response = $this->postLogin([
            'email' => $usuario->email,
            'password' => 'senha-errada',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();

        $log = AuditLog::where('acao', 'login_failed')->first();
        $this->assertNotNull($log);
        $this->assertSame($usuario->id, $log->user_id);
        $this->assertStringNotContainsString('senha-errada', json_encode($log->toArray()));
    }

    public function test_login_de_usuario_inexistente_nao_quebra_e_audita_sem_usuario(): void
    {
        $response = $this->postLogin([
            'email' => 'nao-existe@example.com',
            'password' => 'qualquer-coisa',
        ]);

        $response->assertStatus(422);

        $log = AuditLog::where('acao', 'login_failed')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
    }

    public function test_login_de_usuario_inativo_e_bloqueado(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->inativo()->create([
            'password' => 'senha-correta-123',
        ]);

        $response = $this->postLogin([
            'email' => $usuario->email,
            'password' => 'senha-correta-123',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_resposta_de_login_nunca_contem_senha_ou_token(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create([
            'password' => 'senha-correta-123',
        ]);

        $response = $this->postLogin([
            'email' => $usuario->email,
            'password' => 'senha-correta-123',
        ]);

        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.remember_token');
        $this->assertStringNotContainsString('senha-correta-123', $response->getContent());
    }
}
