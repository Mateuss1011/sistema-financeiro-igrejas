<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\AjusteSaldo;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Autorização de ajustes de saldo para o Administrador: cria SOMENTE com a exceção `ajustes.operar`. */
class AjustesDeSaldoDoAdministradorTest extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    public function test_admin_sem_excecao_consulta_mas_recebe_403_ao_criar(): void
    {
        $admin = $this->como(PerfilSlug::Administrador);
        $conta = $this->conta('Banco A1', 'banco', '100.00');

        $this->actingAs($admin)->getJson('/api/v1/ajustes')->assertOk()->assertJsonPath('meta.permissoes.criar', false);
        $this->ajustar($admin, $conta)->assertStatus(403);
        $this->assertDatabaseCount('ajustes_saldo', 0);
        $this->assertSame('100.00', $this->saldoDe($conta));
    }

    public function test_admin_com_excecao_cria_ajuste_valido_e_o_saldo_muda_pelo_sentido(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']);
        $conta = $this->conta('Banco A2', 'banco', '100.00');

        $this->actingAs($admin)->getJson('/api/v1/ajustes')->assertOk()->assertJsonPath('meta.permissoes.criar', true);

        $id = $this->ajustar($admin, $conta, ['valor' => '25.50', 'sentido' => 'credito'])->assertStatus(201)->json('data.id');
        $this->assertSame('125.50', $this->saldoDe($conta));

        $this->ajustar($admin, $conta, ['valor' => '5.25', 'sentido' => 'debito'])->assertStatus(201);
        $this->assertSame('120.25', $this->saldoDe($conta));

        $this->assertSame($admin->id, AjusteSaldo::findOrFail($id)->criado_por);
        $this->assertDatabaseCount('ajustes_saldo', 2);
    }

    public function test_admin_com_excecao_gera_auditoria_com_o_admin_como_autor(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']);
        $conta = $this->conta('Banco A3', 'banco', '100.00');

        $id = $this->ajustar($admin, $conta, ['valor' => '7.00', 'sentido' => 'debito', 'justificativa' => 'Tarifa não lançada'])->assertStatus(201)->json('data.id');

        $log = AuditLog::where('modulo', 'ajustes_saldo')->where('acao', 'created')->sole();
        $this->assertSame($id, $log->registro_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Tarifa não lançada', $log->justificativa);
        $this->assertSame('7.00', $log->dados_novos['valor']);
        $this->assertSame('debito', $log->dados_novos['sentido']);
    }

    public function test_admin_com_excecao_respeita_periodo_fechado_e_validacoes(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']);
        $conta = $this->conta('Banco A4', 'banco', '100.00');
        $this->fecharPeriodo('2026-03', $this->como(PerfilSlug::Pastor));

        $this->ajustar($admin, $conta, ['data_ajuste' => '2026-03-15'])->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->ajustar($admin, $conta, ['data_ajuste' => '2999-01-01'])->assertStatus(422);
        $this->ajustar($admin, $conta, ['valor' => '1.005'])->assertStatus(422);
        $this->ajustar($admin, $conta, ['valor' => '0'])->assertStatus(422);
        $this->ajustar($admin, $conta, ['justificativa' => 'ab'])->assertStatus(422);
        $this->ajustar($admin, $conta, ['justificativa' => str_repeat('x', 501)])->assertStatus(422);
        $this->ajustar($admin, $conta, ['data_ajuste' => null])->assertStatus(422);
        $this->ajustar($admin, $conta, ['conta_id' => 999999])->assertStatus(422);

        $inativa = $this->conta('Banco A4 Inativa', 'banco', '10.00');
        $inativa->update(['ativa' => false]);
        $this->ajustar($admin, $inativa)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');

        $this->assertDatabaseCount('ajustes_saldo', 0);
        $this->ajustar($admin, $conta, ['data_ajuste' => '2026-04-15', 'valor' => '1.00'])->assertStatus(201);
    }

    public function test_admin_com_excecao_respeita_caixa_insuficiente(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']);
        $caixa = $this->conta('Caixa A5', 'caixa', '10.00');

        $this->ajustar($admin, $caixa, ['valor' => '10.01', 'sentido' => 'debito'])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->ajustar($admin, $caixa, ['valor' => '10.01', 'sentido' => 'debito', 'confirmar_saldo_negativo' => true])->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->assertDatabaseCount('ajustes_saldo', 0);

        $this->ajustar($admin, $caixa, ['valor' => '10.00', 'sentido' => 'debito'])->assertStatus(201);
        $this->assertSame('0.00', $this->saldoDe($caixa));
    }

    public function test_admin_com_excecao_respeita_confirmacao_de_banco_negativo(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']);
        $banco = $this->conta('Banco A6', 'banco', '10.00');

        $this->ajustar($admin, $banco, ['valor' => '30.00', 'sentido' => 'debito'])->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->assertDatabaseCount('ajustes_saldo', 0);

        $this->ajustar($admin, $banco, ['valor' => '30.00', 'sentido' => 'debito', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertSame('-20.00', $this->saldoDe($banco));
        $this->assertTrue(AuditLog::where('modulo', 'ajustes_saldo')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_admin_com_excecao_respeita_idempotencia(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['ajustes.operar']);
        $conta = $this->conta('Banco A7', 'banco', '100.00');

        $primeiro = $this->ajustar($admin, $conta, ['valor' => '10.00'], 'chave-admin-1')->assertStatus(201);
        $replay = $this->ajustar($admin, $conta, ['valor' => '10.00'], 'chave-admin-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($primeiro->json('data.id'), $replay->json('data.id'));

        $this->ajustar($admin, $conta, ['valor' => '11.00'], 'chave-admin-1')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUTILIZADA');

        $this->assertDatabaseCount('ajustes_saldo', 1);
        $this->assertSame('110.00', $this->saldoDe($conta));
        $this->assertSame(1, AuditLog::where('modulo', 'ajustes_saldo')->where('acao', 'created')->count());
    }

    public function test_revogar_a_excecao_volta_a_dar_403_e_conceder_de_novo_libera(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->como(PerfilSlug::Administrador);
        $conta = $this->conta('Banco A8', 'banco', '100.00');

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'ajustes.operar'])->assertStatus(201);
        $this->ajustar($admin->refresh(), $conta, ['valor' => '1.00'])->assertStatus(201);

        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao/ajustes.operar")->assertOk();
        $this->ajustar($admin->refresh(), $conta)->assertStatus(403);
        $this->actingAs($admin->refresh())->getJson('/api/v1/ajustes')->assertOk()->assertJsonPath('meta.permissoes.criar', false);
        $this->assertDatabaseCount('ajustes_saldo', 1);
    }

    public function test_outras_excecoes_nao_liberam_ajuste_para_o_admin(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar', 'transferencias.estornar', 'despesas.operar']);

        $this->ajustar($admin, $this->conta('Banco A9', 'banco', '100.00'))->assertStatus(403);
        $this->assertDatabaseCount('ajustes_saldo', 0);
    }

    public function test_pastor_e_tesoureiro_continuam_criando_sem_excecao(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Tesoureiro] as $perfil) {
            $conta = $this->conta("Banco {$perfil->value}", 'banco', '100.00');
            $ator = $this->como($perfil);

            $this->ajustar($ator, $conta, ['valor' => '10.00'])->assertStatus(201);
            $this->assertSame('110.00', $this->saldoDe($conta));
            $this->actingAs($ator)->getJson('/api/v1/ajustes')->assertOk()->assertJsonPath('meta.permissoes.criar', true);
        }
    }

    public function test_auxiliar_e_secretario_continuam_com_403_mesmo_com_a_excecao_no_banco(): void
    {
        $conta = $this->conta('Banco A10', 'banco', '100.00');

        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $ator = $this->comExcecoes($perfil, ['ajustes.operar']);

            $this->ajustar($ator, $conta)->assertStatus(403);
            $this->actingAs($ator)->getJson('/api/v1/ajustes')->assertStatus(403);
        }
        $this->assertDatabaseCount('ajustes_saldo', 0);
    }
}
