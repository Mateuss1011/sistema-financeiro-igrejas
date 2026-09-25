<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\AjusteSaldo;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AjustesDeSaldoTest extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_credito_e_debito_validos_alteram_o_saldo_pelo_sentido(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Aj', 'banco', '1000.00');

        $this->ajustar($ator, $conta, ['valor' => '50.25', 'sentido' => 'credito'])
            ->assertStatus(201)
            ->assertJsonPath('data.valor', '50.25')
            ->assertJsonPath('data.sentido', 'credito')
            ->assertJsonPath('data.data_ajuste', $this->hoje())
            ->assertJsonPath('data.conta.id', $conta->id)
            ->assertJsonPath('data.justificativa', 'Diferença encontrada na conciliação')
            ->assertJsonPath('data.criado_por.id', $ator->id);
        $this->assertSame('1050.25', $this->saldoDe($conta));

        $this->ajustar($ator, $conta, ['valor' => '0.25', 'sentido' => 'debito'])->assertStatus(201)->assertJsonPath('data.sentido', 'debito');
        $this->assertSame('1050.00', $this->saldoDe($conta));
        $this->assertSame('1050.00', $this->saldoNaApi($ator, $conta));
        $this->assertSame('1000.00', $conta->fresh()->saldo_inicial); // o saldo inicial nunca muda
    }

    public function test_correcao_e_outro_ajuste_de_sentido_oposto_e_o_historico_e_preservado(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Co', 'banco', '100.00');

        $this->ajustar($ator, $conta, ['valor' => '100.00', 'sentido' => 'credito', 'justificativa' => 'Lançado errado'])->assertStatus(201);
        $this->ajustar($ator, $conta, ['valor' => '100.00', 'sentido' => 'debito', 'justificativa' => 'Correção do ajuste anterior'])->assertStatus(201);

        $this->assertSame('100.00', $this->saldoDe($conta));
        $this->assertSame(2, AjusteSaldo::count());
    }

    public function test_permissoes_de_criacao_e_de_visualizacao(): void
    {
        $conta = $this->conta('Banco Pm', 'banco', '1000.00');

        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro] as $perfil) {
            $this->ajustar($this->como($perfil), $conta, ['valor' => '1.00'])->assertStatus(201);
        }
        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $this->ajustar($this->como($perfil), $conta)->assertStatus(403);
        }
        $this->ajustar($this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar', 'despesas.operar']), $conta)->assertStatus(403); // outras exceções não valem
        $this->assertDatabaseCount('ajustes_saldo', 2);

        $this->ajustar($this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']), $conta, ['valor' => '1.00'])->assertStatus(201);

        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro, PerfilSlug::Administrador] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/ajustes')->assertOk();
        }
        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/ajustes')->assertStatus(403);
        }
    }

    public function test_concessao_e_revogacao_de_ajustes_operar_tem_efeito_imediato(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->como(PerfilSlug::Administrador);
        $conta = $this->conta('Banco Im', 'banco', '10.00');

        $this->ajustar($admin, $conta)->assertStatus(403);
        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'ajustes.operar'])->assertStatus(201);
        $this->ajustar($admin->refresh(), $conta)->assertStatus(201);
        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao/ajustes.operar")->assertOk();
        $this->ajustar($admin->refresh(), $conta)->assertStatus(403);
    }

    public function test_conta_inexistente_excluida_ou_inativa(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $excluida = $this->conta('Excluída', 'banco', '0.00');
        $excluida->delete();
        $inativa = $this->conta('Inativa', 'banco', '100.00', false);

        $this->ajustar($ator, $excluida)->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->ajustar($ator, $excluida, ['conta_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->actingAs($ator)->postJson('/api/v1/ajustes', ['valor' => '1.00', 'sentido' => 'credito', 'data_ajuste' => $this->hoje(), 'justificativa' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->ajustar($ator, $inativa)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
        $this->assertDatabaseCount('ajustes_saldo', 0);
    }

    public function test_valor_e_sentido_invalidos_retornam_422_e_o_valor_nunca_tem_sinal(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Vl', 'banco', '1000.00');

        foreach (['0', '0.00', '-1.00', '-100', '10.005', 'abc', '1,50', '', null, '1000000000000.00', [], true] as $invalido) {
            $this->ajustar($ator, $conta, ['valor' => $invalido])->assertStatus(422)->assertJsonValidationErrors('valor');
        }
        foreach (['', null, 'ajuste', 'CREDITO', 'entrada', 'saida', 1] as $sentido) {
            $this->ajustar($ator, $conta, ['sentido' => $sentido])->assertStatus(422)->assertJsonValidationErrors('sentido');
        }
        $this->assertDatabaseCount('ajustes_saldo', 0);

        foreach (['100' => '100.00', '100.5' => '100.50', '0.01' => '0.01'] as $enviado => $esperado) {
            $this->assertSame($esperado, $this->ajustar($ator, $conta, ['valor' => (string) $enviado])->assertStatus(201)->json('data.valor'));
        }
        $this->ajustar($ator, $conta, ['valor' => 150.5])->assertStatus(201)->assertJsonPath('data.valor', '150.50');
    }

    public function test_saldo_alvo_e_campos_controlados_sao_ignorados(): void
    {
        $outro = $this->como(PerfilSlug::Pastor);
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Sa', 'banco', '100.00');

        $id = $this->ajustar($ator, $conta, [
            'valor' => '10.00', 'saldo_alvo' => '999999.00', 'saldo_atual' => '999999.00', 'saldo' => '1.00',
            'criado_por' => $outro->id, 'chave_idempotencia' => 'hack', 'hash_payload' => 'x',
        ])->assertStatus(201)->json('data.id');

        $a = AjusteSaldo::find($id);
        $this->assertSame($ator->id, $a->criado_por);
        $this->assertNull($a->chave_idempotencia);
        $this->assertSame('110.00', $this->saldoDe($conta)); // só o valor + sentido contam
        $colunas = collect(DB::select('SHOW COLUMNS FROM ajustes_saldo'))->pluck('Field')->all();
        $this->assertNotContains('saldo_alvo', $colunas);
        $this->assertNotContains('saldo_atual', $colunas);
    }

    public function test_data_obrigatoria_nao_futura_e_retroativa_aceita(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Dt', 'banco', '1000.00');
        $amanha = Carbon::now('America/Sao_Paulo')->addDay()->toDateString();

        foreach ([$amanha, '2999-01-01', '2026-02-30', '20/09/2026', 'ontem', '', null] as $data) {
            $this->ajustar($ator, $conta, ['data_ajuste' => $data])->assertStatus(422)->assertJsonValidationErrors('data_ajuste');
        }
        foreach ([$this->hoje(), '2026-01-15', '1990-01-01'] as $data) {
            $this->ajustar($ator, $conta, ['data_ajuste' => $data, 'valor' => '1.00'])->assertStatus(201)->assertJsonPath('data.data_ajuste', $data);
        }

        Carbon::setTestNow(Carbon::parse('2026-09-21 01:30:00', 'UTC')); // 22:30 de 20/09 em São Paulo
        $this->ajustar($ator, $conta, ['data_ajuste' => '2026-09-21'])->assertStatus(422);
        $this->ajustar($ator, $conta, ['data_ajuste' => '2026-09-20', 'valor' => '1.00'])->assertStatus(201);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_periodo_fechado_bloqueia_ate_o_pastor_e_periodo_inexistente_e_aberto(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Pf', 'banco', '1000.00');
        $this->fecharPeriodo('2026-03', $pastor);

        foreach ([$pastor, $tesoureiro] as $ator) {
            $this->ajustar($ator, $conta, ['data_ajuste' => '2026-03-15'])->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        }
        $this->assertDatabaseCount('ajustes_saldo', 0);
        $this->ajustar($tesoureiro, $conta, ['data_ajuste' => '2026-04-15', 'valor' => '1.00'])->assertStatus(201);
        $this->ajustar($tesoureiro, $conta, ['data_ajuste' => '2019-06-15', 'valor' => '1.00'])->assertStatus(201);
    }

    public function test_justificativa_obrigatoria_entre_3_e_500_caracteres(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ju', 'banco', '1000.00');

        foreach ([null, '', '   ', 'ab', str_repeat('x', 501)] as $j) {
            $this->ajustar($ator, $conta, ['justificativa' => $j])->assertStatus(422)->assertJsonValidationErrors('justificativa');
        }
        $corpo = $this->corpoAjuste($conta);
        unset($corpo['justificativa']);
        $this->actingAs($ator)->postJson('/api/v1/ajustes', $corpo)->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->ajustar($ator, $conta, ['justificativa' => 'abc', 'valor' => '1.00'])->assertStatus(201);
        $this->ajustar($ator, $conta, ['justificativa' => str_repeat('x', 500), 'valor' => '1.00'])->assertStatus(201);
        $this->ajustar($ator, $conta, ['justificativa' => '  Com espaços  ', 'valor' => '1.00'])->assertStatus(201)->assertJsonPath('data.justificativa', 'Com espaços');
    }

    // ---------------- saldo: caixa e banco ----------------

    public function test_debito_em_caixa_sem_saldo_bloqueia_mesmo_com_confirmacao(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Aj', 'caixa', '50.00');

        $this->ajustar($ator, $caixa, ['valor' => '50.01', 'sentido' => 'debito'])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->ajustar($ator, $caixa, ['valor' => '50.01', 'sentido' => 'debito', 'confirmar_saldo_negativo' => true])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->assertDatabaseCount('ajustes_saldo', 0);
        $this->assertSame('50.00', $this->saldoDe($caixa));
        $this->assertSame(0, AuditLog::where('modulo', 'ajustes_saldo')->count());

        $this->ajustar($ator, $caixa, ['valor' => '50.00', 'sentido' => 'debito'])->assertStatus(201); // exatamente zero
        $this->assertSame('0.00', $this->saldoDe($caixa));
    }

    public function test_credito_em_caixa_nunca_e_bloqueado_por_saldo(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Cr', 'caixa', '0.00');

        $this->ajustar($ator, $caixa, ['valor' => '999.99', 'sentido' => 'credito'])->assertStatus(201);
        $this->assertSame('999.99', $this->saldoDe($caixa));
    }

    public function test_debito_em_banco_que_ficaria_negativo_exige_confirmacao_e_o_credito_nao(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $banco = $this->conta('Banco Ng', 'banco', '30.00');

        $this->ajustar($ator, $banco, ['valor' => '100.00', 'sentido' => 'debito'])->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->ajustar($ator, $banco, ['valor' => '100.00', 'sentido' => 'debito', 'confirmar_saldo_negativo' => false])->assertStatus(409);
        $this->assertDatabaseCount('ajustes_saldo', 0);
        $this->assertSame('30.00', $this->saldoDe($banco));

        $this->ajustar($ator, $banco, ['valor' => '100.00', 'sentido' => 'debito', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertSame('-70.00', $this->saldoDe($banco));
        $this->assertTrue(AuditLog::where('acao', 'created')->sole()->dados_novos['saldo_negativo_confirmado']);

        // Banco já negativo: piorar exige confirmação; creditar nunca.
        $this->ajustar($ator, $banco, ['valor' => '0.01', 'sentido' => 'debito'])->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->ajustar($ator, $banco, ['valor' => '10.00', 'sentido' => 'credito'])->assertStatus(201);
        $this->assertSame('-60.00', $this->saldoDe($banco));
    }

    public function test_confirmar_saldo_negativo_deve_ser_booleano(): void
    {
        $this->ajustar($this->como(PerfilSlug::Pastor), $this->conta('Banco Bo', 'banco', '10.00'), ['confirmar_saldo_negativo' => 'talvez'])
            ->assertStatus(422)->assertJsonValidationErrors('confirmar_saldo_negativo');
    }

    // ---------------- imutabilidade ----------------

    public function test_ajuste_e_imutavel_sem_put_patch_delete_nem_get_individual_nem_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $ajuste = $this->ajusteDireto($this->conta('Banco Im', 'banco', '100.00'), $pastor, '10.00');

        foreach (['put', 'patch', 'delete', 'get'] as $metodo) {
            $status = $this->actingAs($pastor)->{$metodo . 'Json'}("/api/v1/ajustes/{$ajuste->id}", ['valor' => '1.00'])->status();
            $this->assertContains($status, [404, 405], "$metodo deveria ser inexistente");
        }
        $this->assertContains($this->actingAs($pastor)->postJson("/api/v1/ajustes/{$ajuste->id}/estornar", ['justificativa' => 'x'])->status(), [404, 405]);
        $this->assertSame('10.00', $ajuste->fresh()->valor);
        $this->assertNotNull(AjusteSaldo::find($ajuste->id));

        $colunas = collect(DB::select('SHOW COLUMNS FROM ajustes_saldo'))->pluck('Field')->all();
        foreach (['status', 'deleted_at', 'ajuste_estornado_id'] as $proibida) {
            $this->assertNotContains($proibida, $colunas);
        }
    }

    // ---------------- auditoria ----------------

    public function test_criacao_e_auditada_com_conta_valor_sentido_data_e_justificativa_e_rejeicoes_nao_geram_log(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Au', 'banco', '1000.00');

        $id = $this->ajustar($ator, $conta, ['valor' => '12.34', 'sentido' => 'debito', 'data_ajuste' => '2026-07-05', 'justificativa' => 'Tarifa bancária não lançada'])->assertStatus(201)->json('data.id');

        $log = AuditLog::where('modulo', 'ajustes_saldo')->where('acao', 'created')->sole();
        $this->assertSame($id, $log->registro_id);
        $this->assertSame($ator->id, $log->user_id);
        $this->assertSame([
            'conta_id' => $conta->id, 'valor' => '12.34', 'sentido' => 'debito', 'data_ajuste' => '2026-07-05', 'saldo_negativo_confirmado' => false,
        ], $log->dados_novos);
        $this->assertSame('Tarifa bancária não lançada', $log->justificativa);
        $this->assertNotNull($log->created_at);

        $logs = AuditLog::count();
        $this->ajustar($ator, $conta, ['valor' => '0'])->assertStatus(422);
        $this->ajustar($ator, $conta, ['valor' => '999999.00', 'sentido' => 'debito'])->assertStatus(409);
        $this->ajustar($this->como(PerfilSlug::Secretario), $conta)->assertStatus(403);
        $this->assertSame($logs, AuditLog::count());
    }

    // ---------------- listagem ----------------

    public function test_listagem_filtros_ordenacao_paginacao_e_meta(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '0.00');
        $b = $this->conta('Caixa B', 'caixa', '0.00');
        $a1 = $this->ajusteDireto($a, $pastor, '10.00', 'credito', ['data_ajuste' => '2026-01-10']);
        $a2 = $this->ajusteDireto($b, $pastor, '20.00', 'credito', ['data_ajuste' => '2026-03-10']);
        $a3 = $this->ajusteDireto($a, $pastor, '5.00', 'debito', ['data_ajuste' => '2026-03-10']);

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/ajustes')->assertOk();
        $resposta->assertJsonStructure([
            'data' => [['id', 'conta' => ['id', 'nome', 'tipo'], 'conta_id', 'valor', 'sentido', 'data_ajuste', 'justificativa', 'criado_por' => ['id', 'name'], 'created_at']],
            'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total', 'permissoes'],
        ]);
        $this->assertIsString($resposta->json('data.0.valor'));
        $resposta->assertJsonPath('meta.permissoes.criar', true);
        foreach (['chave_idempotencia', 'hash_payload', 'updated_at'] as $interno) {
            $this->assertArrayNotHasKey($interno, $resposta->json('data.0'));
        }

        $ids = fn (string $q = '') => collect($this->actingAs($pastor)->getJson('/api/v1/ajustes' . $q)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$a3->id, $a2->id, $a1->id], $ids());
        $this->assertSame([$a1->id, $a2->id, $a3->id], $ids('?ordenar=data_ajuste'));
        $this->assertSame([$a2->id, $a1->id, $a3->id], $ids('?ordenar=-valor'));
        $this->assertEqualsCanonicalizing([$a2->id, $a3->id], $ids('?data_de=2026-03-01'));
        $this->assertEqualsCanonicalizing([$a1->id, $a3->id], $ids('?conta_id=' . $a->id));
        $this->assertEqualsCanonicalizing([$a3->id], $ids('?sentido=debito'));
        $this->actingAs($pastor)->getJson('/api/v1/ajustes?por_pagina=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);

        foreach (['sentido=x', 'ordenar=senha', 'por_pagina=101', 'data_de=ontem', 'conta_id=abc'] as $q) {
            $this->actingAs($pastor)->getJson('/api/v1/ajustes?' . $q)->assertStatus(422);
        }
        $this->actingAs($this->como(PerfilSlug::Administrador))->getJson('/api/v1/ajustes')->assertOk()->assertJsonPath('meta.permissoes.criar', false);
    }

    // ---------------- idempotência ----------------

    public function test_mesma_chave_e_mesmo_payload_e_replay_sem_novo_ajuste(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Id', 'banco', '1000.00');

        $primeira = $this->ajustar($ator, $conta, [], 'aj-1')->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $segunda = $this->ajustar($ator, $conta, [], 'aj-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->ajustar($ator, $conta, [], 'aj-1')->assertStatus(200);

        $this->assertSame($primeira->json('data.id'), $segunda->json('data.id'));
        $this->assertDatabaseCount('ajustes_saldo', 1);
        $this->assertSame('1050.00', $this->saldoDe($conta));
        $this->assertSame(1, AuditLog::where('modulo', 'ajustes_saldo')->count());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', AjusteSaldo::sole()->hash_payload);
    }

    public function test_mesma_chave_com_payload_diferente_retorna_409_e_usuarios_diferentes_podem_repetir(): void
    {
        $x = $this->como(PerfilSlug::Tesoureiro);
        $y = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Id2', 'banco', '1000.00');
        $outra = $this->conta('Banco Id3', 'banco', '0.00');
        $this->ajustar($x, $conta, ['data_ajuste' => '2026-05-01'], 'k')->assertStatus(201);

        foreach ([['valor' => '50.01'], ['sentido' => 'debito'], ['conta_id' => $outra->id], ['data_ajuste' => '2026-05-02'], ['justificativa' => 'Outra justificativa']] as $variacao) {
            $this->ajustar($x, $conta, array_merge(['data_ajuste' => '2026-05-01'], $variacao), 'k')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUTILIZADA');
        }
        $this->ajustar($y, $conta, ['data_ajuste' => '2026-05-01'], 'k')->assertStatus(201);
        $this->assertDatabaseCount('ajustes_saldo', 2);
    }

    public function test_replay_compara_valores_normalizados_e_chave_invalida_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Id4', 'banco', '1000.00');

        $this->ajustar($ator, $conta, ['valor' => '10.5', 'justificativa' => '  Tarifa  '], 'n')->assertStatus(201);
        $this->ajustar($ator, $conta, ['valor' => '10.50', 'justificativa' => 'Tarifa'], 'n')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->ajustar($ator, $conta, ['valor' => 10.5, 'justificativa' => 'Tarifa'], 'n')->assertStatus(200);

        foreach ([str_repeat('a', 65), 'com espaço', 'acentuação'] as $chave) {
            $this->ajustar($ator, $conta, [], $chave)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        }
        $this->ajustar($ator, $conta, ['idempotency_key' => 'no-corpo', 'chave_idempotencia' => 'no-corpo'])->assertStatus(201);
        $this->assertSame(1, AjusteSaldo::whereNull('chave_idempotencia')->count());
    }

    public function test_unique_e_checks_do_banco_protegem_fora_da_aplicacao(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Db', 'banco', '10.00');
        $hash = str_repeat('a', 64);
        $this->ajusteDireto($conta, $ator, '1.00', 'credito', ['chave_idempotencia' => 'db', 'hash_payload' => $hash]);

        foreach ([
            ['UNIQUE', 'ajustes_idempotencia_unica', fn () => $this->ajusteDireto($conta, $ator, '1.00', 'credito', ['chave_idempotencia' => 'db', 'hash_payload' => $hash])],
            ['CHECK chave/hash', 'chk_ajustes_idempotencia', fn () => $this->ajusteDireto($conta, $ator, '1.00', 'credito', ['chave_idempotencia' => 'sem-hash'])],
            ['CHECK valor', 'chk_ajustes_valor_positivo', fn () => $this->ajusteDireto($conta, $ator, '0.00')],
            ['CHECK justificativa', 'chk_ajustes_justificativa_minima', fn () => $this->ajusteDireto($conta, $ator, '1.00', 'credito', ['justificativa' => 'ab'])],
        ] as [$nome, $trecho, $acao]) {
            try {
                $acao();
                $this->fail("$nome deveria barrar");
            } catch (QueryException $e) {
                $this->assertStringContainsString($trecho, $e->getMessage(), $nome);
            }
        }
    }
}
