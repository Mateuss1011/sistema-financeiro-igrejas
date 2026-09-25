<?php

namespace Tests\Feature\Perfis;

use App\Enums\PerfilSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerfisTest extends TestCase
{
    use RefreshDatabase;

    private function slugs(User $ator): array
    {
        return collect($this->actingAs($ator)->getJson('/api/v1/perfis')->assertOk()->json('data'))
            ->pluck('slug')->all();
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/perfis')->assertStatus(401);
    }

    public function test_pastor_ve_todos_os_cinco_perfis_no_formato_esperado(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $response = $this->actingAs($pastor)->getJson('/api/v1/perfis');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonStructure(['data' => [['id', 'slug', 'nome_exibicao']]]);
        $this->assertSame(
            ['pastor', 'administrador', 'tesoureiro', 'auxiliar_financeiro', 'secretario'],
            $response->json('data.*.slug')
        );
    }

    public function test_administrador_so_ve_perfis_nao_privilegiados(): void
    {
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $this->assertSame(['tesoureiro', 'auxiliar_financeiro', 'secretario'], $this->slugs($admin));
    }

    public function test_administrador_com_excecao_ve_todos_os_perfis(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", [
            'permissao' => 'usuarios.gerenciar_privilegiado',
        ])->assertStatus(201);

        $this->assertCount(5, $this->slugs($admin->refresh()));
    }

    public function test_secretario_recebe_lista_vazia_pois_nao_atribui_perfis(): void
    {
        $secretario = User::factory()->comPerfil(PerfilSlug::Secretario)->create();

        $this->assertSame([], $this->slugs($secretario));
    }

    public function test_tesoureiro_e_auxiliar_nao_autorizados(): void
    {
        foreach ([PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro] as $perfil) {
            $usuario = User::factory()->comPerfil($perfil)->create();
            $this->actingAs($usuario)->getJson('/api/v1/perfis')->assertStatus(403);
        }
    }
}
