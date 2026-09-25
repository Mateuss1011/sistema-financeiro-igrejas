<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListagemEPermissoesDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    public function test_perfis_com_acesso_listam_e_secretario_recebe_403(): void
    {
        $this->despesaPendente($this->categoriaDespesa(), $this->como(PerfilSlug::Pastor));

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/despesas')->assertOk()->assertJsonCount(1, 'data');
        }
        $this->actingAs($this->como(PerfilSlug::Secretario))->getJson('/api/v1/despesas')->assertStatus(403);
    }

    public function test_auxiliar_ve_somente_as_proprias_no_backend_mesmo_com_filtros_e_parametros_extras(): void
    {
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $outro = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $conta = $this->conta();

        $minha = $this->despesaPendente($categoria, $auxiliar);
        $minhaPaga = $this->despesaPaga($conta, $categoria, $auxiliar);
        $this->despesaPendente($categoria, $outro);
        $this->despesaPendente($categoria, $tesoureiro);

        $ids = collect($this->actingAs($auxiliar)->getJson('/api/v1/despesas')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$minha->id, $minhaPaga->id], $ids);

        foreach (['?conta_id=' . $conta->id, '?estorno=false', '?criado_por=' . $tesoureiro->id, '?por_pagina=100&ordenar=valor', '?status=pendente', '?categoria_id=' . $categoria->id] as $query) {
            $resposta = $this->actingAs($auxiliar)->getJson('/api/v1/despesas' . $query)->assertOk();
            foreach ($resposta->json('data') as $linha) {
                $this->assertContains($linha['id'], [$minha->id, $minhaPaga->id], $query);
            }
            $this->assertLessThanOrEqual(2, $resposta->json('meta.total'), $query);
        }
    }

    public function test_meta_permissoes_reflete_as_permissoes_reais_de_cada_perfil(): void
    {
        $matriz = [
            [$this->como(PerfilSlug::Pastor), ['criar' => true, 'editar' => true, 'pagar' => true, 'cancelar' => true, 'estornar' => true, 'excluir' => true]],
            [$this->como(PerfilSlug::Tesoureiro), ['criar' => true, 'editar' => true, 'pagar' => true, 'cancelar' => true, 'estornar' => false, 'excluir' => true]],
            [$this->comExcecoes(PerfilSlug::Tesoureiro, ['despesas.estornar_paga']), ['criar' => true, 'editar' => true, 'pagar' => true, 'cancelar' => true, 'estornar' => true, 'excluir' => true]],
            [$this->como(PerfilSlug::AuxiliarFinanceiro), ['criar' => true, 'editar' => false, 'pagar' => false, 'cancelar' => false, 'estornar' => false, 'excluir' => false]],
            [$this->como(PerfilSlug::Administrador), ['criar' => false, 'editar' => false, 'pagar' => false, 'cancelar' => false, 'estornar' => false, 'excluir' => false]],
            [$this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar']), ['criar' => true, 'editar' => true, 'pagar' => true, 'cancelar' => true, 'estornar' => false, 'excluir' => true]],
            [$this->comExcecoes(PerfilSlug::Administrador, ['despesas.estornar_paga']), ['criar' => false, 'editar' => false, 'pagar' => false, 'cancelar' => false, 'estornar' => false, 'excluir' => false]],
            [$this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar', 'despesas.estornar_paga']), ['criar' => true, 'editar' => true, 'pagar' => true, 'cancelar' => true, 'estornar' => true, 'excluir' => true]],
        ];

        foreach ($matriz as [$ator, $esperado]) {
            $this->assertSame($esperado, $this->actingAs($ator)->getJson('/api/v1/despesas')->assertOk()->json('meta.permissoes'));
        }
    }

    public function test_flags_por_linha_combinam_estado_do_registro_e_permissao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $conta = $this->conta();
        $categoria = $this->categoriaDespesa();
        $pendente = $this->despesaPendente($categoria, $tesoureiro);
        $paga = $this->despesaPaga($conta, $categoria, $tesoureiro);
        $cancelada = $this->despesaCancelada($categoria, $tesoureiro);
        $estornada = $this->despesaEstornada($conta, $categoria, $tesoureiro);
        $estorno = \App\Models\Despesa::whereNotNull('despesa_estornada_id')->first();

        $flags = fn ($ator, $despesa) => collect($this->actingAs($ator)->getJson('/api/v1/despesas?por_pagina=100')->json('data'))->firstWhere('id', $despesa->id);
        $so = fn (array $linha) => array_intersect_key($linha, array_flip(['editavel', 'pagavel', 'cancelavel', 'estornavel', 'excluivel']));

        $todas = ['editavel' => true, 'pagavel' => true, 'cancelavel' => true, 'estornavel' => false, 'excluivel' => true];
        $this->assertSame($todas, $so($flags($pastor, $pendente)));
        $this->assertSame(['editavel' => false, 'pagavel' => false, 'cancelavel' => false, 'estornavel' => true, 'excluivel' => false], $so($flags($pastor, $paga)));
        foreach ([$cancelada, $estornada, $estorno] as $terminal) {
            $this->assertSame(['editavel' => false, 'pagavel' => false, 'cancelavel' => false, 'estornavel' => false, 'excluivel' => false], $so($flags($pastor, $terminal)));
        }
        // Tesoureiro sem exceção: não estorna paga.
        $this->assertFalse($flags($tesoureiro, $paga)['estornavel']);
        // Auxiliar: nenhuma ação, mesmo nas próprias.
        $propria = $this->despesaPendente($categoria, $auxiliar);
        $this->assertSame(['editavel' => false, 'pagavel' => false, 'cancelavel' => false, 'estornavel' => false, 'excluivel' => false], $so($flags($auxiliar, $propria)));
        // Tesoureiro só exclui as próprias: a do Pastor aparece sem "excluivel".
        $doPastor = $this->despesaPendente($categoria, $pastor);
        $this->assertFalse($flags($tesoureiro, $doPastor)['excluivel']);
        $this->assertTrue($flags($tesoureiro, $pendente)['excluivel']);
    }

    public function test_formato_da_listagem_sem_totais_e_sem_dados_internos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->despesaPaga($this->conta('Caixa Fm', 'caixa'), $this->categoriaDespesa('Fm'), $pastor, ['valor' => '12.50', 'data_competencia' => '2026-05-01', 'data_pagamento' => '2026-05-02', 'fornecedor_nome' => 'Ana']);

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/despesas')->assertOk();

        $resposta->assertJsonStructure([
            'data' => [['id', 'categoria' => ['id', 'nome'], 'conta' => ['id', 'nome', 'tipo'], 'categoria_id', 'conta_id', 'valor', 'data_competencia', 'data_pagamento',
                'descricao', 'fornecedor_nome', 'status', 'eh_estorno', 'despesa_estornada_id', 'motivo_estorno', 'motivo_cancelamento', 'estorno_id',
                'criado_por' => ['id', 'name'], 'pago_por' => ['id', 'name'], 'pago_em', 'created_at', 'editavel', 'pagavel', 'cancelavel', 'estornavel', 'excluivel']],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'permissoes'],
        ]);
        $this->assertIsString($resposta->json('data.0.valor'));
        $this->assertSame('2026-05-02', $resposta->json('data.0.data_pagamento'));
        foreach (['soma', 'total_valor', 'totais', 'saldo'] as $chave) {
            $this->assertArrayNotHasKey($chave, $resposta->json('meta'));
        }
        foreach (['chave_idempotencia', 'hash_payload', 'updated_at', 'atualizado_por'] as $interno) {
            $this->assertArrayNotHasKey($interno, $resposta->json('data.0'));
        }
    }

    public function test_ordenacao_padrao_e_alternativas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $a = $this->despesaPendente($categoria, $pastor, ['valor' => '1.00', 'data_competencia' => '2026-01-10']);
        $b = $this->despesaPendente($categoria, $pastor, ['valor' => '2.00', 'data_competencia' => '2026-03-10']);
        $c = $this->despesaPendente($categoria, $pastor, ['valor' => '3.00', 'data_competencia' => '2026-03-10']);
        $d = $this->despesaPendente($categoria, $pastor, ['valor' => '4.00', 'data_competencia' => '2026-02-10']);

        $ids = fn (string $query = '') => collect($this->actingAs($pastor)->getJson('/api/v1/despesas' . $query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $d->id, $a->id], $ids());
        $this->assertSame([$c->id, $b->id, $d->id, $a->id], $ids('?ordenar=-data_competencia,-id'));
        $this->assertSame([$a->id, $d->id, $b->id, $c->id], $ids('?ordenar=data_competencia'));
        $this->assertSame([$d->id, $c->id, $b->id, $a->id], $ids('?ordenar=-valor'));
        $this->assertSame([$a->id, $b->id, $c->id, $d->id], $ids('?ordenar=valor'));
    }

    public function test_filtros(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $banco = $this->conta('Banco F');
        $caixa = $this->conta('Caixa F', 'caixa', '500.00');
        $luz = $this->categoriaDespesa('Luz F');
        $agua = $this->categoriaDespesa('Agua F');

        $d1 = $this->despesaPendente($luz, $pastor, ['data_competencia' => '2026-01-05']);
        $d2 = $this->despesaPaga($caixa, $agua, $pastor, ['data_competencia' => '2026-02-05']);
        $d3 = $this->despesaCancelada($agua, $pastor, ['data_competencia' => '2026-03-05']);
        $d4 = $this->despesaPaga($banco, $luz, $pastor, ['data_competencia' => '2026-01-20', 'status' => 'estornada']);
        $estorno = $this->despesaPaga($banco, $luz, $pastor, ['data_competencia' => '2026-01-20', 'despesa_estornada_id' => $d4->id, 'motivo_estorno' => 'erro', 'pago_por' => null, 'pago_em' => null]);

        $ids = fn (string $q) => collect($this->actingAs($pastor)->getJson('/api/v1/despesas?' . $q)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$d2->id, $d3->id], $ids('data_de=2026-02-01'));
        $this->assertSame([$d1->id, $d4->id, $estorno->id], $ids('data_ate=2026-01-31'));
        $this->assertSame([$d2->id], $ids('data_de=2026-02-01&data_ate=2026-02-28'));
        $this->assertSame([$d1->id, $d4->id, $estorno->id], $ids('categoria_id=' . $luz->id));
        $this->assertSame([$d2->id], $ids('conta_id=' . $caixa->id));
        $this->assertSame([$d1->id], $ids('status=pendente'));
        $this->assertSame([$d3->id], $ids('status=cancelada'));
        $this->assertSame([$d4->id], $ids('status=estornada'));
        $this->assertSame([$d2->id, $estorno->id], $ids('status=paga'));
        $this->assertSame([$estorno->id], $ids('estorno=true'));
        $this->assertSame([$d1->id, $d2->id, $d3->id, $d4->id], $ids('estorno=false'));
        $this->assertSame([$d4->id, $estorno->id], $ids('conta_id=' . $banco->id . '&categoria_id=' . $luz->id));
    }

    public function test_filtros_invalidos_retornam_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['data_de=ontem', 'data_ate=2026-13-01', 'data_de=2026-05-01&data_ate=2026-04-01', 'status=confirmada', 'estorno=talvez', 'ordenar=senha', 'ordenar=-valor,saldo', 'por_pagina=101', 'por_pagina=0', 'categoria_id=abc', 'conta_id=0'] as $query) {
            $this->actingAs($pastor)->getJson('/api/v1/despesas?' . $query)->assertStatus(422);
        }
    }

    public function test_paginacao_com_maximo_de_100(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        for ($i = 0; $i < 5; $i++) {
            $this->despesaPendente($categoria, $pastor);
        }

        $this->actingAs($pastor)->getJson('/api/v1/despesas?por_pagina=2&page=3')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 2);
        $this->actingAs($pastor)->getJson('/api/v1/despesas?por_pagina=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_listagem_nao_tem_n_mais_1_nem_consulta_excecoes_por_linha(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar', 'despesas.estornar_paga']);
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoriaDespesa();

        $this->despesaPaga($conta, $categoria, $pastor);
        $this->actingAs($admin)->getJson('/api/v1/despesas')->assertOk(); // aquece
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/v1/despesas')->assertOk();
        $com1 = count(DB::getQueryLog());

        for ($i = 0; $i < 9; $i++) {
            $this->despesaPaga($conta, $categoria, $pastor);
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/v1/despesas')->assertOk();
        $com10 = count(DB::getQueryLog());

        $this->assertSame($com1, $com10, json_encode(array_column(DB::getQueryLog(), 'query')));
    }

    public function test_nao_existe_get_individual_de_despesa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $pastor);

        $status = $this->actingAs($pastor)->getJson("/api/v1/despesas/{$despesa->id}")->status();
        $this->assertContains($status, [404, 405]);
        $this->actingAs($pastor)->patchJson("/api/v1/despesas/{$despesa->id}", [])->assertStatus(405);
    }

    public function test_rotas_de_acao_exigem_id_numerico(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->postJson('/api/v1/despesas/abc/pagar', [])->assertStatus(404);
        $this->actingAs($pastor)->putJson('/api/v1/despesas/abc', [])->assertStatus(404);
        $this->actingAs($pastor)->deleteJson('/api/v1/despesas/abc')->assertStatus(404);
    }
}
