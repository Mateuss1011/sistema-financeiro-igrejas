<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Despesa;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriacaoEEdicaoDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function editar($ator, Despesa|int $despesa, array $corpo)
    {
        $id = $despesa instanceof Despesa ? $despesa->id : $despesa;

        return $this->actingAs($ator)->putJson("/api/v1/despesas/{$id}", $corpo);
    }

    // ================= criação =================

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/despesas')->assertStatus(401);
        $this->postJson('/api/v1/despesas', [])->assertStatus(401);
        $this->putJson('/api/v1/despesas/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/despesas/1')->assertStatus(401);
        $this->postJson('/api/v1/despesas/1/pagar', [])->assertStatus(401);
        $this->postJson('/api/v1/despesas/1/cancelar', [])->assertStatus(401);
        $this->postJson('/api/v1/despesas/1/estornar', [])->assertStatus(401);
    }

    public function test_pastor_tesoureiro_e_auxiliar_criam_despesa_sempre_pendente_e_sem_conta(): void
    {
        $categoria = $this->categoriaDespesa();

        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro] as $perfil) {
            $ator = $this->como($perfil);

            $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['fornecedor_nome' => 'Copel']))
                ->assertStatus(201)
                ->assertJsonPath('data.status', 'pendente')
                ->assertJsonPath('data.conta_id', null)
                ->assertJsonPath('data.conta', null)
                ->assertJsonPath('data.data_pagamento', null)
                ->assertJsonPath('data.pago_por', null)
                ->assertJsonPath('data.valor', '100.00')
                ->assertJsonPath('data.eh_estorno', false)
                ->assertJsonPath('data.descricao', 'Conta de luz')
                ->assertJsonPath('data.fornecedor_nome', 'Copel')
                ->assertJsonPath('data.criado_por.id', $ator->id)
                ->assertJsonPath('data.categoria.id', $categoria->id);
        }

        $this->assertSame(3, Despesa::where('status', 'pendente')->whereNull('conta_id')->count());
    }

    public function test_secretario_e_administrador_sem_excecao_recebem_403_e_com_operar_cria(): void
    {
        $categoria = $this->categoriaDespesa();

        foreach ([PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $this->actingAs($this->como($perfil))->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(403);
        }
        $this->assertDatabaseCount('despesas', 0);

        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar']);
        $this->actingAs($admin)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(201);
    }

    public function test_campos_controlados_pelo_cliente_sao_ignorados_na_criacao(): void
    {
        $conta = $this->conta();
        $outro = $this->como(PerfilSlug::Pastor);
        $ator = $this->como(PerfilSlug::Tesoureiro);

        $id = $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($this->categoriaDespesa(), [
            'conta_id' => $conta->id, 'status' => 'paga', 'criado_por' => $outro->id, 'pago_por' => $outro->id,
            'data_pagamento' => $this->hoje(), 'despesa_estornada_id' => 1, 'motivo_estorno' => 'x', 'saldo_atual' => '999.00',
            'chave_idempotencia' => 'hack', 'hash_payload' => 'x',
        ]))->assertStatus(201)->json('data.id');

        $despesa = Despesa::find($id);
        $this->assertSame('pendente', $despesa->status->value);
        $this->assertNull($despesa->conta_id);
        $this->assertNull($despesa->data_pagamento);
        $this->assertNull($despesa->pago_por);
        $this->assertNull($despesa->despesa_estornada_id);
        $this->assertNull($despesa->chave_idempotencia);
        $this->assertNull($despesa->hash_payload);
        $this->assertSame($ator->id, $despesa->criado_por);
    }

    public function test_valor_invalido_retorna_422_e_valido_e_normalizado(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        foreach (['0', '0.00', '-1.00', '10.005', 'abc', '1,50', '', null, '1000000000000.00', [], true] as $invalido) {
            $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['valor' => $invalido]))
                ->assertStatus(422)->assertJsonValidationErrors('valor');
        }
        $this->assertDatabaseCount('despesas', 0);

        foreach (['100' => '100.00', '100.5' => '100.50', '0.01' => '0.01', '999999999999.99' => '999999999999.99'] as $enviado => $esperado) {
            $resposta = $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['valor' => (string) $enviado]))->assertStatus(201);
            $this->assertSame($esperado, $resposta->json('data.valor'));
            $this->assertIsString($resposta->json('data.valor'));
        }
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['valor' => 150.5]))
            ->assertStatus(201)->assertJsonPath('data.valor', '150.50');
    }

    public function test_descricao_e_obrigatoria_e_fornecedor_opcional_com_limites(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        foreach ([null, '', '   '] as $descricao) {
            $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['descricao' => $descricao]))
                ->assertStatus(422)->assertJsonValidationErrors('descricao');
        }
        $corpo = $this->payloadDespesa($categoria);
        unset($corpo['descricao']);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $corpo)->assertStatus(422)->assertJsonValidationErrors('descricao');

        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['descricao' => str_repeat('a', 256)]))->assertStatus(422);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['fornecedor_nome' => str_repeat('a', 151)]))->assertStatus(422);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['descricao' => str_repeat('a', 255), 'fornecedor_nome' => str_repeat('b', 150)]))->assertStatus(201);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['descricao' => '  Aluguel  ', 'fornecedor_nome' => '  ']))
            ->assertStatus(201)->assertJsonPath('data.descricao', 'Aluguel')->assertJsonPath('data.fornecedor_nome', null);
    }

    public function test_categoria_inexistente_ou_de_entrada_422_e_inativa_409(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $entrada = $this->categoria('Dízimo X');

        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($entrada))->assertStatus(422)->assertJsonValidationErrors('categoria_id');
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($entrada, ['categoria_id' => 99999]))->assertStatus(422);
        $corpo = $this->payloadDespesa($entrada);
        unset($corpo['categoria_id']);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $corpo)->assertStatus(422)->assertJsonValidationErrors('categoria_id');

        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($this->categoriaDespesa('Inativa D', false)))
            ->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_INATIVA');
        $this->assertDatabaseCount('despesas', 0);
    }

    public function test_competencia_futura_ou_invalida_422_e_retroativa_aceita(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $amanha = Carbon::now('America/Sao_Paulo')->addDay()->toDateString();

        foreach ([$amanha, '2999-01-01', '2026-02-30', '2026-13-01', '20/09/2026', 'ontem', '', null] as $data) {
            $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => $data]))
                ->assertStatus(422)->assertJsonValidationErrors('data_competencia');
        }
        $this->assertDatabaseCount('despesas', 0);

        foreach ([$this->hoje(), '2026-01-15', '1990-01-01'] as $data) {
            $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => $data]))
                ->assertStatus(201)->assertJsonPath('data.data_competencia', $data);
        }
    }

    public function test_futuro_e_avaliado_em_sao_paulo_sem_mudar_o_fuso_global(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        Carbon::setTestNow(Carbon::parse('2026-09-21 01:30:00', 'UTC')); // 22:30 de 20/09 em São Paulo
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => '2026-09-21']))->assertStatus(422);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => '2026-09-20']))->assertStatus(201);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_periodo_fechado_bloqueia_a_criacao_e_meses_vizinhos_seguem_abertos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $this->fecharPeriodo('2026-03', $pastor);

        foreach (['2026-03-01', '2026-03-31'] as $data) {
            $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => $data]))
                ->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        }
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => '2026-02-28']))->assertStatus(201);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['data_competencia' => '2026-04-01']))->assertStatus(201);
        $this->assertDatabaseCount('despesas', 2);
    }

    public function test_criacao_e_auditada_sem_dados_pessoais_e_rejeicoes_nao_geram_log(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        $id = $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, [
            'valor' => '250.75', 'data_competencia' => '2026-07-05', 'descricao' => 'Descrição secreta', 'fornecedor_nome' => 'Fornecedor Sigiloso',
        ]))->assertStatus(201)->json('data.id');

        $log = AuditLog::where('modulo', 'despesas')->where('acao', 'created')->sole();
        $this->assertSame($id, $log->registro_id);
        $this->assertSame($ator->id, $log->user_id);
        $this->assertSame([
            'categoria_id' => $categoria->id, 'conta_id' => null, 'valor' => '250.75',
            'data_competencia' => '2026-07-05', 'data_pagamento' => null, 'status' => 'pendente',
        ], $log->dados_novos);
        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
        $this->assertStringNotContainsString('Fornecedor Sigiloso', $bruto);

        $antes = AuditLog::count();
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($this->categoriaDespesa('Inativa E', false)))->assertStatus(409);
        $this->actingAs($ator)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria, ['valor' => '0']))->assertStatus(422);
        $this->actingAs($this->como(PerfilSlug::Secretario))->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(403);
        $this->assertSame($antes, AuditLog::count());
    }

    // ================= edição =================

    public function test_perfis_que_operam_editam_pendente_e_os_demais_nao(): void
    {
        $categoria = $this->categoriaDespesa();
        $criador = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $despesa = $this->despesaPendente($categoria, $criador);

        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro] as $perfil) {
            $this->editar($this->como($perfil), $despesa, ['valor' => '55.10'])->assertOk()->assertJsonPath('data.valor', '55.10');
            $this->editar($this->como($perfil), $despesa, ['valor' => '100.00'])->assertOk();
        }
        $this->editar($this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar']), $despesa, ['descricao' => 'Editada pelo admin'])->assertOk();

        // O auxiliar não edita nem a própria; secretário e admin sem exceção também não.
        foreach ([$criador, $this->como(PerfilSlug::Secretario), $this->como(PerfilSlug::Administrador)] as $ator) {
            $this->editar($ator, $despesa, ['valor' => '1.00'])->assertStatus(403);
        }
        $this->assertSame('100.00', $despesa->fresh()->valor);
    }

    public function test_edicao_altera_apenas_os_campos_permitidos_e_registra_atualizado_por(): void
    {
        $categoria = $this->categoriaDespesa();
        $outra = $this->categoriaDespesa('Outra D');
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $criador = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $despesa = $this->despesaPendente($categoria, $criador);

        $this->editar($tesoureiro, $despesa, [
            'categoria_id' => $outra->id, 'valor' => '12.34', 'data_competencia' => '2026-05-10', 'descricao' => 'Nova', 'fornecedor_nome' => 'Forn',
        ])->assertOk()
            ->assertJsonPath('data.categoria.id', $outra->id)
            ->assertJsonPath('data.valor', '12.34')
            ->assertJsonPath('data.data_competencia', '2026-05-10')
            ->assertJsonPath('data.descricao', 'Nova')
            ->assertJsonPath('data.fornecedor_nome', 'Forn')
            ->assertJsonPath('data.status', 'pendente');

        $atual = $despesa->fresh();
        $this->assertSame($criador->id, $atual->criado_por);
        $this->assertSame($tesoureiro->id, $atual->atualizado_por);
        $this->assertNull($atual->conta_id);
    }

    public function test_campos_proibidos_no_put_retornam_422(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);

        foreach (['status' => 'paga', 'conta_id' => 1, 'data_pagamento' => '2026-01-01', 'pago_por' => 1, 'pago_em' => '2026-01-01',
            'criado_por' => 1, 'atualizado_por' => 1, 'despesa_estornada_id' => 1, 'motivo_estorno' => 'x', 'motivo_cancelamento' => 'x', 'saldo_atual' => '1.00'] as $campo => $valor) {
            $this->editar($tesoureiro, $despesa, [$campo => $valor])->assertStatus(422)->assertJsonValidationErrors($campo);
        }
        $this->assertSame('pendente', $this->statusDe($despesa));
    }

    public function test_edicao_valida_os_campos_como_na_criacao(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);
        $amanha = Carbon::now('America/Sao_Paulo')->addDay()->toDateString();

        $this->editar($tesoureiro, $despesa, ['valor' => '0'])->assertStatus(422)->assertJsonValidationErrors('valor');
        $this->editar($tesoureiro, $despesa, ['valor' => '1.005'])->assertStatus(422);
        $this->editar($tesoureiro, $despesa, ['descricao' => ''])->assertStatus(422)->assertJsonValidationErrors('descricao');
        $this->editar($tesoureiro, $despesa, ['data_competencia' => $amanha])->assertStatus(422)->assertJsonValidationErrors('data_competencia');
        $this->editar($tesoureiro, $despesa, ['categoria_id' => $this->categoria('Dízimo Y')->id])->assertStatus(422)->assertJsonValidationErrors('categoria_id');
        $this->editar($tesoureiro, 99999, ['valor' => '1.00'])->assertStatus(404);
    }

    public function test_edicao_bloqueada_em_estados_terminais_e_no_estorno(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $conta = $this->conta();
        $estornada = $this->despesaEstornada($conta, $categoria, $tesoureiro);
        $estorno = Despesa::whereNotNull('despesa_estornada_id')->first();

        foreach ([$this->despesaPaga($conta, $categoria, $tesoureiro), $this->despesaCancelada($categoria, $tesoureiro), $estornada, $estorno] as $bloqueada) {
            $this->editar($tesoureiro, $bloqueada, ['valor' => '1.00'])->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        }
    }

    public function test_put_sem_mudanca_nao_persiste_nem_audita(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '10.00', 'fornecedor_nome' => 'F']);
        $atualizadoEm = $despesa->fresh()->updated_at;
        $logs = AuditLog::count();

        Carbon::setTestNow(now()->addHour());
        $this->editar($tesoureiro, $despesa, [])->assertOk();
        $this->editar($tesoureiro, $despesa, ['valor' => '10', 'descricao' => 'Despesa pendente', 'fornecedor_nome' => 'F'])->assertOk();

        $this->assertSame($logs, AuditLog::count());
        $this->assertEquals($atualizadoEm, $despesa->fresh()->updated_at);
        $this->assertNull($despesa->fresh()->atualizado_por);
    }

    public function test_categoria_inativa_bloqueia_atribuir_mas_nao_editar_outros_campos(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $tesoureiro);
        $inativa = $this->categoriaDespesa('Inativa F', false);

        $this->editar($tesoureiro, $despesa, ['categoria_id' => $inativa->id])->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_INATIVA');

        $categoria->update(['ativa' => false]);
        // Mantida a mesma categoria (agora inativa): editar outros campos continua permitido.
        $this->editar($tesoureiro, $despesa, ['categoria_id' => $categoria->id, 'valor' => '77.00'])->assertOk()->assertJsonPath('data.valor', '77.00');
    }

    public function test_periodo_fechado_bloqueia_a_edicao_pela_competencia_antiga_e_pela_nova(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $antiga = $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-03-10']);
        $aberta = $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-05-10']);
        $this->fecharPeriodo('2026-03', $pastor);

        $this->editar($tesoureiro, $antiga, ['valor' => '1.00'])->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->editar($tesoureiro, $aberta, ['data_competencia' => '2026-03-20'])->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->editar($tesoureiro, $aberta, ['data_competencia' => '2026-04-20'])->assertOk();
        $this->assertSame('100.00', $antiga->fresh()->valor);
    }

    public function test_edicao_e_auditada_com_nomes_dos_campos_e_sem_dados_pessoais(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $tesoureiro, ['valor' => '10.00']);

        $this->editar($tesoureiro, $despesa, ['valor' => '20.00', 'descricao' => 'Texto pessoal novo', 'fornecedor_nome' => 'Fornecedor Sigiloso'])->assertOk();

        $log = AuditLog::where('modulo', 'despesas')->where('acao', 'updated')->sole();
        $this->assertSame($despesa->id, $log->registro_id);
        $this->assertSame(['valor' => '10.00'], $log->dados_anteriores);
        $this->assertEqualsCanonicalizing(['valor', 'descricao', 'fornecedor_nome'], $log->dados_novos['campos_alterados']);
        $this->assertSame('20.00', $log->dados_novos['valor']);
        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Texto pessoal novo', $bruto);
        $this->assertStringNotContainsString('Fornecedor Sigiloso', $bruto);
    }

    public function test_rejeicoes_de_edicao_nao_geram_auditoria(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);
        $logs = AuditLog::count();

        $this->editar($this->como(PerfilSlug::Secretario), $despesa, ['valor' => '1.00'])->assertStatus(403);
        $this->editar($tesoureiro, $despesa, ['valor' => '0'])->assertStatus(422);
        $this->editar($tesoureiro, $despesa, ['status' => 'paga'])->assertStatus(422);

        $this->assertSame($logs, AuditLog::count());
    }
}
