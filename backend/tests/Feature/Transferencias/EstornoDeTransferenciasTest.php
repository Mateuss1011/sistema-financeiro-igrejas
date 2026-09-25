<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Transferencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstornoDeTransferenciasTest extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    public function test_estorno_cria_transferencia_inversa_vinculada_com_a_mesma_data_e_restaura_os_saldos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Caixa B', 'caixa', '0.00');
        $original = $this->transferenciaDireta($a, $b, $tesoureiro, '150.25', '2026-04-10', ['descricao' => 'Reforço sigiloso']);
        $this->assertSame('849.75', $this->saldoDe($a));
        $this->assertSame('150.25', $this->saldoDe($b));

        $resposta = $this->estornarTransferencia($pastor, $original, ['justificativa' => 'Lançada por engano'])
            ->assertStatus(201)
            ->assertJsonPath('data.eh_estorno', true)
            ->assertJsonPath('data.transferencia_estornada_id', $original->id)
            ->assertJsonPath('data.conta_origem.id', $b->id)      // origem e destino TROCADOS
            ->assertJsonPath('data.conta_destino.id', $a->id)
            ->assertJsonPath('data.valor', '150.25')
            ->assertJsonPath('data.data_transferencia', '2026-04-10') // MESMA data da original
            ->assertJsonPath('data.status', 'confirmada')
            ->assertJsonPath('data.motivo_estorno', 'Lançada por engano')
            ->assertJsonPath('data.descricao', null)                  // nada copiado além do necessário
            ->assertJsonPath('data.criado_por.id', $pastor->id)
            ->assertJsonPath('data.estornavel', false);

        $estorno = Transferencia::findOrFail($resposta->json('data.id'));
        $this->assertSame($original->id, $estorno->transferencia_estornada_id);
        $original->refresh();
        $this->assertSame('estornada', $original->status->value);
        $this->assertNull($original->transferencia_estornada_id); // o vínculo nunca é invertido
        $this->assertSame($a->id, $original->conta_origem_id);    // a original permanece intacta
        $this->assertSame('150.25', $original->valor);
        $this->assertDatabaseCount('transferencias', 2);

        $this->assertSame('1000.00', $this->saldoDe($a));
        $this->assertSame('0.00', $this->saldoDe($b));
        $this->assertSame('1000.00', $this->saldoNaApi($pastor, $a));

        $linha = collect($this->actingAs($pastor)->getJson('/api/v1/transferencias')->json('data'))->firstWhere('id', $original->id);
        $this->assertSame($estorno->id, $linha['estorno_id']);
        $this->assertFalse($linha['estornavel']);
        $this->assertSame('estornada', $linha['status']);
    }

    public function test_justificativa_do_estorno_entre_3_e_500_caracteres(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->transferenciaDireta($this->conta('Banco A', 'banco', '1000.00'), $this->conta('Banco B', 'banco', '0.00'), $pastor);

        foreach ([[], ['justificativa' => ''], ['justificativa' => '   '], ['justificativa' => 'ab'], ['justificativa' => str_repeat('x', 501)]] as $corpo) {
            $this->estornarTransferencia($pastor, $original, $corpo)->assertStatus(422)->assertJsonValidationErrors('justificativa');
        }
        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('transferencias', 1);
        $this->estornarTransferencia($pastor, $original, ['justificativa' => 'abc'])->assertStatus(201);
    }

    public function test_segundo_estorno_estorno_de_estorno_e_inexistente_sao_bloqueados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->transferenciaDireta($this->conta('Banco A', 'banco', '1000.00'), $this->conta('Banco B', 'banco', '0.00'), $pastor);

        $estornoId = $this->estornarTransferencia($pastor, $original)->assertStatus(201)->json('data.id');
        $this->estornarTransferencia($pastor, $original)->assertStatus(409)->assertJsonPath('code', 'TRANSFERENCIA_JA_ESTORNADA');
        $this->estornarTransferencia($pastor, $estornoId)->assertStatus(409)->assertJsonPath('code', 'TRANSFERENCIA_NAO_ESTORNAVEL');
        $this->estornarTransferencia($pastor, 99999)->assertStatus(404);
        $this->actingAs($pastor)->postJson('/api/v1/transferencias/abc/estornar', ['justificativa' => 'teste'])->assertStatus(404);

        $this->assertSame(1, Transferencia::where('transferencia_estornada_id', $original->id)->count());
        $this->assertSame(1, AuditLog::where('acao', 'reversed')->count());
    }

    public function test_nao_existe_ciclo_de_estornos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->transferenciaDireta($this->conta('Banco A', 'banco', '1000.00'), $this->conta('Banco B', 'banco', '500.00'), $pastor);
        $estorno = Transferencia::findOrFail($this->estornarTransferencia($pastor, $original)->json('data.id'));

        // Nem a original (já estornada) nem o estorno podem ser estornados de novo.
        $this->estornarTransferencia($pastor, $original)->assertStatus(409);
        $this->estornarTransferencia($pastor, $estorno)->assertStatus(409);
        $this->assertDatabaseCount('transferencias', 2);
    }

    public function test_permissoes_do_estorno(): void
    {
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '500.00');
        $criador = $this->como(PerfilSlug::Pastor);
        $nova = fn () => $this->transferenciaDireta($a, $b, $criador, '10.00');

        $bloqueados = [
            $this->como(PerfilSlug::AuxiliarFinanceiro),
            $this->como(PerfilSlug::Secretario),
            $this->como(PerfilSlug::Administrador),
            $this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar']),      // só operar: não estorna
            $this->comExcecoes(PerfilSlug::Administrador, ['transferencias.estornar']),    // só estornar: não estorna
        ];
        foreach ($bloqueados as $ator) {
            $t = $nova();
            $this->estornarTransferencia($ator, $t)->assertStatus(403);
            $this->assertSame('confirmada', $t->fresh()->status->value);
        }

        foreach ([$this->como(PerfilSlug::Pastor), $this->como(PerfilSlug::Tesoureiro), $this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar', 'transferencias.estornar'])] as $ator) {
            $this->estornarTransferencia($ator, $nova())->assertStatus(201);
        }
    }

    public function test_concessao_e_revogacao_das_excecoes_de_transferencia_tem_efeito_imediato(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->como(PerfilSlug::Administrador);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $this->transferir($admin, $a, $b)->assertStatus(403);
        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'transferencias.operar'])->assertStatus(201);
        $id = $this->transferir($admin->refresh(), $a, $b, ['valor' => '10.00'])->assertStatus(201)->json('data.id');
        $this->estornarTransferencia($admin, $id)->assertStatus(403);
        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'transferencias.estornar'])->assertStatus(201);
        $this->estornarTransferencia($admin->refresh(), $id)->assertStatus(201);
        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao/transferencias.operar")->assertOk();
        $this->transferir($admin->refresh(), $a, $b)->assertStatus(403);
    }

    public function test_periodo_fechado_da_data_original_bloqueia_ate_o_pastor(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $original = $this->transferenciaDireta($this->conta('Banco A', 'banco', '1000.00'), $this->conta('Banco B', 'banco', '0.00'), $pastor, '10.00', '2026-03-10');
        $this->fecharPeriodo('2026-03', $pastor);

        foreach ([$pastor, $tesoureiro] as $ator) {
            $this->estornarTransferencia($ator, $original)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        }
        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('transferencias', 1);
    }

    public function test_estorno_retroativo_usa_a_data_da_original_mesmo_com_periodo_aberto_antigo(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->transferenciaDireta($this->conta('Banco A', 'banco', '1000.00'), $this->conta('Banco B', 'banco', '0.00'), $pastor, '10.00', '2022-02-15');

        $this->estornarTransferencia($pastor, $original)->assertStatus(201)->assertJsonPath('data.data_transferencia', '2022-02-15');
    }

    public function test_conta_inativa_de_qualquer_lado_bloqueia_o_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        foreach ([$a, $b] as $inativar) {
            $original = $this->transferenciaDireta($a, $b, $pastor, '10.00');
            $inativar->update(['ativa' => false]);
            $this->estornarTransferencia($pastor, $original)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
            $this->assertSame('confirmada', $original->fresh()->status->value);
            $inativar->update(['ativa' => true]);
        }
    }

    public function test_estorno_que_retira_de_caixa_sem_saldo_e_bloqueado_mesmo_com_confirmacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $banco = $this->conta('Banco A', 'banco', '1000.00');
        $caixa = $this->conta('Caixa B', 'caixa', '0.00');
        $original = $this->transferenciaDireta($banco, $caixa, $pastor, '100.00');
        // O caixa gasta o dinheiro recebido: uma despesa paga de 100.
        $this->pagar($pastor, $this->despesaPendente($this->categoriaDespesa(), $pastor, ['valor' => '100.00']), $this->corpoPagamento($caixa))->assertOk();
        $this->assertSame('0.00', $this->saldoDe($caixa));

        $this->estornarTransferencia($pastor, $original)->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->estornarTransferencia($pastor, $original, ['justificativa' => 'teste', 'confirmar_saldo_negativo' => true])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('transferencias', 1);
        $this->assertSame(0, AuditLog::where('acao', 'reversed')->count());
    }

    public function test_estorno_em_banco_que_ficaria_negativo_pede_confirmacao_e_com_ela_conclui(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $caixa = $this->conta('Caixa A', 'caixa', '500.00');
        $banco = $this->conta('Banco B', 'banco', '0.00');
        $original = $this->transferenciaDireta($caixa, $banco, $pastor, '100.00'); // banco = 100
        $this->pagar($pastor, $this->despesaPendente($this->categoriaDespesa(), $pastor, ['valor' => '150.00']), $this->corpoPagamento($banco, ['confirmar_saldo_negativo' => true]))->assertOk(); // banco = -50

        $this->estornarTransferencia($pastor, $original)->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->assertSame('confirmada', $original->fresh()->status->value);

        $this->estornarTransferencia($pastor, $original, ['justificativa' => 'Confirmado', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertSame('-150.00', $this->saldoDe($banco));
        $this->assertSame('500.00', $this->saldoDe($caixa));
        $this->assertTrue(AuditLog::where('acao', 'reversed')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_estorno_e_auditado_e_rejeicoes_nao_geram_log_nem_efeito_parcial(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $original = $this->transferenciaDireta($a, $b, $pastor, '77.70', '2026-02-02', ['descricao' => 'Descrição sigilosa']);

        $logs = AuditLog::count();
        $this->estornarTransferencia($pastor, $original, [])->assertStatus(422);
        $this->estornarTransferencia($this->como(PerfilSlug::Secretario), $original)->assertStatus(403);
        $this->assertSame($logs, AuditLog::count());
        $this->assertSame('confirmada', $original->fresh()->status->value);

        $estornoId = $this->estornarTransferencia($pastor, $original, ['justificativa' => 'Valor errado'])->json('data.id');

        $log = AuditLog::where('modulo', 'transferencias')->where('acao', 'reversed')->sole();
        $this->assertSame($original->id, $log->registro_id);
        $this->assertSame($pastor->id, $log->user_id);
        $this->assertSame('Valor errado', $log->justificativa);
        $this->assertSame(['status' => 'confirmada'], $log->dados_anteriores);
        $this->assertSame([
            'conta_origem_id' => $a->id, 'conta_destino_id' => $b->id, 'valor' => '77.70', 'data_transferencia' => '2026-02-02',
            'status' => 'estornada', 'estorno_id' => $estornoId, 'saldo_negativo_confirmado' => false,
        ], $log->dados_novos);
        $this->assertStringNotContainsString('Descrição sigilosa', json_encode($log->getAttributes()));
    }
}
