<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListagemEPermissoesDeEntradasTest extends TestCase
{
    use RefreshDatabase, CenarioEntradas;

    public function test_perfis_com_acesso_listam_e_secretario_recebe_403(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->entrada($this->conta(), $this->categoria(), $pastor);

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $this->actingAs($this->como($perfil))->getJson('/api/v1/entradas')->assertOk()->assertJsonCount(1, 'data');
        }

        $this->actingAs($this->como(PerfilSlug::Secretario))->getJson('/api/v1/entradas')->assertStatus(403);
    }

    public function test_auxiliar_ve_somente_as_proprias_no_backend_mesmo_com_filtros(): void
    {
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $outroAuxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        $minha = $this->entrada($conta, $categoria, $auxiliar, '10.00');
        $this->entrada($conta, $categoria, $outroAuxiliar, '20.00');
        $this->entrada($conta, $categoria, $tesoureiro, '30.00');

        $resposta = $this->actingAs($auxiliar)->getJson('/api/v1/entradas')->assertOk();
        $this->assertSame([$minha->id], collect($resposta->json('data'))->pluck('id')->all());
        $this->assertSame(1, $resposta->json('meta.total'));

        // Filtros e parâmetros extras não furam a restrição.
        foreach (['?conta_id=' . $conta->id, '?estorno=false', '?criado_por=' . $tesoureiro->id, '?por_pagina=100&ordenar=valor'] as $query) {
            $ids = collect($this->actingAs($auxiliar)->getJson('/api/v1/entradas' . $query)->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$minha->id], $ids, $query);
        }
    }

    public function test_auxiliar_cria_e_ve_a_propria_entrada_criada(): void
    {
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $id = $this->actingAs($auxiliar)->postJson('/api/v1/entradas', $this->payload($this->conta(), $this->categoria()))->assertStatus(201)->json('data.id');

        $this->assertSame([$id], collect($this->actingAs($auxiliar)->getJson('/api/v1/entradas')->json('data'))->pluck('id')->all());
    }

    public function test_meta_permissoes_reflete_as_permissoes_reais_de_cada_perfil(): void
    {
        $esperado = [
            [$this->como(PerfilSlug::Pastor), true, true],
            [$this->como(PerfilSlug::Tesoureiro), true, true],
            [$this->como(PerfilSlug::AuxiliarFinanceiro), true, false],
            [$this->como(PerfilSlug::Administrador), false, false],
            [$this->administradorOperador(), true, true],
        ];

        foreach ($esperado as [$ator, $criar, $estornar]) {
            $this->actingAs($ator)->getJson('/api/v1/entradas')
                ->assertOk()
                ->assertJsonPath('meta.permissoes.criar', $criar)
                ->assertJsonPath('meta.permissoes.estornar', $estornar);
        }
    }

    public function test_formato_da_listagem_sem_totais_no_meta(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->entrada($this->conta('Caixa Z', 'caixa'), $this->categoria('Oferta Z'), $pastor, '12.50', '2026-05-01', ['contribuinte_nome' => 'Ana']);

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/entradas')->assertOk();

        $resposta->assertJsonStructure([
            'data' => [['id', 'categoria' => ['id', 'nome'], 'conta' => ['id', 'nome', 'tipo'], 'valor', 'data_competencia', 'descricao', 'contribuinte_nome', 'status', 'eh_estorno', 'entrada_estornada_id', 'motivo_estorno', 'estorno_id', 'estornavel', 'criado_por' => ['id', 'name'], 'created_at']],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'permissoes'],
        ]);
        $this->assertIsString($resposta->json('data.0.valor'));
        $this->assertSame('2026-05-01', $resposta->json('data.0.data_competencia'));
        $this->assertEqualsCanonicalizing(['criar', 'estornar'], array_keys($resposta->json('meta.permissoes')));
        foreach (['soma', 'total_valor', 'totais', 'saldo'] as $chave) {
            $this->assertArrayNotHasKey($chave, $resposta->json('meta'));
        }
        // Não vaza dados internos.
        $this->assertArrayNotHasKey('chave_idempotencia', $resposta->json('data.0'));
        $this->assertArrayNotHasKey('updated_at', $resposta->json('data.0'));
    }

    public function test_ordenacao_padrao_e_por_data_de_competencia_decrescente_e_id_decrescente(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $a = $this->entrada($conta, $categoria, $pastor, '1.00', '2026-01-10');
        $b = $this->entrada($conta, $categoria, $pastor, '2.00', '2026-03-10');
        $c = $this->entrada($conta, $categoria, $pastor, '3.00', '2026-03-10');
        $d = $this->entrada($conta, $categoria, $pastor, '4.00', '2026-02-10');

        $ids = fn (string $query = '') => collect($this->actingAs($pastor)->getJson('/api/v1/entradas' . $query)->assertOk()->json('data'))->pluck('id')->all();

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
        $caixa = $this->conta('Caixa F', 'caixa');
        $dizimo = $this->categoria('Dízimo F');
        $oferta = $this->categoria('Oferta F');

        $e1 = $this->entrada($banco, $dizimo, $pastor, '10.00', '2026-01-05');
        $e2 = $this->entrada($caixa, $oferta, $pastor, '20.00', '2026-02-05');
        $e3 = $this->entrada($banco, $oferta, $pastor, '30.00', '2026-03-05');
        $estorno = $this->entrada($banco, $dizimo, $pastor, '10.00', '2026-01-05', ['entrada_estornada_id' => $e1->id, 'motivo_estorno' => 'erro']);
        $e1->update(['status' => 'estornada']);

        $ids = fn (string $query) => collect($this->actingAs($pastor)->getJson('/api/v1/entradas?' . $query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$e2->id, $e3->id], $ids('data_de=2026-02-01'));
        $this->assertSame([$e1->id, $e2->id, $estorno->id], $ids('data_ate=2026-02-28'));
        $this->assertSame([$e2->id], $ids('data_de=2026-02-01&data_ate=2026-02-28'));
        $this->assertSame([$e1->id, $estorno->id], $ids('categoria_id=' . $dizimo->id));
        $this->assertSame([$e2->id], $ids('conta_id=' . $caixa->id));
        $this->assertSame([$e1->id], $ids('status=estornada'));
        $this->assertSame([$e2->id, $e3->id, $estorno->id], $ids('status=confirmada'));
        $this->assertSame([$estorno->id], $ids('estorno=true'));
        $this->assertSame([$e1->id, $e2->id, $e3->id], $ids('estorno=false'));
        $this->assertSame([$e3->id], $ids('conta_id=' . $banco->id . '&categoria_id=' . $oferta->id));
    }

    public function test_filtros_invalidos_retornam_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['data_de=ontem', 'data_ate=2026-13-01', 'data_de=2026-05-01&data_ate=2026-04-01', 'status=cancelada', 'estorno=talvez', 'ordenar=senha', 'ordenar=-valor,saldo', 'por_pagina=101', 'por_pagina=0', 'categoria_id=abc'] as $query) {
            $this->actingAs($pastor)->getJson('/api/v1/entradas?' . $query)->assertStatus(422);
        }
    }

    public function test_paginacao_com_maximo_de_100(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        for ($i = 0; $i < 5; $i++) {
            $this->entrada($conta, $categoria, $pastor);
        }

        $pagina = $this->actingAs($pastor)->getJson('/api/v1/entradas?por_pagina=2&page=3')->assertOk();
        $pagina->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 2);

        $this->actingAs($pastor)->getJson('/api/v1/entradas?por_pagina=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_listagem_inclui_conta_e_usuario_removidos_logicamente_sem_erro(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $autor = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Conta a Inativar');
        $this->entrada($conta, $this->categoria(), $autor);
        $autor->delete();

        $this->actingAs($pastor)->getJson('/api/v1/entradas')->assertOk()
            ->assertJsonPath('data.0.criado_por.id', $autor->id)
            ->assertJsonPath('data.0.conta.nome', 'Conta a Inativar');
    }

    public function test_quantidade_de_consultas_da_listagem_nao_cresce_com_o_numero_de_linhas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();

        $this->entrada($conta, $categoria, $pastor);
        $this->actingAs($pastor)->getJson('/api/v1/entradas')->assertOk(); // aquece o usuário autenticado
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($pastor)->getJson('/api/v1/entradas')->assertOk();
        $com1 = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::flushQueryLog();

        for ($i = 0; $i < 9; $i++) {
            $this->entrada($conta, $categoria, $pastor);
        }
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->actingAs($pastor)->getJson('/api/v1/entradas')->assertOk();
        $com10 = count(\Illuminate\Support\Facades\DB::getQueryLog());

        $this->assertSame($com1, $com10, json_encode(array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query')));
    }
}
