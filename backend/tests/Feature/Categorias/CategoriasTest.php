<?php

namespace Tests\Feature\Categorias;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Categoria;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\CategoriaService;
use Database\Seeders\CategoriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriasTest extends TestCase
{
    use RefreshDatabase;

    private function como(PerfilSlug $perfil): User
    {
        return User::factory()->comPerfil($perfil)->create();
    }

    /** Substitui o service por uma subclasse em que toda categoria está "em uso" (simula as Fases 6/7). */
    private function simularCategoriasEmUso(): void
    {
        $this->app->instance(CategoriaService::class, new class(app(AuditoriaService::class)) extends CategoriaService {
            public function estaEmUso(Categoria $categoria): bool
            {
                return true;
            }
        });
    }

    private function categoria(string $nome = 'Dízimo', string $tipo = 'entrada', bool $ativa = true): Categoria
    {
        return Categoria::create(['nome' => $nome, 'tipo' => $tipo, 'ativa' => $ativa]);
    }

    // ---------- listagem ----------

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/categorias')->assertStatus(401);
        $this->postJson('/api/v1/categorias', [])->assertStatus(401);
    }

    public function test_todos_os_perfis_autenticados_podem_listar(): void
    {
        $this->categoria();

        foreach (PerfilSlug::cases() as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/categorias')
                ->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public function test_formato_da_listagem_e_paginacao(): void
    {
        $this->categoria();

        $this->actingAs($this->como(PerfilSlug::Tesoureiro))->getJson('/api/v1/categorias')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'nome', 'tipo', 'ativa', 'created_at', 'updated_at']], 'links', 'meta'])
            ->assertJsonPath('data.0.tipo', 'entrada');
    }

    public function test_filtros_por_tipo_e_por_status(): void
    {
        $this->categoria('Dízimo', 'entrada');
        $this->categoria('Oferta', 'entrada', false);
        $this->categoria('Aluguel', 'despesa');
        $pastor = $this->como(PerfilSlug::Pastor);

        $nomes = fn (string $qs) => collect($this->actingAs($pastor)->getJson('/api/v1/categorias' . $qs)->assertOk()->json('data'))->pluck('nome')->all();

        $this->assertCount(3, $nomes(''));
        $this->assertEqualsCanonicalizing(['Dízimo', 'Oferta'], $nomes('?tipo=entrada'));
        $this->assertSame(['Aluguel'], $nomes('?tipo=despesa'));
        $this->assertEqualsCanonicalizing(['Dízimo', 'Aluguel'], $nomes('?ativa=true'));
        $this->assertSame(['Oferta'], $nomes('?ativa=false'));
        $this->assertSame(['Dízimo'], $nomes('?tipo=entrada&ativa=1'));
    }

    public function test_filtros_invalidos_retornam_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->getJson('/api/v1/categorias?tipo=outro')->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->actingAs($pastor)->getJson('/api/v1/categorias?ativa=talvez')->assertStatus(422)->assertJsonValidationErrors('ativa');
    }

    // ---------- criação ----------

    public function test_pastor_e_administrador_criam_categoria_com_auditoria(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador] as $i => $perfil) {
            $ator = $this->como($perfil);

            $this->actingAs($ator)->postJson('/api/v1/categorias', ['nome' => "Nova $i", 'tipo' => 'despesa'])
                ->assertStatus(201)
                ->assertJsonPath('data.nome', "Nova $i")
                ->assertJsonPath('data.tipo', 'despesa')
                ->assertJsonPath('data.ativa', true);

            $id = Categoria::where('nome', "Nova $i")->value('id');
            $this->assertDatabaseHas('audit_logs', ['acao' => 'created', 'modulo' => 'categorias', 'registro_id' => $id, 'user_id' => $ator->id]);
        }
    }

    public function test_validacao_de_criacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->postJson('/api/v1/categorias', [])
            ->assertStatus(422)->assertJsonValidationErrors(['nome', 'tipo']);
        $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => str_repeat('a', 101), 'tipo' => 'entrada'])
            ->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => 'X', 'tipo' => 'transferencia'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => str_repeat('a', 100), 'tipo' => 'entrada'])
            ->assertStatus(201);
    }

    public function test_nome_duplicado_no_mesmo_tipo_e_barrado_mas_em_outro_tipo_e_permitido(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->categoria('Eventos', 'despesa');

        $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => 'Eventos', 'tipo' => 'despesa'])
            ->assertStatus(422)->assertJsonValidationErrors('nome');

        $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => 'Eventos', 'tipo' => 'entrada'])
            ->assertStatus(201);

        $this->assertSame(2, Categoria::where('nome', 'Eventos')->count());
    }

    // ---------- autorização ----------

    public function test_perfis_de_consulta_nao_criam_editam_nem_excluem(): void
    {
        $categoria = $this->categoria();

        foreach ([PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $ator = $this->como($perfil);

            $this->actingAs($ator)->postJson('/api/v1/categorias', ['nome' => 'Tentativa', 'tipo' => 'entrada'])->assertStatus(403);
            $this->actingAs($ator)->putJson("/api/v1/categorias/{$categoria->id}", ['nome' => 'Alterada'])->assertStatus(403);
            $this->actingAs($ator)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => false])->assertStatus(403);
            $this->actingAs($ator)->deleteJson("/api/v1/categorias/{$categoria->id}")->assertStatus(403);
        }

        $categoria->refresh();
        $this->assertSame('Dízimo', $categoria->nome);
        $this->assertTrue($categoria->ativa);
        $this->assertDatabaseMissing('categorias', ['nome' => 'Tentativa']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_categoria_inexistente_retorna_404(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->putJson('/api/v1/categorias/9999', ['nome' => 'X'])->assertStatus(404);
        $this->actingAs($pastor)->deleteJson('/api/v1/categorias/9999')->assertStatus(404);
    }

    // ---------- edição ----------

    public function test_edicao_de_nome_com_auditoria_de_antes_e_depois(): void
    {
        $admin = $this->como(PerfilSlug::Administrador);
        $categoria = $this->categoria('Oferta');

        $this->actingAs($admin)->putJson("/api/v1/categorias/{$categoria->id}", ['nome' => 'Oferta Especial'])
            ->assertOk()->assertJsonPath('data.nome', 'Oferta Especial');

        $log = AuditLog::where('acao', 'updated')->where('registro_id', $categoria->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Oferta', $log->dados_anteriores['nome']);
        $this->assertSame('Oferta Especial', $log->dados_novos['nome']);
        $this->assertSame('categorias', $log->modulo);
    }

    public function test_editar_para_nome_ja_existente_no_mesmo_tipo_retorna_422_mas_manter_o_proprio_nome_ok(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->categoria('A', 'entrada');
        $this->categoria('B', 'entrada');
        $this->categoria('A', 'despesa');

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$a->id}", ['nome' => 'B'])->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$a->id}", ['nome' => 'A'])->assertOk();
    }

    public function test_tipo_e_imutavel(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria('Dízimo', 'entrada');

        foreach (['despesa', 'entrada'] as $tipo) {
            $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['tipo' => $tipo, 'nome' => 'Outro'])
                ->assertStatus(422)->assertJsonValidationErrors('tipo');
        }

        $categoria->refresh();
        $this->assertSame('entrada', $categoria->tipo->value);
        $this->assertSame('Dízimo', $categoria->nome);
    }

    public function test_ativa_precisa_ser_booleano(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => 'talvez'])
            ->assertStatus(422)->assertJsonValidationErrors('ativa');
    }

    // ---------- ativação / inativação ----------

    public function test_inativa_e_reativa_com_auditoria_propria_e_continua_consultavel(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => false])
            ->assertOk()->assertJsonPath('data.ativa', false);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'deactivated', 'modulo' => 'categorias', 'registro_id' => $categoria->id]);

        $consulta = $this->actingAs($this->como(PerfilSlug::Secretario))->getJson('/api/v1/categorias?ativa=false');
        $consulta->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => true])
            ->assertOk()->assertJsonPath('data.ativa', true);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'activated', 'modulo' => 'categorias', 'registro_id' => $categoria->id]);
    }

    public function test_atualizacao_sem_mudanca_nao_gera_auditoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['nome' => 'Dízimo', 'ativa' => true])->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ---------- exclusão ----------

    public function test_exclui_categoria_nao_usada_com_auditoria(): void
    {
        $admin = $this->como(PerfilSlug::Administrador);
        $categoria = $this->categoria('Descartável');

        $this->actingAs($admin)->deleteJson("/api/v1/categorias/{$categoria->id}")->assertOk();

        $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
        $log = AuditLog::where('acao', 'deleted')->where('registro_id', $categoria->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Descartável', $log->dados_anteriores['nome']);
    }

    public function test_exclusao_e_bloqueada_quando_esta_em_uso_com_409(): void
    {
        $this->simularCategoriasEmUso();

        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();

        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$categoria->id}")
            ->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_EM_USO');

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id]);
        $this->assertDatabaseMissing('audit_logs', ['acao' => 'deleted']);
    }

    public function test_categoria_em_uso_ainda_pode_ser_inativada(): void
    {
        $this->simularCategoriasEmUso();

        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => false])->assertOk();
        $this->assertFalse($categoria->refresh()->ativa);
    }

    public function test_nesta_fase_nenhuma_categoria_esta_em_uso(): void
    {
        $this->assertFalse(app(CategoriaService::class)->estaEmUso($this->categoria()));
    }

    // ---------- seed ----------

    public function test_seed_cria_as_13_categorias_aprovadas_e_e_idempotente(): void
    {
        $this->seed(CategoriaSeeder::class);
        $this->seed(CategoriaSeeder::class);

        $this->assertSame(6, Categoria::where('tipo', 'entrada')->count());
        $this->assertSame(7, Categoria::where('tipo', 'despesa')->count());
        $this->assertDatabaseHas('categorias', ['nome' => 'Dízimo', 'tipo' => 'entrada']);
        $this->assertDatabaseHas('categorias', ['nome' => 'Água/Luz/Internet', 'tipo' => 'despesa']);
    }

    public function test_seed_nao_sobrescreve_categoria_ja_alterada(): void
    {
        $this->seed(CategoriaSeeder::class);
        Categoria::where('nome', 'Dízimo')->update(['ativa' => false]);

        $this->seed(CategoriaSeeder::class);

        $this->assertFalse(Categoria::where('nome', 'Dízimo')->first()->ativa);
        $this->assertSame(13, Categoria::count());
    }
}
