<?php

namespace Tests\Feature\Usuarios;

use App\Enums\PerfilSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoEVisualizacaoExcecoesTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'usuarios.gerenciar_privilegiado';

    public function test_catalogo_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/permissoes-excecao')->assertStatus(401);
    }

    public function test_pastor_lista_catalogo_com_as_chaves_existentes(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $response = $this->actingAs($pastor)->getJson('/api/v1/permissoes-excecao');

        $response->assertOk();
        $response->assertJsonCount(7, 'data');
        $response->assertJsonStructure(['data' => [['chave', 'descricao']]]);
        $response->assertJsonPath('data.0.chave', self::CHAVE);
        $response->assertJsonPath('data.1.chave', 'entradas.operar');
        $response->assertJsonPath('data.2.chave', 'despesas.operar');
        $response->assertJsonPath('data.3.chave', 'despesas.estornar_paga');
        $response->assertJsonPath('data.4.chave', 'transferencias.operar');
        $response->assertJsonPath('data.5.chave', 'transferencias.estornar');
        $response->assertJsonPath('data.6.chave', 'ajustes.operar');
    }

    public function test_demais_perfis_nao_acessam_o_catalogo(): void
    {
        foreach ([PerfilSlug::Administrador, PerfilSlug::Tesoureiro, PerfilSlug::Secretario] as $perfil) {
            $ator = User::factory()->comPerfil($perfil)->create();
            $this->actingAs($ator)->getJson('/api/v1/permissoes-excecao')->assertStatus(403);
        }
    }

    public function test_pastor_ve_excecoes_concedidas_na_listagem_e_apos_conceder_e_revogar(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $linhaDoAdmin = fn () => collect($this->actingAs($pastor)->getJson('/api/v1/usuarios')->json('data'))
            ->firstWhere('id', $admin->id);

        $this->assertSame([], $linhaDoAdmin()['permissoes_excecao']);

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => self::CHAVE])
            ->assertStatus(201);
        $this->assertSame([self::CHAVE], $linhaDoAdmin()['permissoes_excecao']);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'permission_granted', 'registro_id' => $admin->id, 'user_id' => $pastor->id]);

        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao/" . self::CHAVE)
            ->assertOk();
        $this->assertSame([], $linhaDoAdmin()['permissoes_excecao']);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'permission_revoked', 'registro_id' => $admin->id, 'user_id' => $pastor->id]);
    }

    public function test_respostas_de_criacao_e_edicao_tambem_trazem_excecoes_para_o_pastor(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$alvo->id}", ['name' => 'X'])
            ->assertOk()->assertJsonPath('data.permissoes_excecao', []);
    }

    public function test_quem_nao_pode_gerenciar_excecoes_nao_recebe_o_campo(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $secretario = User::factory()->comPerfil(PerfilSlug::Secretario)->create();

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => self::CHAVE])
            ->assertStatus(201);

        foreach ([$admin->refresh(), $secretario] as $ator) {
            $linhas = $this->actingAs($ator)->getJson('/api/v1/usuarios')->assertOk()->json('data');
            foreach ($linhas as $linha) {
                $this->assertArrayNotHasKey('permissoes_excecao', $linha);
            }
        }

        $this->actingAs($admin)->getJson('/api/v1/auth/me')->assertJsonMissingPath('data.permissoes_excecao');
    }

    public function test_conceder_permissao_fora_do_catalogo_retorna_422(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'inventada.qualquer'])
            ->assertStatus(422)->assertJsonValidationErrors('permissao');

        $this->assertDatabaseCount('permissoes_excecao', 0);
    }

    public function test_apenas_pastor_concede_e_revoga(): void
    {
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->actingAs($admin)->postJson("/api/v1/usuarios/{$alvo->id}/permissoes-excecao", ['permissao' => self::CHAVE])
            ->assertStatus(403);
        $this->actingAs($admin)->deleteJson("/api/v1/usuarios/{$alvo->id}/permissoes-excecao/" . self::CHAVE)
            ->assertStatus(403);
    }
}
