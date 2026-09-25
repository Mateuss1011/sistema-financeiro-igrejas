<?php

namespace Tests\Feature\Usuarios;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UltimoPastorAtivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_permite_desativar_o_unico_pastor_ativo(): void
    {
        $unicoPastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $response = $this->actingAs($unicoPastor)->deleteJson("/api/v1/usuarios/{$unicoPastor->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'ULTIMO_PASTOR_ATIVO');

        $unicoPastor->refresh();
        $this->assertTrue($unicoPastor->ativo);
    }

    public function test_permite_desativar_pastor_quando_existe_outro_ativo(): void
    {
        $pastorAtor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $pastorAlvo = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $response = $this->actingAs($pastorAtor)->deleteJson("/api/v1/usuarios/{$pastorAlvo->id}");

        $response->assertOk();
        $this->assertFalse($pastorAlvo->refresh()->ativo);
        $this->assertTrue($pastorAtor->refresh()->ativo);
    }

    public function test_nao_permite_rebaixar_o_unico_pastor_ativo_via_update(): void
    {
        $unicoPastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $perfilAdmin = Perfil::where('slug', PerfilSlug::Administrador->value)->first();

        $response = $this->actingAs($unicoPastor)->putJson("/api/v1/usuarios/{$unicoPastor->id}", [
            'perfil_id' => $perfilAdmin->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'ULTIMO_PASTOR_ATIVO');
    }
}
