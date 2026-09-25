<?php

namespace Tests\Feature\Contas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Conta;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\ContaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContasTest extends TestCase
{
    use RefreshDatabase;

    private function como(PerfilSlug $perfil): User
    {
        return User::factory()->comPerfil($perfil)->create();
    }

    private function conta(string $nome = 'Banco Itaú', string $tipo = 'banco', string $saldo = '100.50', bool $ativa = true): Conta
    {
        return Conta::create(['nome' => $nome, 'tipo' => $tipo, 'saldo_inicial' => $saldo, 'ativa' => $ativa]);
    }

    /** Substitui o service por uma subclasse em que toda conta está "em uso" (simula as Fases 6–8). */
    private function simularContasEmUso(): void
    {
        $this->app->instance(ContaService::class, new class(app(AuditoriaService::class)) extends ContaService {
            public function estaEmUso(Conta $conta): bool
            {
                return true;
            }
        });
    }

    // ---------- acesso e listagem ----------

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/contas')->assertStatus(401);
        $this->postJson('/api/v1/contas', [])->assertStatus(401);
    }

    public function test_perfis_financeiros_e_administrativos_listam_e_secretario_recebe_403(): void
    {
        $this->conta();

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/contas')->assertOk()->assertJsonCount(1, 'data');
        }

        $this->actingAs($this->como(PerfilSlug::Secretario))->getJson('/api/v1/contas')->assertStatus(403);
    }

    public function test_formato_da_resposta_com_valores_monetarios_como_string(): void
    {
        $this->conta('Caixa Principal', 'caixa', '1234.50');

        $resposta = $this->actingAs($this->como(PerfilSlug::Tesoureiro))->getJson('/api/v1/contas');

        $resposta->assertOk()
            ->assertJsonStructure(['data' => [['id', 'nome', 'tipo', 'ativa', 'saldo_inicial', 'saldo_atual', 'created_at', 'updated_at']], 'links', 'meta'])
            ->assertJsonPath('data.0.saldo_inicial', '1234.50')
            ->assertJsonPath('data.0.saldo_atual', '1234.50');

        $this->assertIsString($resposta->json('data.0.saldo_inicial'));
        $this->assertIsString($resposta->json('data.0.saldo_atual'));
        $this->assertArrayNotHasKey('deleted_at', $resposta->json('data.0'));
        $this->assertArrayNotHasKey('nome_ativo', $resposta->json('data.0'));
    }

    public function test_paginacao(): void
    {
        foreach (['A', 'B', 'C'] as $nome) {
            $this->conta("Conta $nome", 'banco', '0');
        }

        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->getJson('/api/v1/contas?por_pagina=2')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->actingAs($pastor)->getJson('/api/v1/contas?por_pagina=2&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($pastor)->getJson('/api/v1/contas?por_pagina=101')->assertStatus(422);
    }

    public function test_filtros_por_tipo_e_status_e_filtros_invalidos(): void
    {
        $this->conta('Banco A', 'banco');
        $this->conta('Banco B', 'banco', '0', false);
        $this->conta('Caixa A', 'caixa', '0');
        $pastor = $this->como(PerfilSlug::Pastor);

        $nomes = fn (string $qs) => collect($this->actingAs($pastor)->getJson('/api/v1/contas' . $qs)->assertOk()->json('data'))->pluck('nome')->all();

        $this->assertCount(3, $nomes(''));
        $this->assertEqualsCanonicalizing(['Banco A', 'Banco B'], $nomes('?tipo=banco'));
        $this->assertSame(['Caixa A'], $nomes('?tipo=caixa'));
        $this->assertEqualsCanonicalizing(['Banco A', 'Caixa A'], $nomes('?ativa=true'));
        $this->assertSame(['Banco B'], $nomes('?ativa=false'));
        $this->assertSame(['Banco A'], $nomes('?tipo=banco&ativa=1'));

        $this->actingAs($pastor)->getJson('/api/v1/contas?tipo=poupanca')->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->actingAs($pastor)->getJson('/api/v1/contas?ativa=talvez')->assertStatus(422)->assertJsonValidationErrors('ativa');
    }

    // ---------- criação ----------

    public function test_pastor_e_administrador_criam_banco_e_caixa_com_auditoria_do_saldo_inicial(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador] as $i => $perfil) {
            $ator = $this->como($perfil);

            $this->actingAs($ator)->postJson('/api/v1/contas', ['nome' => "Banco $i", 'tipo' => 'banco', 'saldo_inicial' => '2500.75'])
                ->assertStatus(201)
                ->assertJsonPath('data.nome', "Banco $i")
                ->assertJsonPath('data.tipo', 'banco')
                ->assertJsonPath('data.ativa', true)
                ->assertJsonPath('data.saldo_inicial', '2500.75')
                ->assertJsonPath('data.saldo_atual', '2500.75');

            $id = Conta::where('nome', "Banco $i")->value('id');
            $log = AuditLog::where('acao', 'created')->where('modulo', 'contas')->where('registro_id', $id)->first();
            $this->assertNotNull($log);
            $this->assertSame($ator->id, $log->user_id);
            $this->assertSame('2500.75', $log->dados_novos['saldo_inicial']);
            $this->assertSame('banco', $log->dados_novos['tipo']);
        }

        $this->actingAs($this->como(PerfilSlug::Pastor))->postJson('/api/v1/contas', ['nome' => 'Caixa Principal', 'tipo' => 'caixa'])
            ->assertStatus(201)->assertJsonPath('data.tipo', 'caixa');
    }

    public function test_saldo_inicial_omitido_assume_zero(): void
    {
        $this->actingAs($this->como(PerfilSlug::Pastor))->postJson('/api/v1/contas', ['nome' => 'Caixa Zero', 'tipo' => 'caixa'])
            ->assertStatus(201)->assertJsonPath('data.saldo_inicial', '0.00')->assertJsonPath('data.saldo_atual', '0.00');
    }

    public function test_formatos_validos_de_saldo_inicial_sao_normalizados_para_duas_casas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $casos = [
            'Conta 1' => ['1500', '1500.00'],
            'Conta 2' => ['1500.5', '1500.50'],
            'Conta 3' => [1500.5, '1500.50'],       // número JSON
            'Conta 4' => [10, '10.00'],
            'Conta 5' => ['-250.30', '-250.30'],
            'Conta 6' => ['999999999999.99', '999999999999.99'],
            'Conta 7' => ['0.10', '0.10'],
        ];

        foreach ($casos as $nome => [$entrada, $esperado]) {
            $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => $nome, 'tipo' => 'banco', 'saldo_inicial' => $entrada])
                ->assertStatus(201)->assertJsonPath('data.saldo_inicial', $esperado);
        }
    }

    public function test_formatos_invalidos_de_saldo_inicial_sao_rejeitados_sem_arredondar(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['1.005', '1,50', 'abc', '1e5', '1234567890123', '--5', true, ['1'], 1.005, 0.1 + 0.2] as $i => $invalido) {
            $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => "Inv $i", 'tipo' => 'banco', 'saldo_inicial' => $invalido])
                ->assertStatus(422)->assertJsonValidationErrors('saldo_inicial');
        }

        $this->assertSame(0, Conta::count());
    }

    public function test_caixa_nao_pode_iniciar_negativo_mas_banco_pode(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Caixa Neg', 'tipo' => 'caixa', 'saldo_inicial' => '-0.01'])
            ->assertStatus(422)->assertJsonValidationErrors('saldo_inicial');
        $this->assertDatabaseMissing('contas', ['nome' => 'Caixa Neg']);

        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Banco Neg', 'tipo' => 'banco', 'saldo_inicial' => '-1234.56'])
            ->assertStatus(201)->assertJsonPath('data.saldo_inicial', '-1234.56')->assertJsonPath('data.saldo_atual', '-1234.56');

        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Caixa Zero', 'tipo' => 'caixa', 'saldo_inicial' => '0.00'])->assertStatus(201);
    }

    public function test_validacao_de_nome_e_tipo(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->postJson('/api/v1/contas', [])->assertStatus(422)->assertJsonValidationErrors(['nome', 'tipo']);
        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => str_repeat('a', 101), 'tipo' => 'banco'])
            ->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'X', 'tipo' => 'poupanca'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => str_repeat('a', 100), 'tipo' => 'banco'])->assertStatus(201);
    }

    public function test_nome_duplicado_entre_contas_ativas_e_barrado_ignorando_caixa_alta_e_acentos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->conta('Banco Itaú', 'banco');

        foreach (['Banco Itaú', 'BANCO ITAU', 'banco itaú'] as $nome) {
            // até com outro tipo: o nome identifica a conta
            $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => $nome, 'tipo' => 'caixa'])
                ->assertStatus(422)->assertJsonValidationErrors('nome');
        }

        $this->assertSame(1, Conta::count());
    }

    public function test_mesmo_nome_pode_ser_reutilizado_depois_do_soft_delete(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $antiga = $this->conta('Caixa Principal', 'caixa', '0');

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$antiga->id}")->assertOk();
        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Caixa Principal', 'tipo' => 'caixa'])->assertStatus(201);

        // e uma segunda exclusão com o mesmo nome também é possível (várias excluídas coexistem)
        $nova = Conta::where('nome', 'Caixa Principal')->first();
        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$nova->id}")->assertOk();
        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Caixa Principal', 'tipo' => 'caixa'])->assertStatus(201);

        $this->assertSame(3, Conta::withTrashed()->where('nome', 'Caixa Principal')->count());
        $this->assertSame(1, Conta::where('nome', 'Caixa Principal')->count());
    }

    // ---------- autorização ----------

    public function test_perfis_sem_poder_de_escrita_recebem_403(): void
    {
        $conta = $this->conta();

        foreach ([PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $ator = $this->como($perfil);

            $this->actingAs($ator)->postJson('/api/v1/contas', ['nome' => 'Tentativa', 'tipo' => 'banco'])->assertStatus(403);
            $this->actingAs($ator)->putJson("/api/v1/contas/{$conta->id}", ['nome' => 'Alterada'])->assertStatus(403);
            $this->actingAs($ator)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])->assertStatus(403);
            $this->actingAs($ator)->deleteJson("/api/v1/contas/{$conta->id}")->assertStatus(403);
        }

        $conta->refresh();
        $this->assertSame('Banco Itaú', $conta->nome);
        $this->assertTrue($conta->ativa);
        $this->assertNull($conta->deleted_at);
        $this->assertDatabaseMissing('contas', ['nome' => 'Tentativa']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_administrador_edita_e_alterna_status_mas_nao_exclui(): void
    {
        $admin = $this->como(PerfilSlug::Administrador);
        $conta = $this->conta('Antiga');

        $this->actingAs($admin)->putJson("/api/v1/contas/{$conta->id}", ['nome' => 'Nova'])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => true])->assertOk();

        $this->actingAs($admin)->deleteJson("/api/v1/contas/{$conta->id}")->assertStatus(403);
        $this->assertNull($conta->refresh()->deleted_at);
        $this->assertDatabaseMissing('audit_logs', ['acao' => 'deleted']);
    }

    public function test_conta_inexistente_ou_excluida_retorna_404(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();

        $this->actingAs($pastor)->putJson('/api/v1/contas/9999', ['nome' => 'X'])->assertStatus(404);
        $this->actingAs($pastor)->deleteJson('/api/v1/contas/9999')->assertStatus(404);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")->assertOk();
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['nome' => 'X'])->assertStatus(404);
        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")->assertStatus(404);
    }

    // ---------- edição ----------

    public function test_renomear_com_auditoria_de_antes_e_depois(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco do Brasil');

        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['nome' => 'Banco do Brasil - CC'])
            ->assertOk()->assertJsonPath('data.nome', 'Banco do Brasil - CC');

        $log = AuditLog::where('acao', 'updated')->where('registro_id', $conta->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('contas', $log->modulo);
        $this->assertSame('Banco do Brasil', $log->dados_anteriores['nome']);
        $this->assertSame('Banco do Brasil - CC', $log->dados_novos['nome']);
    }

    public function test_tipo_e_saldo_inicial_sao_imutaveis_mesmo_com_o_mesmo_valor(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco', 'banco', '100.50');

        foreach ([['tipo' => 'caixa'], ['tipo' => 'banco'], ['saldo_inicial' => '999.00'], ['saldo_inicial' => '100.50'], ['tipo' => 'caixa', 'saldo_inicial' => '0', 'nome' => 'Outro']] as $payload) {
            $resposta = $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", $payload)->assertStatus(422);
            $this->assertTrue($resposta->json('errors.tipo') !== null || $resposta->json('errors.saldo_inicial') !== null);
        }

        $conta->refresh();
        $this->assertSame('banco', $conta->tipo->value);
        $this->assertSame('100.50', $conta->saldo_inicial);
        $this->assertSame('Banco', $conta->nome);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_renomear_para_nome_existente_da_422_mas_manter_o_proprio_nome_e_ok(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('A');
        $this->conta('B');

        $this->actingAs($pastor)->putJson("/api/v1/contas/{$a->id}", ['nome' => 'b'])->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$a->id}", ['nome' => 'A'])->assertOk();
    }

    public function test_ativa_precisa_ser_booleano(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();

        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => 'talvez'])->assertStatus(422)->assertJsonValidationErrors('ativa');
    }

    // ---------- ativação / inativação ----------

    public function test_inativar_com_saldo_diferente_de_zero_e_permitido_e_conta_continua_consultavel(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Com Saldo', 'banco', '5000.00');

        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])
            ->assertOk()->assertJsonPath('data.ativa', false)->assertJsonPath('data.saldo_atual', '5000.00');
        $this->assertDatabaseHas('audit_logs', ['acao' => 'deactivated', 'modulo' => 'contas', 'registro_id' => $conta->id]);

        $consulta = $this->actingAs($this->como(PerfilSlug::AuxiliarFinanceiro))->getJson('/api/v1/contas?ativa=false');
        $consulta->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.saldo_atual', '5000.00');

        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => true])->assertOk()->assertJsonPath('data.ativa', true);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'activated', 'modulo' => 'contas', 'registro_id' => $conta->id]);
    }

    public function test_atualizacao_sem_mudanca_nao_persiste_nem_audita(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Estavel');
        $antes = $conta->fresh()->updated_at;

        $this->travel(5)->minutes();
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['nome' => 'Estavel', 'ativa' => true])->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertTrue($antes->equalTo($conta->fresh()->updated_at));
    }

    // ---------- exclusão (soft delete) ----------

    public function test_pastor_exclui_conta_sem_uso_por_soft_delete_com_auditoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Descartável', 'caixa', '12.30');

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")->assertOk();

        $this->assertSoftDeleted('contas', ['id' => $conta->id]);
        $this->assertNotNull(Conta::withTrashed()->find($conta->id));
        $this->assertNull(Conta::find($conta->id));

        $log = AuditLog::where('acao', 'deleted')->where('registro_id', $conta->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Descartável', $log->dados_anteriores['nome']);
        $this->assertSame('12.30', $log->dados_anteriores['saldo_inicial']);
    }

    public function test_conta_excluida_nao_aparece_na_listagem_nem_nos_filtros(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $viva = $this->conta('Viva');
        $morta = $this->conta('Morta');

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$morta->id}")->assertOk();

        foreach (['', '?ativa=true', '?ativa=false', '?tipo=banco'] as $qs) {
            $nomes = collect($this->actingAs($pastor)->getJson('/api/v1/contas' . $qs)->json('data'))->pluck('nome')->all();
            $this->assertNotContains('Morta', $nomes);
        }
        $this->actingAs($pastor)->getJson('/api/v1/contas')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $viva->id);
    }

    public function test_exclusao_e_bloqueada_com_409_quando_esta_em_uso(): void
    {
        $this->simularContasEmUso();

        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")
            ->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');

        $this->assertNull($conta->refresh()->deleted_at);
        $this->assertDatabaseMissing('audit_logs', ['acao' => 'deleted']);

        // em uso: deve ser inativada
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])->assertOk();
    }

    public function test_esta_em_uso_e_o_ponto_unico_e_hoje_retorna_falso(): void
    {
        $this->assertFalse(app(ContaService::class)->estaEmUso($this->conta()));
    }
}
