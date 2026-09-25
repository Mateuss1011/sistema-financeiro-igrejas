<?php

namespace Tests\Feature\Usuarios;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtualizacaoEStatusUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function perfilId(PerfilSlug $slug): int
    {
        return Perfil::where('slug', $slug->value)->firstOrFail()->id;
    }

    public function test_criacao_e_auditada_sem_expor_senha(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $this->actingAs($pastor)->postJson('/api/v1/usuarios', [
            'name' => 'Novo Tesoureiro',
            'email' => 'novo@example.com',
            'password' => 'senha-valida-123',
            'perfil_id' => $this->perfilId(PerfilSlug::Tesoureiro),
        ])->assertStatus(201)->assertJsonMissingPath('data.password');

        $novo = User::where('email', 'novo@example.com')->first();
        $this->assertDatabaseHas('audit_logs', ['acao' => 'created', 'registro_id' => $novo->id, 'user_id' => $pastor->id]);
        $this->assertStringNotContainsString(
            'senha-valida-123',
            json_encode(\App\Models\AuditLog::where('acao', 'created')->first()->toArray())
        );
    }

    public function test_atualizacao_normal_continua_funcionando_e_e_auditada(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create(['name' => 'Antigo']);

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$alvo->id}", [
            'name' => 'Nome Novo',
            'perfil_id' => $this->perfilId(PerfilSlug::AuxiliarFinanceiro),
        ])->assertOk()->assertJsonPath('data.name', 'Nome Novo')
            ->assertJsonPath('data.perfil.slug', 'auxiliar_financeiro');

        $log = \App\Models\AuditLog::where('acao', 'updated')->where('registro_id', $alvo->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Antigo', $log->dados_anteriores['name']);
        $this->assertSame('Nome Novo', $log->dados_novos['name']);
    }

    public function test_desativa_e_reativa_via_put_com_auditoria_propria(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$alvo->id}", ['ativo' => false])
            ->assertOk()->assertJsonPath('data.ativo', false);
        $this->assertFalse($alvo->refresh()->ativo);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'deactivated', 'registro_id' => $alvo->id]);

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$alvo->id}", ['ativo' => true])
            ->assertOk()->assertJsonPath('data.ativo', true);
        $this->assertTrue($alvo->refresh()->ativo);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'activated', 'registro_id' => $alvo->id]);
    }

    public function test_ativo_precisa_ser_booleano(): void
    {
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        foreach (['talvez', null, ['x']] as $invalido) {
            $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$alvo->id}", ['ativo' => $invalido])
                ->assertStatus(422)->assertJsonValidationErrors('ativo');
        }
        $this->assertTrue($alvo->refresh()->ativo);
    }

    public function test_nao_permite_desativar_o_ultimo_pastor_ativo_via_put(): void
    {
        $unico = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $this->actingAs($unico)->putJson("/api/v1/usuarios/{$unico->id}", ['ativo' => false])
            ->assertStatus(409)->assertJsonPath('code', 'ULTIMO_PASTOR_ATIVO');

        $this->assertTrue($unico->refresh()->ativo);
        $this->assertDatabaseMissing('audit_logs', ['acao' => 'deactivated']);
    }

    public function test_nao_contorna_protecao_combinando_rebaixar_e_desativar(): void
    {
        $unico = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $this->actingAs($unico)->putJson("/api/v1/usuarios/{$unico->id}", [
            'ativo' => false,
            'perfil_id' => $this->perfilId(PerfilSlug::Administrador),
        ])->assertStatus(409)->assertJsonPath('code', 'ULTIMO_PASTOR_ATIVO');
    }

    public function test_permite_desativar_pastor_via_put_quando_ha_outro_ativo_e_reativar_depois(): void
    {
        $ator = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $outro = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $this->actingAs($ator)->putJson("/api/v1/usuarios/{$outro->id}", ['ativo' => false])->assertOk();
        $this->actingAs($ator)->putJson("/api/v1/usuarios/{$outro->id}", ['ativo' => true])->assertOk();
        $this->assertTrue($outro->refresh()->ativo);
    }

    public function test_administrador_nao_desativa_nem_reativa_pastor_sem_excecao(): void
    {
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $pastor = User::factory()->comPerfil(PerfilSlug::Pastor)->create();

        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$pastor->id}", ['ativo' => false])->assertStatus(403);
        $this->assertTrue($pastor->refresh()->ativo);
    }

    public function test_administrador_pode_alternar_status_de_usuario_nao_privilegiado(): void
    {
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Secretario)->create();

        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$alvo->id}", ['ativo' => false])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$alvo->id}", ['ativo' => true])->assertOk();
    }

    public function test_tesoureiro_e_secretario_nao_alteram_usuarios(): void
    {
        $alvo = User::factory()->comPerfil(PerfilSlug::AuxiliarFinanceiro)->create();

        foreach ([PerfilSlug::Tesoureiro, PerfilSlug::Secretario] as $perfil) {
            $ator = User::factory()->comPerfil($perfil)->create();
            $this->actingAs($ator)->putJson("/api/v1/usuarios/{$alvo->id}", ['ativo' => false])->assertStatus(403);
        }
    }

    public function test_administrador_nao_pode_promover_usuario_a_pastor_via_update(): void
    {
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador] as $destino) {
            $this->actingAs($admin)->putJson("/api/v1/usuarios/{$alvo->id}", [
                'perfil_id' => $this->perfilId($destino),
            ])->assertStatus(403);
        }

        $this->assertTrue($alvo->refresh()->ehPerfil(PerfilSlug::Tesoureiro));
    }

    public function test_administrador_pode_trocar_para_perfil_nao_privilegiado(): void
    {
        $admin = User::factory()->comPerfil(PerfilSlug::Administrador)->create();
        $alvo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$alvo->id}", [
            'perfil_id' => $this->perfilId(PerfilSlug::Secretario),
        ])->assertOk();
    }
}
