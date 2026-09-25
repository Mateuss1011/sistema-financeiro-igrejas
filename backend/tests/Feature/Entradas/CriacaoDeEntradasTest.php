<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Entrada;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriacaoDeEntradasTest extends TestCase
{
    use RefreshDatabase, CenarioEntradas;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->postJson('/api/v1/entradas', [])->assertStatus(401);
        $this->getJson('/api/v1/entradas')->assertStatus(401);
        $this->postJson('/api/v1/entradas/1/estornar', [])->assertStatus(401);
    }

    public function test_pastor_tesoureiro_e_auxiliar_criam_entrada(): void
    {
        $conta = $this->conta();
        $categoria = $this->categoria();

        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro] as $perfil) {
            $ator = $this->como($perfil);

            $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['descricao' => 'Culto', 'contribuinte_nome' => 'Maria']))
                ->assertStatus(201)
                ->assertJsonPath('data.status', 'confirmada')
                ->assertJsonPath('data.valor', '100.00')
                ->assertJsonPath('data.eh_estorno', false)
                ->assertJsonPath('data.entrada_estornada_id', null)
                ->assertJsonPath('data.estornavel', true)
                ->assertJsonPath('data.criado_por.id', $ator->id)
                ->assertJsonPath('data.categoria.id', $categoria->id)
                ->assertJsonPath('data.conta.id', $conta->id)
                ->assertJsonPath('data.descricao', 'Culto')
                ->assertJsonPath('data.contribuinte_nome', 'Maria');
        }

        $this->assertDatabaseCount('entradas', 3);
    }

    public function test_secretario_e_administrador_sem_excecao_recebem_403(): void
    {
        $conta = $this->conta();
        $categoria = $this->categoria();

        foreach ([PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $this->actingAs($this->como($perfil))->postJson('/api/v1/entradas', $this->payload($conta, $categoria))->assertStatus(403);
        }

        $this->assertDatabaseCount('entradas', 0);
    }

    public function test_administrador_com_excecao_entradas_operar_cria(): void
    {
        $this->actingAs($this->administradorOperador())
            ->postJson('/api/v1/entradas', $this->payload($this->conta(), $this->categoria()))
            ->assertStatus(201);
    }

    public function test_pastor_concede_e_revoga_entradas_operar_pela_api_e_o_efeito_e_imediato(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->como(PerfilSlug::Administrador);
        $corpo = $this->payload($this->conta(), $this->categoria());

        $this->actingAs($admin)->postJson('/api/v1/entradas', $corpo)->assertStatus(403);

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'entradas.operar'])->assertStatus(201);
        $this->actingAs($admin->refresh())->postJson('/api/v1/entradas', $corpo)->assertStatus(201);

        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao/entradas.operar")->assertOk();
        $this->actingAs($admin->refresh())->postJson('/api/v1/entradas', $corpo)->assertStatus(403);
    }

    public function test_campos_controlados_pelo_backend_sao_ignorados_no_payload(): void
    {
        $conta = $this->conta();
        $categoria = $this->categoria();
        $outro = $this->como(PerfilSlug::Pastor);
        $ator = $this->como(PerfilSlug::Tesoureiro);

        $id = $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, [
            'status' => 'estornada', 'criado_por' => $outro->id, 'entrada_estornada_id' => 1,
            'motivo_estorno' => 'x', 'saldo_atual' => '999.00', 'chave_idempotencia' => 'hack',
        ]))->assertStatus(201)->json('data.id');

        $entrada = Entrada::find($id);
        $this->assertSame('confirmada', $entrada->status->value);
        $this->assertSame($ator->id, $entrada->criado_por);
        $this->assertNull($entrada->entrada_estornada_id);
        $this->assertNull($entrada->motivo_estorno);
        $this->assertNull($entrada->chave_idempotencia);
    }

    // ---------- valor ----------

    public function test_valor_invalido_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        foreach (['0', '0.00', '-1.00', '10.005', 'abc', '1,50', '', null, '1000000000000.00', [], true] as $invalido) {
            $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['valor' => $invalido]))
                ->assertStatus(422)->assertJsonValidationErrors('valor');
        }

        $this->actingAs($ator)->postJson('/api/v1/entradas', ['categoria_id' => $categoria->id, 'conta_id' => $conta->id, 'data_competencia' => $this->hoje()])
            ->assertStatus(422)->assertJsonValidationErrors('valor');

        $this->assertDatabaseCount('entradas', 0);
    }

    public function test_valor_valido_e_normalizado_para_duas_casas_sem_float(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        foreach (['100' => '100.00', '100.5' => '100.50', '0.01' => '0.01', '999999999999.99' => '999999999999.99'] as $enviado => $esperado) {
            $resposta = $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['valor' => (string) $enviado]))->assertStatus(201);
            $this->assertSame($esperado, $resposta->json('data.valor'));
            $this->assertIsString($resposta->json('data.valor'));
        }

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['valor' => 150.5]))
            ->assertStatus(201)->assertJsonPath('data.valor', '150.50');
    }

    // ---------- categoria ----------

    public function test_categoria_inexistente_ou_de_despesa_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $despesa = $this->categoria('Aluguel X', true, 'despesa');

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $despesa))
            ->assertStatus(422)->assertJsonValidationErrors('categoria_id');
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $despesa, ['categoria_id' => 99999]))
            ->assertStatus(422)->assertJsonValidationErrors('categoria_id');
        $this->actingAs($ator)->postJson('/api/v1/entradas', ['conta_id' => $conta->id, 'valor' => '1.00', 'data_competencia' => $this->hoje()])
            ->assertStatus(422)->assertJsonValidationErrors('categoria_id');
    }

    public function test_categoria_inativa_retorna_409_categoria_inativa(): void
    {
        $this->actingAs($this->como(PerfilSlug::Tesoureiro))
            ->postJson('/api/v1/entradas', $this->payload($this->conta(), $this->categoria('Inativa X', false)))
            ->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_INATIVA');

        $this->assertDatabaseCount('entradas', 0);
    }

    // ---------- conta ----------

    public function test_conta_inexistente_ou_excluida_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoria();
        $excluida = $this->conta('Conta Excluída');
        $excluida->delete();

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($excluida, $categoria))
            ->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($excluida, $categoria, ['conta_id' => 99999]))
            ->assertStatus(422)->assertJsonValidationErrors('conta_id');
    }

    public function test_conta_inativa_retorna_409_conta_inativa(): void
    {
        $this->actingAs($this->como(PerfilSlug::Tesoureiro))
            ->postJson('/api/v1/entradas', $this->payload($this->conta('Inativa', 'banco', '0', false), $this->categoria()))
            ->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');

        $this->assertDatabaseCount('entradas', 0);
    }

    public function test_conta_banco_com_saldo_negativo_aceita_entrada_e_caixa_tambem(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoria();
        $banco = $this->conta('Banco Negativo', 'banco', '-500.00');
        $caixa = $this->conta('Caixa Y', 'caixa', '0.00');

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($banco, $categoria))->assertStatus(201);
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($caixa, $categoria))->assertStatus(201);
    }

    // ---------- data ----------

    public function test_data_invalida_ou_futura_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $amanha = Carbon::now('America/Sao_Paulo')->addDay()->toDateString();

        foreach ([$amanha, '2999-01-01', '2026-02-30', '2026-13-01', '20/09/2026', 'ontem', '2026-9-1', '', null] as $data) {
            $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => $data]))
                ->assertStatus(422)->assertJsonValidationErrors('data_competencia');
        }

        $this->assertDatabaseCount('entradas', 0);
    }

    public function test_data_de_hoje_e_retroativa_sem_limite_minimo_sao_aceitas(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        foreach ([$this->hoje(), '2026-01-15', '2010-06-30', '1990-01-01'] as $data) {
            $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => $data]))
                ->assertStatus(201)->assertJsonPath('data.data_competencia', $data);
        }
    }

    public function test_futuro_e_avaliado_no_fuso_de_sao_paulo_sem_mudar_o_fuso_global(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        // 01:30 UTC de 21/09 = 22:30 de 20/09 em São Paulo (UTC-3): 21/09 ainda é futuro no Brasil.
        Carbon::setTestNow(Carbon::parse('2026-09-21 01:30:00', 'UTC'));
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-09-21']))
            ->assertStatus(422)->assertJsonValidationErrors('data_competencia');
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-09-20']))
            ->assertStatus(201);

        // 03:30 UTC de 21/09 = 00:30 de 21/09 em São Paulo: 21/09 já é hoje.
        Carbon::setTestNow(Carbon::parse('2026-09-21 03:30:00', 'UTC'));
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-09-21']))
            ->assertStatus(201);

        $this->assertSame('UTC', config('app.timezone'));
    }

    // ---------- período ----------

    public function test_periodo_fechado_bloqueia_a_criacao_e_outros_meses_seguem_abertos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $this->fecharPeriodo('2026-03', $pastor);

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-03-10']))
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-03-31']))
            ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');

        // Meses vizinhos e período sem linha na tabela = abertos.
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-02-28']))->assertStatus(201);
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['data_competencia' => '2026-04-01']))->assertStatus(201);

        $this->assertDatabaseCount('entradas', 2);
    }

    // ---------- texto livre ----------

    public function test_descricao_e_contribuinte_sao_opcionais_e_normalizados(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['descricao' => '   ', 'contribuinte_nome' => '']))
            ->assertStatus(201)->assertJsonPath('data.descricao', null)->assertJsonPath('data.contribuinte_nome', null);

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['descricao' => '  Oferta de domingo  ']))
            ->assertStatus(201)->assertJsonPath('data.descricao', 'Oferta de domingo');

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['descricao' => str_repeat('a', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors('descricao');
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['contribuinte_nome' => str_repeat('a', 151)]))
            ->assertStatus(422)->assertJsonValidationErrors('contribuinte_nome');
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['descricao' => str_repeat('a', 255), 'contribuinte_nome' => str_repeat('b', 150)]))
            ->assertStatus(201);
    }

    // ---------- imutabilidade ----------

    public function test_nao_existem_put_delete_nem_show_para_entradas(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Pastor);
        $entrada = $this->entrada($this->conta(), $this->categoria(), $tesoureiro);

        foreach (['put', 'patch', 'delete', 'get'] as $metodo) {
            $status = $this->actingAs($tesoureiro)->{$metodo . 'Json'}("/api/v1/entradas/{$entrada->id}", ['valor' => '1.00'])->status();
            $this->assertContains($status, [404, 405], "$metodo deveria ser inexistente");
        }

        $this->assertSame('100.00', $entrada->fresh()->valor);
        $this->assertNotNull(Entrada::find($entrada->id));
    }

    public function test_entrada_nao_tem_soft_delete(): void
    {
        $this->assertFalse(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(Entrada::class), true));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('entradas', 'deleted_at'));
    }

    // ---------- auditoria ----------

    public function test_criacao_e_auditada_sem_dados_pessoais(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        $id = $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, [
            'valor' => '250.75', 'data_competencia' => '2026-07-05', 'descricao' => 'Descrição secreta', 'contribuinte_nome' => 'João da Silva',
        ]))->assertStatus(201)->json('data.id');

        $log = AuditLog::where('modulo', 'entradas')->where('acao', 'created')->sole();

        $this->assertSame($id, $log->registro_id);
        $this->assertSame($ator->id, $log->user_id);
        $this->assertSame([
            'categoria_id' => $categoria->id,
            'conta_id' => $conta->id,
            'valor' => '250.75',
            'data_competencia' => '2026-07-05',
            'status' => 'confirmada',
        ], $log->dados_novos);

        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('João da Silva', $bruto);
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
        $this->assertStringNotContainsString('contribuinte_nome', $bruto);
    }

    public function test_tentativas_rejeitadas_nao_geram_auditoria(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();

        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $this->categoria('Inativa Z', false)))->assertStatus(409);
        $this->actingAs($ator)->postJson('/api/v1/entradas', $this->payload($conta, $this->categoria(), ['valor' => '0']))->assertStatus(422);
        $this->actingAs($this->como(PerfilSlug::Secretario))->postJson('/api/v1/entradas', $this->payload($conta, $this->categoria('Outra')))->assertStatus(403);

        $this->assertSame(0, AuditLog::where('modulo', 'entradas')->count());
    }
}
