<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Transferencia;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListagemEIdempotenciaDeTransferenciasTest extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    // ================= listagem =================

    public function test_perfis_com_acesso_listam_e_os_demais_recebem_403(): void
    {
        $this->transferenciaDireta($this->conta('Banco A', 'banco', '10.00'), $this->conta('Banco B', 'banco', '0.00'), $this->como(PerfilSlug::Pastor), '1.00');

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/transferencias')->assertOk()->assertJsonCount(1, 'data');
        }
        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/transferencias')->assertStatus(403);
        }
    }

    public function test_meta_permissoes_reflete_as_permissoes_reais(): void
    {
        $matriz = [
            [$this->como(PerfilSlug::Pastor), true, true],
            [$this->como(PerfilSlug::Tesoureiro), true, true],
            [$this->como(PerfilSlug::Administrador), false, false],
            [$this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar']), true, false],
            [$this->comExcecoes(PerfilSlug::Administrador, ['transferencias.estornar']), false, false],
            [$this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar', 'transferencias.estornar']), true, true],
        ];

        foreach ($matriz as [$ator, $criar, $estornar]) {
            $this->actingAs($ator)->getJson('/api/v1/transferencias')->assertOk()
                ->assertJsonPath('meta.permissoes.criar', $criar)
                ->assertJsonPath('meta.permissoes.estornar', $estornar);
        }
    }

    public function test_flag_estornavel_combina_estado_e_permissao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->como(PerfilSlug::Administrador);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $confirmada = $this->transferenciaDireta($a, $b, $pastor, '1.00');
        $estornada = $this->transferenciaDireta($a, $b, $pastor, '2.00', null, ['status' => 'estornada']);
        $estorno = $this->transferenciaDireta($b, $a, $pastor, '2.00', null, ['transferencia_estornada_id' => $estornada->id, 'motivo_estorno' => 'x']);

        $flag = fn ($ator, $t) => collect($this->actingAs($ator)->getJson('/api/v1/transferencias?por_pagina=100')->json('data'))->firstWhere('id', $t->id)['estornavel'];

        $this->assertTrue($flag($pastor, $confirmada));
        $this->assertFalse($flag($pastor, $estornada));
        $this->assertFalse($flag($pastor, $estorno));
        $this->assertFalse($flag($admin, $confirmada));
    }

    public function test_formato_ordenacao_filtros_e_paginacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Caixa B', 'caixa', '0.00');
        $c = $this->conta('Banco C', 'banco', '0.00');

        $t1 = $this->transferenciaDireta($a, $b, $pastor, '10.00', '2026-01-10');
        $t2 = $this->transferenciaDireta($b, $c, $pastor, '20.00', '2026-03-10');
        $t3 = $this->transferenciaDireta($a, $c, $pastor, '30.00', '2026-03-10');
        $t4 = $this->transferenciaDireta($c, $a, $pastor, '5.00', '2026-02-10', ['transferencia_estornada_id' => $t1->id, 'motivo_estorno' => 'x']);
        $t1->update(['status' => 'estornada']);

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/transferencias')->assertOk();
        $resposta->assertJsonStructure([
            'data' => [['id', 'conta_origem' => ['id', 'nome', 'tipo'], 'conta_destino' => ['id', 'nome', 'tipo'], 'conta_origem_id', 'conta_destino_id', 'valor', 'data_transferencia', 'descricao',
                'status', 'eh_estorno', 'transferencia_estornada_id', 'motivo_estorno', 'estorno_id', 'criado_por' => ['id', 'name'], 'created_at', 'estornavel']],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'permissoes'],
        ]);
        $this->assertIsString($resposta->json('data.0.valor'));
        foreach (['chave_idempotencia', 'hash_payload', 'updated_at'] as $interno) {
            $this->assertArrayNotHasKey($interno, $resposta->json('data.0'));
        }
        foreach (['soma', 'total_valor', 'totais', 'saldo'] as $chave) {
            $this->assertArrayNotHasKey($chave, $resposta->json('meta'));
        }

        $ids = fn (string $q = '') => collect($this->actingAs($pastor)->getJson('/api/v1/transferencias' . $q)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$t3->id, $t2->id, $t4->id, $t1->id], $ids());                      // -data_transferencia,-id
        $this->assertSame([$t1->id, $t4->id, $t2->id, $t3->id], $ids('?ordenar=data_transferencia'));
        $this->assertSame([$t3->id, $t2->id, $t1->id, $t4->id], $ids('?ordenar=-valor'));
        $this->assertEqualsCanonicalizing([$t2->id, $t3->id], $ids('?data_de=2026-03-01'));
        $this->assertEqualsCanonicalizing([$t1->id, $t4->id], $ids('?data_ate=2026-02-28'));
        $this->assertEqualsCanonicalizing([$t1->id, $t3->id], $ids('?conta_origem_id=' . $a->id));
        $this->assertEqualsCanonicalizing([$t2->id, $t3->id], $ids('?conta_destino_id=' . $c->id));
        $this->assertEqualsCanonicalizing([$t1->id, $t2->id], $ids('?conta_id=' . $b->id)); // qualquer dos lados
        $this->assertEqualsCanonicalizing([$t1->id], $ids('?status=estornada'));
        $this->assertEqualsCanonicalizing([$t4->id], $ids('?estorno=true'));
        $this->assertEqualsCanonicalizing([$t1->id, $t2->id, $t3->id], $ids('?estorno=false'));

        $this->actingAs($pastor)->getJson('/api/v1/transferencias?por_pagina=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2);
        $this->actingAs($pastor)->getJson('/api/v1/transferencias?por_pagina=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_filtros_invalidos_retornam_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['data_de=ontem', 'data_ate=2026-13-01', 'data_de=2026-05-01&data_ate=2026-04-01', 'status=cancelada', 'estorno=talvez', 'ordenar=senha', 'ordenar=-valor,saldo', 'por_pagina=101', 'por_pagina=0', 'conta_id=abc', 'conta_origem_id=0'] as $query) {
            $this->actingAs($pastor)->getJson('/api/v1/transferencias?' . $query)->assertStatus(422);
        }
    }

    public function test_listagem_sem_n_mais_1(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['transferencias.operar', 'transferencias.estornar']);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $this->transferenciaDireta($a, $b, $pastor, '1.00');
        $this->actingAs($admin)->getJson('/api/v1/transferencias')->assertOk(); // aquece
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/v1/transferencias')->assertOk();
        $com1 = count(DB::getQueryLog());

        for ($i = 0; $i < 9; $i++) {
            $this->transferenciaDireta($a, $b, $pastor, '1.00');
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/v1/transferencias')->assertOk();
        $this->assertSame($com1, count(DB::getQueryLog()), json_encode(array_column(DB::getQueryLog(), 'query')));
    }

    public function test_nao_existem_put_delete_patch_nem_get_individual(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $t = $this->transferenciaDireta($this->conta('Banco A', 'banco', '10.00'), $this->conta('Banco B', 'banco', '0.00'), $pastor, '1.00');

        foreach (['put', 'patch', 'delete', 'get'] as $metodo) {
            $status = $this->actingAs($pastor)->{$metodo . 'Json'}("/api/v1/transferencias/{$t->id}", ['valor' => '9.00'])->status();
            $this->assertContains($status, [404, 405], "$metodo deveria ser inexistente");
        }
        $this->assertSame('1.00', $t->fresh()->valor);
        $this->assertNotNull(Transferencia::find($t->id));
    }

    // ================= idempotência =================

    public function test_mesma_chave_e_mesmo_payload_devolve_a_transferencia_sem_duplicar_nem_debitar_de_novo(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $primeira = $this->transferir($ator, $a, $b, ['descricao' => 'Reforço'], 'chave-1')->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $segunda = $this->transferir($ator, $a, $b, ['descricao' => 'Reforço'], 'chave-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->transferir($ator, $a, $b, ['descricao' => 'Reforço'], 'chave-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($primeira->json('data.id'), $segunda->json('data.id'));
        $this->assertSame($primeira->json('data'), $segunda->json('data'));
        $this->assertDatabaseCount('transferencias', 1);
        $this->assertSame('900.00', $this->saldoDe($a));
        $this->assertSame(1, AuditLog::where('modulo', 'transferencias')->count());
        $t = Transferencia::sole();
        $this->assertSame('chave-1', $t->chave_idempotencia);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t->hash_payload);
    }

    public function test_mesma_chave_com_payload_diferente_retorna_409(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $c = $this->conta('Banco C', 'banco', '0.00');
        $this->transferir($ator, $a, $b, ['data_transferencia' => '2026-05-01', 'descricao' => 'Base'], 'k')->assertStatus(201);

        foreach ([['valor' => '100.01'], ['conta_destino_id' => $c->id], ['conta_origem_id' => $c->id], ['data_transferencia' => '2026-05-02'], ['descricao' => 'Outra'], ['descricao' => null]] as $variacao) {
            $this->transferir($ator, $a, $b, array_merge(['data_transferencia' => '2026-05-01', 'descricao' => 'Base'], $variacao), 'k')
                ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUTILIZADA');
        }
        $this->assertDatabaseCount('transferencias', 1);
        $this->assertSame('900.00', $this->saldoDe($a));
    }

    public function test_replay_compara_valores_normalizados(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $this->transferir($ator, $a, $b, ['valor' => '100.5', 'descricao' => '  Luz  '], 'norm')->assertStatus(201);
        $this->transferir($ator, $a, $b, ['valor' => '100.50', 'descricao' => 'Luz'], 'norm')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->transferir($ator, $a, $b, ['valor' => 100.5, 'descricao' => 'Luz'], 'norm')->assertStatus(200);
        $this->transferir($ator, $a, $b, ['valor' => '100.500', 'descricao' => 'Luz'], 'norm')->assertStatus(422);
        $this->assertDatabaseCount('transferencias', 1);
    }

    public function test_replay_apos_estorno_devolve_o_estado_atual_sem_nova_transferencia(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $id = $this->transferir($pastor, $a, $b, [], 'apos')->assertStatus(201)->json('data.id');
        $this->estornarTransferencia($pastor, $id)->assertStatus(201);

        $replay = $this->transferir($pastor, $a, $b, [], 'apos')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($id, $replay->json('data.id'));
        $this->assertSame('estornada', $replay->json('data.status'));
        $this->assertDatabaseCount('transferencias', 2);
        $this->assertSame('1000.00', $this->saldoDe($a));
    }

    public function test_replay_devolve_a_original_mesmo_com_contas_inativadas_depois(): void
    {
        $ator = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $id = $this->transferir($ator, $a, $b, [], 'inativa')->assertStatus(201)->json('data.id');
        $b->update(['ativa' => false]);

        $this->transferir($ator, $a, $b, [], 'inativa')->assertStatus(200)->assertJsonPath('data.id', $id);
    }

    public function test_chaves_sao_independentes_por_usuario_e_sem_chave_cria_sempre(): void
    {
        $x = $this->como(PerfilSlug::Tesoureiro);
        $y = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        $this->transferir($x, $a, $b, ['valor' => '1.00'], 'mesma')->assertStatus(201);
        $this->transferir($y, $a, $b, ['valor' => '1.00'], 'mesma')->assertStatus(201);
        $this->transferir($x, $a, $b, ['valor' => '1.00'], 'outra')->assertStatus(201);
        $this->transferir($x, $a, $b, ['valor' => '1.00'])->assertStatus(201);
        $this->transferir($x, $a, $b, ['valor' => '1.00'])->assertStatus(201);
        $this->assertDatabaseCount('transferencias', 5);
        $this->assertSame(2, Transferencia::whereNull('chave_idempotencia')->whereNull('hash_payload')->count());
    }

    public function test_chave_invalida_retorna_422_e_o_header_e_a_unica_fonte(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');

        foreach ([str_repeat('a', 65), 'com espaço', 'acentuação'] as $chave) {
            $this->transferir($ator, $a, $b, [], $chave)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        }
        $this->transferir($ator, $a, $b, ['valor' => '1.00'], str_repeat('a', 64))->assertStatus(201);
        $this->transferir($ator, $a, $b, ['valor' => '1.00'], '550e8400-e29b-41d4-a716-446655440000')->assertStatus(201);
        $this->transferir($ator, $a, $b, ['valor' => '1.00', 'idempotency_key' => 'no-corpo', 'chave_idempotencia' => 'no-corpo'])->assertStatus(201);
        $this->assertSame(1, Transferencia::whereNull('chave_idempotencia')->count());
    }

    public function test_replay_nao_e_afetado_por_periodo_fechado_posterior_mas_nova_chave_e(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $id = $this->transferir($pastor, $a, $b, ['data_transferencia' => '2026-03-10'], 'per')->assertStatus(201)->json('data.id');
        $this->fecharPeriodo('2026-03', $pastor);

        $this->transferir($pastor, $a, $b, ['data_transferencia' => '2026-03-10'], 'per')->assertStatus(200)->assertJsonPath('data.id', $id);
        $this->transferir($pastor, $a, $b, ['data_transferencia' => '2026-03-10'], 'nova')->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
    }

    public function test_unique_e_check_do_banco_protegem_fora_da_aplicacao(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Banco A', 'banco', '1000.00');
        $b = $this->conta('Banco B', 'banco', '0.00');
        $hash = str_repeat('a', 64);
        $this->transferenciaDireta($a, $b, $ator, '1.00', null, ['chave_idempotencia' => 'db', 'hash_payload' => $hash]);

        try {
            $this->transferenciaDireta($a, $b, $ator, '1.00', null, ['chave_idempotencia' => 'db', 'hash_payload' => $hash]);
            $this->fail('UNIQUE deveria barrar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('transferencias_idempotencia_unica', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_transferencias_idempotencia');
        $this->transferenciaDireta($a, $b, $ator, '1.00', null, ['chave_idempotencia' => 'sem-hash']);
    }
}
