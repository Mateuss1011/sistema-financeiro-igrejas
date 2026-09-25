<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Transferencia;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriacaoDeTransferenciasTest extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/transferencias')->assertStatus(401);
        $this->postJson('/api/v1/transferencias', [])->assertStatus(401);
        $this->postJson('/api/v1/transferencias/1/estornar', [])->assertStatus(401);
        $this->getJson('/api/v1/ajustes')->assertStatus(401);
        $this->postJson('/api/v1/ajustes', [])->assertStatus(401);
    }

    public function test_pastor_e_tesoureiro_criam_e_o_saldo_das_duas_contas_muda_sem_alterar_o_total(): void
    {
        $origem = $this->conta('Banco O', 'banco', '1000.00');
        $destino = $this->conta('Caixa D', 'caixa', '50.00');

        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro] as $perfil) {
            $ator = $this->como($perfil);
            $totalAntes = bcadd($this->saldoDe($origem), $this->saldoDe($destino), 2);

            $this->transferir($ator, $origem, $destino, ['valor' => '100.00', 'descricao' => 'Reforço de caixa'])
                ->assertStatus(201)
                ->assertJsonPath('data.status', 'confirmada')
                ->assertJsonPath('data.valor', '100.00')
                ->assertJsonPath('data.eh_estorno', false)
                ->assertJsonPath('data.conta_origem.id', $origem->id)
                ->assertJsonPath('data.conta_origem.tipo', 'banco')
                ->assertJsonPath('data.conta_destino.id', $destino->id)
                ->assertJsonPath('data.conta_destino.tipo', 'caixa')
                ->assertJsonPath('data.data_transferencia', $this->hoje())
                ->assertJsonPath('data.descricao', 'Reforço de caixa')
                ->assertJsonPath('data.criado_por.id', $ator->id)
                ->assertJsonPath('data.estornavel', true);

            $this->assertSame($totalAntes, bcadd($this->saldoDe($origem), $this->saldoDe($destino), 2), 'o saldo consolidado não muda');
        }

        $this->assertSame('800.00', $this->saldoDe($origem));
        $this->assertSame('250.00', $this->saldoDe($destino));
        $this->assertSame('800.00', $this->saldoNaApi($this->como(PerfilSlug::Pastor), $origem));
    }

    public function test_todas_as_combinacoes_de_tipo_sao_aceitas(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $b1 = $this->conta('Banco 1', 'banco', '500.00');
        $b2 = $this->conta('Banco 2', 'banco', '500.00');
        $c1 = $this->conta('Caixa 1', 'caixa', '500.00');
        $c2 = $this->conta('Caixa 2', 'caixa', '500.00');

        foreach ([[$b1, $b2], [$b1, $c1], [$c1, $b2], [$c1, $c2]] as [$origem, $destino]) {
            $this->transferir($ator, $origem, $destino, ['valor' => '10.00'])->assertStatus(201);
        }
        $this->assertSame(4, Transferencia::count());
    }

    public function test_permissoes_de_criacao(): void
    {
        $origem = $this->conta('Banco P', 'banco', '1000.00');
        $destino = $this->conta('Banco Q', 'banco', '0.00');

        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $this->transferir($this->como($perfil), $origem, $destino)->assertStatus(403);
        }
        // Exceção de ESTORNO sem `operar` não cria.
        $this->transferir($this->comExcecoes(PerfilSlug::Administrador, ['transferencias.estornar']), $origem, $destino)->assertStatus(403);
        $this->assertDatabaseCount('transferencias', 0);

        $this->transferir($this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar']), $origem, $destino)->assertStatus(201);
    }

    public function test_campos_controlados_pelo_cliente_sao_ignorados(): void
    {
        $outro = $this->como(PerfilSlug::Pastor);
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $origem = $this->conta('Banco O', 'banco', '1000.00');
        $destino = $this->conta('Banco D', 'banco', '0.00');

        $id = $this->transferir($ator, $origem, $destino, [
            'status' => 'estornada', 'criado_por' => $outro->id, 'transferencia_estornada_id' => 1, 'motivo_estorno' => 'x',
            'saldo_atual' => '999999.00', 'chave_idempotencia' => 'hack', 'hash_payload' => 'x',
        ])->assertStatus(201)->json('data.id');

        $t = Transferencia::find($id);
        $this->assertSame('confirmada', $t->status->value);
        $this->assertSame($ator->id, $t->criado_por);
        $this->assertNull($t->transferencia_estornada_id);
        $this->assertNull($t->motivo_estorno);
        $this->assertNull($t->chave_idempotencia);
        $this->assertNull($t->hash_payload);
        $this->assertSame('900.00', $this->saldoDe($origem));
    }

    // ---------------- validação de contas ----------------

    public function test_mesma_conta_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco S', 'banco', '1000.00');

        $this->transferir($ator, $conta, $conta)->assertStatus(422)->assertJsonValidationErrors('conta_destino_id');
        $this->assertDatabaseCount('transferencias', 0);
    }

    public function test_contas_inexistentes_ou_excluidas_retornam_422(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $boa = $this->conta('Banco Boa', 'banco', '1000.00');
        $excluida = $this->conta('Conta Excluída', 'banco', '0.00');
        $excluida->delete();

        $this->transferir($ator, $boa, $excluida)->assertStatus(422)->assertJsonValidationErrors('conta_destino_id');
        $this->transferir($ator, $excluida, $boa)->assertStatus(422)->assertJsonValidationErrors('conta_origem_id');
        $this->transferir($ator, $boa, $boa, ['conta_destino_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('conta_destino_id');
        $this->transferir($ator, $boa, $boa, ['conta_origem_id' => 99999, 'conta_destino_id' => $boa->id])->assertStatus(422)->assertJsonValidationErrors('conta_origem_id');
        $this->actingAs($ator)->postJson('/api/v1/transferencias', ['valor' => '1.00', 'data_transferencia' => $this->hoje()])->assertStatus(422)->assertJsonValidationErrors(['conta_origem_id', 'conta_destino_id']);
    }

    public function test_conta_inativa_de_origem_ou_de_destino_retorna_409(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $ativa = $this->conta('Banco Ativo', 'banco', '1000.00');
        $inativa = $this->conta('Banco Inativo', 'banco', '1000.00', false);

        $this->transferir($ator, $ativa, $inativa)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
        $this->transferir($ator, $inativa, $ativa)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
        $this->assertDatabaseCount('transferencias', 0);
    }

    // ---------------- valor e data ----------------

    public function test_valor_invalido_retorna_422_e_valido_e_normalizado(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Banco V', 'banco', '1000.00');
        $destino = $this->conta('Banco W', 'banco', '0.00');

        foreach (['0', '0.00', '-1.00', '10.005', 'abc', '1,50', '', null, '1000000000000.00', [], true] as $invalido) {
            $this->transferir($ator, $origem, $destino, ['valor' => $invalido])->assertStatus(422)->assertJsonValidationErrors('valor');
        }
        $this->assertDatabaseCount('transferencias', 0);

        foreach (['100' => '100.00', '100.5' => '100.50', '0.01' => '0.01'] as $enviado => $esperado) {
            $resposta = $this->transferir($ator, $origem, $destino, ['valor' => (string) $enviado, 'confirmar_saldo_negativo' => true])->assertStatus(201);
            $this->assertSame($esperado, $resposta->json('data.valor'));
            $this->assertIsString($resposta->json('data.valor'));
        }
        $this->transferir($ator, $origem, $destino, ['valor' => 150.5])->assertStatus(201)->assertJsonPath('data.valor', '150.50');
    }

    public function test_data_obrigatoria_valida_nao_futura_e_retroativa_aceita(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Banco T', 'banco', '1000.00');
        $destino = $this->conta('Banco U', 'banco', '0.00');
        $amanha = Carbon::now('America/Sao_Paulo')->addDay()->toDateString();

        foreach ([$amanha, '2999-01-01', '2026-02-30', '2026-13-01', '20/09/2026', 'ontem', '', null] as $data) {
            $this->transferir($ator, $origem, $destino, ['data_transferencia' => $data])->assertStatus(422)->assertJsonValidationErrors('data_transferencia');
        }
        $this->assertDatabaseCount('transferencias', 0);

        foreach ([$this->hoje(), '2026-01-15', '1990-01-01'] as $data) {
            $this->transferir($ator, $origem, $destino, ['data_transferencia' => $data, 'valor' => '1.00'])->assertStatus(201)->assertJsonPath('data.data_transferencia', $data);
        }
    }

    public function test_futuro_e_avaliado_em_sao_paulo_sem_mudar_o_fuso_global(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Banco X', 'banco', '1000.00');
        $destino = $this->conta('Banco Y', 'banco', '0.00');

        Carbon::setTestNow(Carbon::parse('2026-09-21 01:30:00', 'UTC')); // 22:30 de 20/09 em São Paulo
        $this->transferir($ator, $origem, $destino, ['data_transferencia' => '2026-09-21'])->assertStatus(422);
        $this->transferir($ator, $origem, $destino, ['data_transferencia' => '2026-09-20'])->assertStatus(201);
        $this->assertSame('UTC', config('app.timezone'));
    }

    // ---------------- período ----------------

    public function test_periodo_fechado_bloqueia_ate_o_pastor_e_periodo_inexistente_e_aberto(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $origem = $this->conta('Banco Pf', 'banco', '1000.00');
        $destino = $this->conta('Banco Pg', 'banco', '0.00');
        $this->fecharPeriodo('2026-03', $pastor);

        foreach ([$pastor, $tesoureiro] as $ator) {
            foreach (['2026-03-01', '2026-03-31'] as $data) {
                $this->transferir($ator, $origem, $destino, ['data_transferencia' => $data])->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
            }
        }
        $this->assertDatabaseCount('transferencias', 0);

        // Meses vizinhos e mês SEM linha em periodos_financeiros seguem abertos.
        $this->transferir($tesoureiro, $origem, $destino, ['data_transferencia' => '2026-02-28', 'valor' => '1.00'])->assertStatus(201);
        $this->transferir($tesoureiro, $origem, $destino, ['data_transferencia' => '2026-04-01', 'valor' => '1.00'])->assertStatus(201);
        $this->transferir($tesoureiro, $origem, $destino, ['data_transferencia' => '2020-07-10', 'valor' => '1.00'])->assertStatus(201);
    }

    // ---------------- saldo: origem caixa ----------------

    public function test_caixa_de_origem_sem_saldo_bloqueia_mesmo_com_confirmacao_e_nada_e_gravado(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Baixo', 'caixa', '50.00');
        $banco = $this->conta('Banco Dest', 'banco', '0.00');

        $this->transferir($ator, $caixa, $banco, ['valor' => '50.01'])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->transferir($ator, $caixa, $banco, ['valor' => '50.01', 'confirmar_saldo_negativo' => true])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');

        $this->assertDatabaseCount('transferencias', 0);
        $this->assertSame('50.00', $this->saldoDe($caixa));
        $this->assertSame('0.00', $this->saldoDe($banco));
        $this->assertSame(0, AuditLog::where('modulo', 'transferencias')->count());
    }

    public function test_caixa_com_saldo_exato_transfere_e_fica_em_zero(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Exato', 'caixa', '50.00');
        $outra = $this->conta('Caixa Outro', 'caixa', '0.00');

        $this->transferir($ator, $caixa, $outra, ['valor' => '50.00'])->assertStatus(201);
        $this->assertSame('0.00', $this->saldoDe($caixa));
        $this->assertSame('50.00', $this->saldoDe($outra));
        $this->assertFalse(AuditLog::where('acao', 'created')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    // ---------------- saldo: origem banco ----------------

    public function test_banco_de_origem_que_ficaria_negativo_exige_confirmacao_explicita(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $banco = $this->conta('Banco Neg', 'banco', '30.00');
        $caixa = $this->conta('Caixa Recebe', 'caixa', '0.00');

        $this->transferir($ator, $banco, $caixa, ['valor' => '100.00'])->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->transferir($ator, $banco, $caixa, ['valor' => '100.00', 'confirmar_saldo_negativo' => false])->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->assertDatabaseCount('transferencias', 0);
        $this->assertSame('30.00', $this->saldoDe($banco));

        $this->transferir($ator, $banco, $caixa, ['valor' => '100.00', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertSame('-70.00', $this->saldoDe($banco));
        $this->assertSame('100.00', $this->saldoDe($caixa));
        $this->assertTrue(AuditLog::where('acao', 'created')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_banco_ja_negativo_exige_confirmacao_para_piorar_e_a_destino_nunca_e_checada(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $negativo = $this->conta('Banco Ja Neg', 'banco', '-100.00');
        $outro = $this->conta('Banco Outro', 'banco', '0.00');

        $this->transferir($ator, $negativo, $outro, ['valor' => '0.01'])->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');

        // Receber dinheiro nunca é bloqueado, mesmo estando negativa (a destino só ganha).
        $this->transferir($ator, $outro, $negativo, ['valor' => '10.00', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertSame('-90.00', $this->saldoDe($negativo));
        $this->assertSame('-10.00', $this->saldoDe($outro));
    }

    public function test_confirmar_saldo_negativo_desnecessario_e_inofensivo_e_deve_ser_booleano(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $origem = $this->conta('Banco Folga', 'banco', '500.00');
        $destino = $this->conta('Banco Dest2', 'banco', '0.00');

        $this->transferir($ator, $origem, $destino, ['valor' => '10.00', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertFalse(AuditLog::where('acao', 'created')->sole()->dados_novos['saldo_negativo_confirmado']);
        $this->transferir($ator, $origem, $destino, ['confirmar_saldo_negativo' => 'talvez'])->assertStatus(422)->assertJsonValidationErrors('confirmar_saldo_negativo');
    }

    public function test_saldo_considera_entradas_e_despesas_da_origem_antes_de_liberar(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Misto', 'caixa', '0.00');
        $destino = $this->conta('Banco Misto', 'banco', '0.00');
        $this->entrada($caixa, $this->categoria(), $ator, '100.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $ator, ['valor' => '60.00']);
        $this->pagar($ator, $despesa, $this->corpoPagamento($caixa))->assertOk(); // saldo do caixa = 40

        $this->transferir($ator, $caixa, $destino, ['valor' => '40.01'])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->transferir($ator, $caixa, $destino, ['valor' => '40.00'])->assertStatus(201);
        $this->assertSame('0.00', $this->saldoDe($caixa));
    }

    // ---------------- descrição, auditoria ----------------

    public function test_descricao_e_opcional_normalizada_e_limitada(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Banco Ds', 'banco', '1000.00');
        $destino = $this->conta('Banco Dt', 'banco', '0.00');

        $this->transferir($ator, $origem, $destino, ['descricao' => '   ', 'valor' => '1.00'])->assertStatus(201)->assertJsonPath('data.descricao', null);
        $this->transferir($ator, $origem, $destino, ['descricao' => '  Reforço  ', 'valor' => '1.00'])->assertStatus(201)->assertJsonPath('data.descricao', 'Reforço');
        $this->transferir($ator, $origem, $destino, ['descricao' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('descricao');
        $this->transferir($ator, $origem, $destino, ['descricao' => str_repeat('a', 255), 'valor' => '1.00'])->assertStatus(201);
    }

    public function test_criacao_e_auditada_sem_a_descricao_e_rejeicoes_nao_geram_log(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $origem = $this->conta('Banco Au', 'banco', '1000.00');
        $destino = $this->conta('Caixa Au', 'caixa', '0.00');

        $id = $this->transferir($ator, $origem, $destino, ['valor' => '250.75', 'data_transferencia' => '2026-07-05', 'descricao' => 'Descrição sigilosa'])->assertStatus(201)->json('data.id');

        $log = AuditLog::where('modulo', 'transferencias')->where('acao', 'created')->sole();
        $this->assertSame($id, $log->registro_id);
        $this->assertSame($ator->id, $log->user_id);
        $this->assertSame([
            'conta_origem_id' => $origem->id, 'conta_destino_id' => $destino->id, 'valor' => '250.75',
            'data_transferencia' => '2026-07-05', 'status' => 'confirmada', 'saldo_negativo_confirmado' => false,
        ], $log->dados_novos);
        $this->assertStringNotContainsString('Descrição sigilosa', json_encode($log->getAttributes()));

        $logs = AuditLog::count();
        $this->transferir($ator, $origem, $origem)->assertStatus(422);
        $this->transferir($ator, $destino, $origem, ['valor' => '999999.00'])->assertStatus(409);
        $this->transferir($this->como(PerfilSlug::Secretario), $origem, $destino)->assertStatus(403);
        $this->assertSame($logs, AuditLog::count());
    }

    public function test_saldo_atual_nunca_e_persistido(): void
    {
        $colunas = collect(\Illuminate\Support\Facades\DB::select('SHOW COLUMNS FROM transferencias'))->pluck('Field')->all();
        $this->assertNotContains('saldo_atual', $colunas);
        $this->assertNotContains('deleted_at', $colunas);
        $origem = $this->conta('Banco Sp', 'banco', '10.00');
        $destino = $this->conta('Banco Sq', 'banco', '0.00');
        $this->transferir($this->como(PerfilSlug::Pastor), $origem, $destino, ['valor' => '1.00'])->assertStatus(201);
        $this->assertSame('10.00', $origem->fresh()->saldo_inicial);
    }
}
