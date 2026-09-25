<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Conta;
use App\Models\Entrada;
use App\Services\CategoriaService;
use App\Services\ContaService;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaldoEUsoComEntradasTest extends TestCase
{
    use RefreshDatabase, CenarioEntradas;

    // ---------- SaldoService ----------

    public function test_saldo_individual_soma_entradas_e_subtrai_estornos_com_bcmath(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Saldo', 'banco', '1000.00');
        $categoria = $this->categoria();
        $saldos = app(SaldoService::class);

        $this->assertSame('1000.00', $saldos->saldoAtual($conta));

        $a = $this->entrada($conta, $categoria, $pastor, '0.10');
        $this->entrada($conta, $categoria, $pastor, '0.20');
        $this->assertSame('1000.30', $saldos->saldoAtual($conta)); // float daria 1000.3000000000001

        $this->entrada($conta, $categoria, $pastor, '0.10', null, ['entrada_estornada_id' => $a->id, 'motivo_estorno' => 'x']);
        $this->assertSame('1000.20', $saldos->saldoAtual($conta));
        $this->assertIsString($saldos->saldoAtual($conta));
    }

    public function test_saldo_nao_depende_da_coluna_status(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Status', 'banco', '0.00');
        $categoria = $this->categoria();
        $original = $this->entrada($conta, $categoria, $pastor, '50.00');
        $this->entrada($conta, $categoria, $pastor, '50.00', null, ['entrada_estornada_id' => $original->id, 'motivo_estorno' => 'x']);

        foreach (['confirmada', 'estornada'] as $status) {
            DB::table('entradas')->where('id', $original->id)->update(['status' => $status]);
            $this->assertSame('0.00', app(SaldoService::class)->saldoAtual($conta), "status $status");
        }
    }

    public function test_saldo_com_valores_extremos_e_negativos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ext', 'banco', '-999999999999.98');
        $this->entrada($conta, $this->categoria(), $pastor, '999999999999.99');

        $this->assertSame('0.01', app(SaldoService::class)->saldoAtual($conta));
    }

    public function test_saldo_em_lote_e_igual_ao_individual_e_inclui_contas_sem_entradas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();
        $contas = [$this->conta('L1', 'banco', '10.00'), $this->conta('L2', 'caixa', '0.00'), $this->conta('L3', 'banco', '-5.50')];
        $this->entrada($contas[0], $categoria, $pastor, '1.11');
        $this->entrada($contas[0], $categoria, $pastor, '2.22');
        $this->entrada($contas[2], $categoria, $pastor, '5.50');

        $service = app(SaldoService::class);
        $lote = $service->saldosAtuais($contas);

        $this->assertSame('13.33', $lote[$contas[0]->id]);
        $this->assertSame('0.00', $lote[$contas[1]->id]);
        $this->assertSame('0.00', $lote[$contas[2]->id]);
        foreach ($contas as $conta) {
            $this->assertSame($service->saldoAtual($conta), $lote[$conta->id]);
        }
        $this->assertSame([], $service->saldosAtuais([]));
    }

    public function test_listagem_de_contas_calcula_saldos_com_uma_unica_consulta_de_entradas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();
        foreach (range(1, 6) as $i) {
            $conta = $this->conta("Conta $i", 'banco', '1.00');
            $this->entrada($conta, $categoria, $pastor, '2.00');
        }
        $this->actingAs($pastor)->getJson('/api/v1/contas')->assertOk(); // aquece

        DB::enableQueryLog();
        $resposta = $this->actingAs($pastor)->getJson('/api/v1/contas')->assertOk();
        $consultasEntradas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `entradas`'))->count();

        $this->assertSame(1, $consultasEntradas);
        foreach ($resposta->json('data') as $linha) {
            $this->assertSame('3.00', $linha['saldo_atual']);
        }
    }

    public function test_saldo_atual_nunca_e_persistido(): void
    {
        $this->assertNotContains('saldo_atual', collect(DB::select('SHOW COLUMNS FROM contas'))->pluck('Field')->all());
        $this->assertNotContains('saldo_atual', collect(DB::select('SHOW COLUMNS FROM entradas'))->pluck('Field')->all());

        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Sem persistir', 'banco', '5.00');
        $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $this->categoria()))->assertStatus(201);

        $this->assertSame('5.00', $conta->fresh()->saldo_inicial); // o inicial não muda com lançamentos
        $this->assertSame('105.00', $this->saldoNaApi($pastor, $conta));
    }

    public function test_api_de_contas_reflete_entrada_e_estorno_e_serve_o_saldo_no_create_e_update(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();

        $conta = $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Nova', 'tipo' => 'banco', 'saldo_inicial' => '10.00'])
            ->assertStatus(201)->assertJsonPath('data.saldo_atual', '10.00');
        $id = $conta->json('data.id');

        $entradaId = $this->actingAs($pastor)->postJson('/api/v1/entradas', ['categoria_id' => $categoria->id, 'conta_id' => $id, 'valor' => '40.40', 'data_competencia' => $this->hoje()])->json('data.id');

        $this->actingAs($pastor)->putJson("/api/v1/contas/{$id}", ['nome' => 'Nova 2'])->assertOk()->assertJsonPath('data.saldo_atual', '50.40');

        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$entradaId}/estornar", ['justificativa' => 'erro'])->assertStatus(201);
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$id}", ['nome' => 'Nova 3'])->assertOk()->assertJsonPath('data.saldo_atual', '10.00');
    }

    // ---------- estaEmUso: conta ----------

    public function test_estaemuso_da_conta_considera_qualquer_entrada_inclusive_estornos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();
        $service = app(ContaService::class);

        $vazia = $this->conta('Vazia');
        $this->assertFalse($service->estaEmUso($vazia));

        $comEntrada = $this->conta('Com entrada');
        $original = $this->entrada($comEntrada, $categoria, $pastor);
        $this->assertTrue($service->estaEmUso($comEntrada));

        // Só a linha de estorno como vínculo da conta (original em outra conta): também conta como uso.
        $soEstorno = $this->conta('So estorno');
        DB::table('entradas')->insert([
            'categoria_id' => $categoria->id, 'conta_id' => $soEstorno->id, 'valor' => '1.00', 'data_competencia' => '2026-01-01',
            'status' => 'confirmada', 'entrada_estornada_id' => $original->id, 'motivo_estorno' => 'x', 'criado_por' => $pastor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue($service->estaEmUso($soEstorno));
    }

    public function test_excluir_conta_com_entradas_retorna_409_e_sem_entradas_exclui(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoria();
        $emUso = $this->conta('Em uso');
        $livre = $this->conta('Livre');
        $this->entrada($emUso, $categoria, $pastor);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$emUso->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
        $this->assertNull($emUso->fresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('modulo', 'contas')->where('acao', 'deleted')->count());

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$livre->id}")->assertOk();
        $this->assertSoftDeleted('contas', ['id' => $livre->id]);
    }

    public function test_conta_com_entradas_estornadas_tambem_nao_pode_ser_excluida_mas_pode_ser_inativada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Estornada');
        $id = $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $this->categoria()))->json('data.id');
        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$id}/estornar", ['justificativa' => 'teste'])->assertStatus(201);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])->assertOk()->assertJsonPath('data.ativa', false);
    }

    // ---------- estaEmUso: categoria ----------

    public function test_estaemuso_e_exclusao_da_categoria_com_entradas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $emUso = $this->categoria('Em uso C');
        $livre = $this->categoria('Livre C');
        $this->entrada($conta, $emUso, $pastor);

        $this->assertTrue(app(CategoriaService::class)->estaEmUso($emUso));
        $this->assertFalse(app(CategoriaService::class)->estaEmUso($livre));

        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$emUso->id}")->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_EM_USO');
        $this->assertDatabaseHas('categorias', ['id' => $emUso->id]);
        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$livre->id}")->assertOk();
        $this->assertDatabaseMissing('categorias', ['id' => $livre->id]);
    }

    public function test_categoria_com_entradas_pode_ser_inativada_e_deixa_de_aceitar_novas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria('Vai inativar');
        $this->entrada($conta, $categoria, $pastor);

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => false])->assertOk();
        $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $categoria))->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_INATIVA');
    }

    public function test_entradas_existentes_permanecem_apos_inativar_conta_e_categoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Vai inativar');
        $categoria = $this->categoria('Cat inativar');
        $this->entrada($conta, $categoria, $pastor);
        $conta->update(['ativa' => false]);
        $categoria->update(['ativa' => false]);

        $this->actingAs($pastor)->getJson('/api/v1/entradas')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('100.00', $this->saldoNaApi($pastor, $conta));
    }
}
