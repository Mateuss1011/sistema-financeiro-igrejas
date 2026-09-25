<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** ano_mes (omitido = mês corrente em America/Sao_Paulo; futuro = 422), filtros extras, ordenação e paginação. */
class FiltrosDeRelatoriosTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mes_omitido_usa_o_mes_corrente_em_todos_os_relatorios(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (self::RELATORIOS as $relatorio) {
            $this->relatorioApi($pastor, $relatorio)->assertOk()->assertJsonPath('meta.ano_mes', $this->mesAtual());
            $this->exportarApi($pastor, $relatorio, 'csv')->assertOk()
                ->assertHeader('Content-Disposition', 'attachment; filename="sfg-relatorio-' . $relatorio . '-' . $this->mesAtual() . '.csv"');
        }
    }

    public function test_mes_atual_e_meses_passados_sao_aceitos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach ([$this->mesAtual(), $this->mesAnterior($this->mesAtual()), $this->mesPassado(11), '2000-01'] as $mes) {
            foreach (self::RELATORIOS as $relatorio) {
                $this->relatorioApi($pastor, $relatorio, ['ano_mes' => $mes])->assertOk()->assertJsonPath('meta.ano_mes', $mes);
            }
        }
    }

    public function test_mes_futuro_e_rejeitado_com_422_em_consulta_e_exportacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach ([$this->mesSeguinte($this->mesAtual()), '2999-12'] as $futuro) {
            foreach (self::RELATORIOS as $relatorio) {
                // Fase 13: consultas e exportações têm cota por minuto; este teste dispara dezenas em sequência de propósito.
                Cache::flush();
                $this->relatorioApi($pastor, $relatorio, ['ano_mes' => $futuro])->assertStatus(422)->assertJsonValidationErrors('ano_mes');
                $this->exportarApi($pastor, $relatorio, 'csv', ['ano_mes' => $futuro])->assertStatus(422);
                Cache::flush();
                $this->exportarApi($pastor, $relatorio, 'xlsx', ['ano_mes' => $futuro])->assertStatus(422);
            }
        }
    }

    public function test_formatos_invalidos_de_ano_mes_retornam_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['2026-13', '2026-00', '2026-1', '26-09', '2026/09', 'setembro', '2026-09-01', "2026-09' OR '1'='1", '202609'] as $lixo) {
            $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $lixo])->assertStatus(422)->assertJsonValidationErrors('ano_mes');
        }
        $this->actingAs($pastor)->getJson('/api/v1/relatorios/entradas?ano_mes[]=2026-01')->assertStatus(422);
    }

    public function test_ano_mes_vazio_equivale_a_omitido(): void
    {
        $this->relatorioApi($this->como(PerfilSlug::Pastor), 'entradas', ['ano_mes' => ''])->assertOk()->assertJsonPath('meta.ano_mes', $this->mesAtual());
    }

    public function test_mes_corrente_segue_o_fuso_de_sao_paulo_e_nao_o_utc(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        Carbon::setTestNow(Carbon::parse('2026-10-01 01:30:00', 'UTC')); // 30/09 22:30 em São Paulo
        $this->relatorioApi($pastor, 'resumo')->assertOk()->assertJsonPath('meta.ano_mes', '2026-09');
        $this->relatorioApi($pastor, 'resumo', ['ano_mes' => '2026-10'])->assertStatus(422);

        Carbon::setTestNow(Carbon::parse('2026-10-01 03:30:00', 'UTC')); // 01/10 00:30 em São Paulo
        $this->relatorioApi($pastor, 'resumo')->assertOk()->assertJsonPath('meta.ano_mes', '2026-10');
        $this->relatorioApi($pastor, 'resumo', ['ano_mes' => '2026-11'])->assertStatus(422);
    }

    public function test_filtros_extras_invalidos_retornam_422_onde_se_aplicam(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['entradas', 'despesas', 'movimentacoes', 'saldos'] as $relatorio) {
            foreach (['conta_id' => ['abc', 0, -1]] as $campo => $invalidos) {
                foreach ($invalidos as $valor) {
                    $this->relatorioApi($pastor, $relatorio, [$campo => $valor])->assertStatus(422)->assertJsonValidationErrors($campo);
                }
            }
        }
        foreach (['entradas', 'despesas'] as $relatorio) {
            $this->relatorioApi($pastor, $relatorio, ['categoria_id' => 'x'])->assertStatus(422);
        }
        $this->relatorioApi($pastor, 'despesas', ['status' => 'inexistente'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->relatorioApi($pastor, 'entradas', ['ordenar' => 'senha'])->assertStatus(422);
        $this->relatorioApi($pastor, 'entradas', ['ordenar' => '-valor,descricao'])->assertStatus(422);
        $this->relatorioApi($pastor, 'entradas', ['ordenar' => 'id; DROP TABLE entradas'])->assertStatus(422);
        foreach (['por_pagina' => [0, 101], 'page' => [0]] as $campo => $invalidos) {
            foreach ($invalidos as $valor) {
                $this->relatorioApi($pastor, 'entradas', [$campo => $valor])->assertStatus(422);
            }
        }
    }

    public function test_filtros_que_nao_se_aplicam_ao_relatorio_sao_ignorados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->massaDoMes($this->mesPassado());
        $mes = $this->mesPassado();

        $normal = $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes])->json();
        $comLixo = $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes, 'status' => 'lixo', 'nao_existe' => 'x'])->assertOk()->json();
        $this->assertSame($normal, $comLixo);

        // Resumo não tem filtros extras nem ordenação nem paginação.
        $resumo = $this->relatorioApi($pastor, 'resumo', ['ano_mes' => $mes, 'ordenar' => 'qualquer', 'por_pagina' => 3, 'conta_id' => 1])->assertOk();
        $this->assertNull($resumo->json('meta.paginacao'));
        $this->assertSame(['ano_mes' => $mes], $resumo->json('meta.filtros'));
    }

    public function test_paginacao_percorre_todas_as_linhas_sem_repetir_nem_perder(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $mes = $this->mesPassado();
        for ($i = 1; $i <= 7; $i++) {
            $this->entrada($conta, $categoria, $pastor, "{$i}0.00", sprintf('%s-%02d', $mes, $i));
        }

        $ids = [];
        foreach ([1, 2, 3] as $pagina) {
            $r = $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes, 'por_pagina' => 3, 'page' => $pagina])->assertOk();
            $r->assertJsonPath('meta.paginacao', ['current_page' => $pagina, 'last_page' => 3, 'per_page' => 3, 'total' => 7]);
            array_push($ids, ...array_column($r->json('data'), 'id'));
        }

        $this->assertCount(7, array_unique($ids));
        $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes, 'por_pagina' => 3, 'page' => 9])->assertOk()->assertJsonPath('data', []);
    }

    public function test_por_pagina_padrao_e_20_e_o_total_do_rodape_nao_depende_da_pagina(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $mes = $this->mesPassado();
        for ($i = 1; $i <= 25; $i++) {
            $this->entrada($conta, $categoria, $pastor, '1.00', sprintf('%s-%02d', $mes, $i));
        }

        $p1 = $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes])->assertOk();
        $p2 = $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes, 'page' => 2])->assertOk();

        $this->assertCount(20, $p1->json('data'));
        $this->assertCount(5, $p2->json('data'));
        $p1->assertJsonPath('meta.paginacao.per_page', 20);
        // Totais cobrem TODO o filtro, não a página.
        $this->assertSame('25.00', $this->totalDe($p1, 'total'));
        $this->assertSame('25.00', $this->totalDe($p2, 'total'));
        $this->assertSame(25, $this->totalDe($p1, 'quantidade'));
    }

    public function test_ordenacao_por_data_valor_e_desempate_por_id(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $mes = $this->mesPassado();
        $a = $this->entrada($conta, $categoria, $pastor, '30.00', "$mes-05");
        $b = $this->entrada($conta, $categoria, $pastor, '10.00', "$mes-05");
        $c = $this->entrada($conta, $categoria, $pastor, '20.00', "$mes-07");
        $ids = fn (array $q) => array_column($this->relatorioApi($pastor, 'entradas', ['ano_mes' => $mes] + $q)->assertOk()->json('data'), 'id');

        $this->assertSame([$c->id, $b->id, $a->id], $ids([]), 'padrão: data desc, id desc');
        $this->assertSame([$a->id, $b->id, $c->id], $ids(['ordenar' => 'data_competencia']));
        $this->assertSame([$a->id, $c->id, $b->id], $ids(['ordenar' => '-valor']));
        $this->assertSame([$b->id, $c->id, $a->id], $ids(['ordenar' => 'valor']));
    }

    public function test_filtros_de_conta_e_categoria_valem_para_linhas_e_totais(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado());
        $mes = $m->mes;

        $porConta = $this->relatorioApi($m->pastor, 'entradas', ['ano_mes' => $mes, 'conta_id' => $m->contaB->id])->assertOk();
        $this->assertSame([$m->e2->id], array_column($porConta->json('data'), 'id'));
        $this->assertSame('250.50', $this->totalDe($porConta, 'total'));
        $porConta->assertJsonPath('meta.filtros', ['ano_mes' => $mes, 'conta_id' => $m->contaB->id]);

        $porCategoria = $this->relatorioApi($m->pastor, 'entradas', ['ano_mes' => $mes, 'categoria_id' => $m->catE1->id])->assertOk();
        $this->assertEqualsCanonicalizing([$m->e1->id, $m->e3->id], array_column($porCategoria->json('data'), 'id'));
        $this->assertSame('140.00', $this->totalDe($porCategoria, 'total'));
    }
}
