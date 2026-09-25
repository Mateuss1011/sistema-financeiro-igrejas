<?php

namespace Tests\Feature\Relatorios;

use App\Models\Despesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Relatórios de Entradas e de Despesas: linhas, colunas, regras de data/estorno/cancelamento, totais e escopo do Auxiliar. */
class EntradasEDespesasDoRelatorioTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    private object $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = (object) $this->massaDoMes($this->mesPassado(2));
    }

    private function ids($resposta): array
    {
        return array_column($resposta->json('data'), 'id');
    }

    // ================================================================ ENTRADAS

    public function test_entradas_traz_so_as_originais_em_vigor_do_mes_pela_competencia(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertEqualsCanonicalizing([$this->m->e1->id, $this->m->e2->id, $this->m->e3->id], $this->ids($r));
        $this->assertNotContains($this->m->e4->id, $this->ids($r), 'entrada estornada não conta');
        $this->assertNotContains($this->m->e4estorno->id, $this->ids($r), 'linha de estorno não aparece');
        $this->assertNotContains($this->m->eForaAntes->id, $this->ids($r));
        $this->assertNotContains($this->m->eForaDepois->id, $this->ids($r));
        $this->assertSame('390.50', $this->totalDe($r, 'total'));
        $this->assertSame(3, $this->totalDe($r, 'quantidade'));
    }

    public function test_entradas_colunas_e_valores_das_linhas(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->m->mes, 'ordenar' => 'data_competencia'])->assertOk();

        $this->assertSame(
            ['id', 'data_competencia', 'categoria', 'conta', 'tipo', 'descricao', 'valor', 'status', 'criado_por', 'criado_em'],
            array_column($r->json('meta.colunas'), 'chave')
        );
        $this->assertSame(
            ['ID', 'Data de competência', 'Categoria', 'Conta', 'Tipo', 'Descrição', 'Valor', 'Status', 'Criado por', 'Data de criação'],
            array_column($r->json('meta.colunas'), 'rotulo')
        );

        $linha = $r->json('data.0'); // e1: a mais antiga do mês
        $this->assertSame($this->m->e1->id, $linha['id']);
        $this->assertSame($this->m->mes . '-05', $linha['data_competencia']);
        $this->assertSame('Dízimos', $linha['categoria']);
        $this->assertSame('Banco A', $linha['conta']);
        $this->assertSame('Banco', $linha['tipo']);
        $this->assertSame('Dízimo de domingo', $linha['descricao']);
        $this->assertSame('100.00', $linha['valor']);
        $this->assertSame('Confirmada', $linha['status']);
        $this->assertSame($this->m->aux1->name, $linha['criado_por']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $linha['criado_em']);
        $this->assertSame('Caixa', collect($r->json('data'))->firstWhere('id', $this->m->e2->id)['tipo']);
    }

    public function test_soma_das_linhas_listadas_e_igual_ao_total(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->m->mes])->assertOk();

        $soma = '0.00';
        foreach ($r->json('data') as $linha) {
            $soma = bcadd($soma, $linha['valor'], 2);
        }
        $this->assertSame($soma, $this->totalDe($r, 'total'));
    }

    public function test_auxiliar_ve_so_as_entradas_que_ele_criou_e_o_estorno_feito_por_outro_nao_quebra_o_total(): void
    {
        $r1 = $this->relatorioApi($this->m->aux1, 'entradas', ['ano_mes' => $this->m->mes])->assertOk();
        $r2 = $this->relatorioApi($this->m->aux2, 'entradas', ['ano_mes' => $this->m->mes])->assertOk();

        // aux1 criou e1 e e4 (estornada pelo Pastor): só e1 conta, mesmo com o estorno criado por outra pessoa.
        $this->assertSame([$this->m->e1->id], $this->ids($r1));
        $this->assertSame('100.00', $this->totalDe($r1, 'total'));
        $r1->assertJsonPath('meta.escopo', 'proprios');
        $this->assertSame([$this->m->e3->id], $this->ids($r2));
        $this->assertSame('40.00', $this->totalDe($r2, 'total'));
        $this->assertStringNotContainsString($this->m->pastor->name, $r1->getContent(), 'nada de outros usuários');
        $this->assertStringNotContainsString($this->m->tesoureiro->name, $r1->getContent());
    }

    public function test_perfis_completos_veem_todas_as_entradas_de_todos(): void
    {
        foreach ([$this->m->pastor, $this->m->tesoureiro] as $ator) {
            $r = $this->relatorioApi($ator, 'entradas', ['ano_mes' => $this->m->mes])->assertOk()->assertJsonPath('meta.escopo', 'todos');
            $this->assertSame('390.50', $this->totalDe($r, 'total'));
        }
    }

    public function test_entradas_de_outro_mes_aparecem_no_mes_certo(): void
    {
        $anterior = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->mesAnterior($this->m->mes)])->assertOk();
        $seguinte = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->mesSeguinte($this->m->mes)])->assertOk();

        $this->assertSame([$this->m->eForaAntes->id], $this->ids($anterior));
        $this->assertSame([$this->m->eForaDepois->id], $this->ids($seguinte));
    }

    public function test_mes_sem_entradas_devolve_lista_vazia_e_totais_zerados(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => '2000-01'])->assertOk();

        $r->assertJsonPath('data', []);
        $this->assertSame('0.00', $this->totalDe($r, 'total'));
        $this->assertSame(0, $this->totalDe($r, 'quantidade'));
    }

    // ================================================================ DESPESAS

    public function test_despesas_lista_pagas_pelo_pagamento_pendentes_e_canceladas_pela_competencia(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->m->d1->id, $this->m->d2->id, $this->m->d4->id, $this->m->d5->id, $this->m->d6->id, $this->m->d7->id],
            $this->ids($r)
        );
        // d1: competência no mês anterior, pago neste mês => aparece aqui; d3: competência neste mês, paga no seguinte => não.
        $this->assertContains($this->m->d1->id, $this->ids($r));
        $this->assertNotContains($this->m->d3->id, $this->ids($r));
        $seguinte = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->mesSeguinte($this->m->mes)])->assertOk();
        $this->assertSame([$this->m->d3->id], $this->ids($seguinte));
        $this->assertSame('99.00', $this->totalDe($seguinte, 'pagas_total'));
    }

    public function test_despesas_nunca_lista_a_linha_de_estorno(): void
    {
        $estorno = Despesa::where('despesa_estornada_id', $this->m->d7->id)->firstOrFail();

        $r = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertNotContains($estorno->id, $this->ids($r));
        $this->assertSame('Estornada', collect($r->json('data'))->firstWhere('id', $this->m->d7->id)['status']);
    }

    public function test_totais_de_despesas_usam_pagamento_para_pagas_e_competencia_para_pendentes(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertSame(2, $this->totalDe($r, 'pagas_quantidade'));
        $this->assertSame('75.00', $this->totalDe($r, 'pagas_total'));      // d1 30 + d2 45 (nunca d3 99)
        $this->assertSame(2, $this->totalDe($r, 'pendentes_quantidade'));
        $this->assertSame('32.34', $this->totalDe($r, 'pendentes_total'));  // d4 12.34 + d5 20
    }

    public function test_canceladas_e_estornadas_aparecem_na_lista_mas_nao_entram_em_nenhum_total(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();
        $porId = collect($r->json('data'))->keyBy('id');

        $this->assertSame('Cancelada', $porId[$this->m->d6->id]['status']);
        $this->assertSame('Estornada', $porId[$this->m->d7->id]['status']);
        // 500.00 (cancelada) e 70.00 (estornada) ficam de fora dos totais.
        $this->assertSame('75.00', $this->totalDe($r, 'pagas_total'));
        $this->assertSame('32.34', $this->totalDe($r, 'pendentes_total'));
    }

    public function test_despesas_colunas_e_valores_das_linhas(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertSame(
            ['id', 'data_competencia', 'data_pagamento', 'categoria', 'conta', 'fornecedor', 'descricao', 'valor', 'status', 'criado_por', 'criado_em'],
            array_column($r->json('meta.colunas'), 'chave')
        );
        $porId = collect($r->json('data'))->keyBy('id');

        $paga = $porId[$this->m->d1->id];
        $this->assertSame($this->mesAnterior($this->m->mes) . '-20', $paga['data_competencia']);
        $this->assertSame($this->m->mes . '-06', $paga['data_pagamento']);
        $this->assertSame('Energia', $paga['categoria']);
        $this->assertSame('Banco A', $paga['conta']);
        $this->assertSame('Companhia de Energia', $paga['fornecedor']);
        $this->assertSame('Luz', $paga['descricao']);
        $this->assertSame('30.00', $paga['valor']);
        $this->assertSame('Paga', $paga['status']);

        $pendente = $porId[$this->m->d4->id];
        $this->assertNull($pendente['data_pagamento']);
        $this->assertNull($pendente['conta']);
        $this->assertSame('Pendente', $pendente['status']);
    }

    public function test_soma_das_pagas_e_das_pendentes_listadas_bate_com_os_totais(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();

        $somas = ['Paga' => '0.00', 'Pendente' => '0.00'];
        foreach ($r->json('data') as $linha) {
            if (isset($somas[$linha['status']])) {
                $somas[$linha['status']] = bcadd($somas[$linha['status']], $linha['valor'], 2);
            }
        }
        $this->assertSame($somas['Paga'], $this->totalDe($r, 'pagas_total'));
        $this->assertSame($somas['Pendente'], $this->totalDe($r, 'pendentes_total'));
    }

    public function test_filtro_de_status_restringe_linhas_e_zera_o_total_do_status_excluido(): void
    {
        $pastor = $this->m->pastor;
        $mes = $this->m->mes;

        $pagas = $this->relatorioApi($pastor, 'despesas', ['ano_mes' => $mes, 'status' => 'paga'])->assertOk();
        $this->assertEqualsCanonicalizing([$this->m->d1->id, $this->m->d2->id], $this->ids($pagas));
        $this->assertSame('75.00', $this->totalDe($pagas, 'pagas_total'));
        $this->assertSame('0.00', $this->totalDe($pagas, 'pendentes_total'));
        $this->assertSame(0, $this->totalDe($pagas, 'pendentes_quantidade'));

        $pendentes = $this->relatorioApi($pastor, 'despesas', ['ano_mes' => $mes, 'status' => 'pendente'])->assertOk();
        $this->assertEqualsCanonicalizing([$this->m->d4->id, $this->m->d5->id], $this->ids($pendentes));
        $this->assertSame('0.00', $this->totalDe($pendentes, 'pagas_total'));
        $this->assertSame('32.34', $this->totalDe($pendentes, 'pendentes_total'));

        $canceladas = $this->relatorioApi($pastor, 'despesas', ['ano_mes' => $mes, 'status' => 'cancelada'])->assertOk();
        $this->assertSame([$this->m->d6->id], $this->ids($canceladas));
        $this->assertSame('0.00', $this->totalDe($canceladas, 'pagas_total'));
        $this->assertSame('0.00', $this->totalDe($canceladas, 'pendentes_total'));

        $estornadas = $this->relatorioApi($pastor, 'despesas', ['ano_mes' => $mes, 'status' => 'estornada'])->assertOk();
        $this->assertSame([$this->m->d7->id], $this->ids($estornadas));
    }

    public function test_filtros_de_conta_e_categoria_afetam_linhas_e_totais(): void
    {
        $porConta = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes, 'conta_id' => $this->m->contaB->id])->assertOk();
        $this->assertSame([$this->m->d2->id], $this->ids($porConta));
        $this->assertSame('45.00', $this->totalDe($porConta, 'pagas_total'));
        $this->assertSame(0, $this->totalDe($porConta, 'pendentes_quantidade'), 'pendente não tem conta');

        $porCategoria = $this->relatorioApi($this->m->pastor, 'despesas', ['ano_mes' => $this->m->mes, 'categoria_id' => $this->m->catD1->id])->assertOk();
        $this->assertEqualsCanonicalizing([$this->m->d1->id, $this->m->d4->id, $this->m->d6->id], $this->ids($porCategoria));
        $this->assertSame('30.00', $this->totalDe($porCategoria, 'pagas_total'));
        $this->assertSame('12.34', $this->totalDe($porCategoria, 'pendentes_total'));
    }

    public function test_auxiliar_ve_so_as_despesas_que_criou(): void
    {
        $r = $this->relatorioApi($this->m->aux1, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertEqualsCanonicalizing([$this->m->d1->id, $this->m->d4->id, $this->m->d7->id], $this->ids($r));
        $r->assertJsonPath('meta.escopo', 'proprios');
        $this->assertSame('30.00', $this->totalDe($r, 'pagas_total'));
        $this->assertSame(1, $this->totalDe($r, 'pendentes_quantidade'));
        $this->assertSame('12.34', $this->totalDe($r, 'pendentes_total'));
        $this->assertStringNotContainsString($this->m->tesoureiro->name, $r->getContent());

        $vazio = $this->relatorioApi($this->m->aux2, 'despesas', ['ano_mes' => $this->m->mes])->assertOk();
        $vazio->assertJsonPath('data', []);
        $this->assertSame('0.00', $this->totalDe($vazio, 'pagas_total'));
    }

    public function test_ordenacao_por_data_de_pagamento_e_valor(): void
    {
        $pastor = $this->m->pastor;
        $ids = fn (array $q) => $this->ids($this->relatorioApi($pastor, 'despesas', ['ano_mes' => $this->m->mes, 'status' => 'paga'] + $q)->assertOk());

        $this->assertSame([$this->m->d1->id, $this->m->d2->id], $ids(['ordenar' => 'data_pagamento']));
        $this->assertSame([$this->m->d2->id, $this->m->d1->id], $ids(['ordenar' => '-data_pagamento']));
        $this->assertSame([$this->m->d2->id, $this->m->d1->id], $ids(['ordenar' => '-valor']));
    }
}
