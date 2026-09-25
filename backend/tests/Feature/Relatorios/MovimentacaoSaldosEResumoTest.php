<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use App\Models\Conta;
use App\Services\SaldoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Relatórios de Movimentação financeira, Saldos por conta e Resumo financeiro. */
class MovimentacaoSaldosEResumoTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    private object $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = (object) $this->massaDoMes($this->mesPassado(2));
    }

    private function saldos(): SaldoService
    {
        return app(SaldoService::class);
    }

    private function mov(?object $ator = null, array $query = [])
    {
        return $this->relatorioApi($ator ?? $this->m->pastor, 'movimentacoes', ['ano_mes' => $this->m->mes] + $query)->assertOk();
    }

    // ================================================================ MOVIMENTAÇÃO

    public function test_movimentacao_traz_entradas_despesas_pagas_transferencias_e_ajustes_do_mes(): void
    {
        $r = $this->mov();
        $tipos = array_count_values(array_column($r->json('data'), 'tipo'));

        $this->assertSame(
            ['Ajuste (crédito)' => 1, 'Ajuste (débito)' => 1, 'Despesa paga' => 2, 'Entrada' => 3, 'Transferência enviada' => 1, 'Transferência recebida' => 1],
            collect($tipos)->sortKeys()->all()
        );
        $this->assertSame(9, $this->totalDe($r, 'quantidade'));
        $this->assertCount(9, $r->json('data'));
    }

    public function test_movimentacao_colunas_e_regras_de_entrada_saida_e_liquido(): void
    {
        $r = $this->mov();

        $this->assertSame(
            ['data', 'tipo', 'conta', 'descricao', 'entrada', 'saida', 'liquido', 'referencia', 'usuario'],
            array_column($r->json('meta.colunas'), 'chave')
        );
        foreach ($r->json('data') as $linha) {
            $this->assertTrue(($linha['entrada'] === null) xor ($linha['saida'] === null), 'exatamente um lado preenchido: ' . $linha['tipo']);
            $esperado = $linha['entrada'] !== null ? $linha['entrada'] : bcsub('0', $linha['saida'], 2);
            $this->assertSame($esperado, $linha['liquido']);
        }
        $entrada = collect($r->json('data'))->first(fn ($l) => $l['tipo'] === 'Entrada' && $l['referencia'] === $this->m->e1->id);
        $this->assertSame($this->m->mes . '-05', $entrada['data']);
        $this->assertSame('Banco A', $entrada['conta']);
        $this->assertSame('100.00', $entrada['entrada']);
        $this->assertNull($entrada['saida']);
        $this->assertSame($this->m->aux1->name, $entrada['usuario']);

        $despesa = collect($r->json('data'))->first(fn ($l) => $l['tipo'] === 'Despesa paga' && $l['referencia'] === $this->m->d1->id);
        $this->assertSame($this->m->mes . '-06', $despesa['data'], 'despesa paga entra pela data de pagamento');
        $this->assertSame('30.00', $despesa['saida']);
        $this->assertSame('-30.00', $despesa['liquido']);
    }

    public function test_transferencia_aparece_como_transferencia_e_nao_como_receita_nem_despesa(): void
    {
        $r = $this->mov();
        $linhas = collect($r->json('data'))->where('referencia', $this->m->t1->id)->filter(fn ($l) => str_starts_with($l['tipo'], 'Transferência'));

        $this->assertCount(2, $linhas, 'uma linha por lado da transferência');
        $recebida = $linhas->firstWhere('tipo', 'Transferência recebida');
        $enviada = $linhas->firstWhere('tipo', 'Transferência enviada');
        $this->assertSame('Caixa B', $recebida['conta']);
        $this->assertSame('300.00', $recebida['liquido']);
        $this->assertSame('Banco A', $enviada['conta']);
        $this->assertSame('-300.00', $enviada['liquido']);
        $this->assertStringContainsString('Banco A → Caixa B', $recebida['descricao']);
        $this->assertStringContainsString('Reforço do caixa', $recebida['descricao']);
        $this->assertSame($this->m->tesoureiro->name, $recebida['usuario']);

        // Nunca somam em receita/despesa; o efeito consolidado das duas pontas é zero.
        $this->assertSame('390.50', $this->totalDe($r, 'total_entradas'));
        $this->assertSame('75.00', $this->totalDe($r, 'total_despesas_pagas'));
        $this->assertSame('300.00', $this->totalDe($r, 'transferencias_recebidas'));
        $this->assertSame('300.00', $this->totalDe($r, 'transferencias_enviadas'));
    }

    public function test_ajustes_aparecem_como_ajuste_e_nao_como_entrada_ou_despesa(): void
    {
        $r = $this->mov();
        $credito = collect($r->json('data'))->first(fn ($l) => $l['tipo'] === 'Ajuste (crédito)');
        $debito = collect($r->json('data'))->first(fn ($l) => $l['tipo'] === 'Ajuste (débito)');

        $this->assertSame($this->m->aj1->id, $credito['referencia']);
        $this->assertSame('Conciliação bancária', $credito['descricao']);
        $this->assertSame('25.00', $credito['liquido']);
        $this->assertSame('Banco A', $credito['conta']);
        $this->assertSame($this->m->aj2->id, $debito['referencia']);
        $this->assertSame('-10.00', $debito['liquido']);
        $this->assertSame('Caixa B', $debito['conta']);

        $this->assertSame('25.00', $this->totalDe($r, 'ajustes_credito'));
        $this->assertSame('10.00', $this->totalDe($r, 'ajustes_debito'));
        $this->assertSame('390.50', $this->totalDe($r, 'total_entradas'), 'ajuste de crédito não é receita');
        $this->assertSame('75.00', $this->totalDe($r, 'total_despesas_pagas'), 'ajuste de débito não é despesa');
    }

    public function test_estornos_nao_geram_dupla_contagem_nem_aparecem(): void
    {
        $r = $this->mov();
        $referencias = collect($r->json('data'))->map(fn ($l) => $l['tipo'] . '#' . $l['referencia']);

        $this->assertFalse($referencias->contains('Entrada#' . $this->m->e4->id), 'entrada estornada fora');
        $this->assertFalse($referencias->contains('Entrada#' . $this->m->e4estorno->id), 'linha de estorno fora');
        $this->assertFalse($referencias->contains('Despesa paga#' . $this->m->d7->id), 'despesa estornada fora');
        $this->assertFalse($referencias->contains(fn ($x) => str_ends_with($x, '#' . $this->m->t2->id) && str_starts_with($x, 'Transfer')), 'transferência estornada fora');
        $this->assertFalse($referencias->contains('Despesa paga#' . $this->m->d3->id), 'paga no mês seguinte fora');
        $this->assertFalse($referencias->contains(fn ($x) => str_starts_with($x, 'Despesa') && str_ends_with($x, '#' . $this->m->d6->id)), 'cancelada fora');
    }

    public function test_variacao_liquida_e_exatamente_a_variacao_de_saldo_do_saldo_service(): void
    {
        $r = $this->mov();
        $contas = Conta::all();
        $fim = $this->ultimoDiaDe($this->m->mes);
        $vespera = Carbon::createFromFormat('!Y-m-d', $this->m->mes . '-01')->subDay()->format('Y-m-d');

        $antes = $this->saldos()->saldosAte($contas, $vespera);
        $depois = $this->saldos()->saldosAte($contas, $fim);
        $delta = '0.00';
        foreach ($contas as $conta) {
            $delta = bcadd($delta, bcsub($depois[$conta->id], $antes[$conta->id], 2), 2);
        }

        $this->assertSame('330.50', $delta);
        $this->assertSame($delta, $this->totalDe($r, 'variacao_liquida'));

        // e por conta (filtro conta_id): variação da conta = soma dos líquidos das suas linhas
        foreach ([$this->m->contaA, $this->m->contaB] as $conta) {
            $porConta = $this->mov(null, ['conta_id' => $conta->id]);
            $esperado = bcsub($depois[$conta->id], $antes[$conta->id], 2);
            $this->assertSame($esperado, $this->totalDe($porConta, 'variacao_liquida'), $conta->nome);
            $soma = '0.00';
            foreach ($porConta->json('data') as $linha) {
                $soma = bcadd($soma, $linha['liquido'], 2);
                $this->assertSame($conta->nome, $linha['conta']);
            }
            $this->assertSame($esperado, $soma);
        }
    }

    public function test_totais_da_movimentacao_batem_com_a_soma_das_linhas_por_tipo(): void
    {
        $r = $this->mov();
        $soma = fn (string $tipo, string $campo) => collect($r->json('data'))->where('tipo', $tipo)->reduce(fn ($t, $l) => bcadd($t, $l[$campo] ?? '0', 2), '0.00');

        $this->assertSame($soma('Entrada', 'entrada'), $this->totalDe($r, 'total_entradas'));
        $this->assertSame($soma('Despesa paga', 'saida'), $this->totalDe($r, 'total_despesas_pagas'));
        $this->assertSame($soma('Transferência recebida', 'entrada'), $this->totalDe($r, 'transferencias_recebidas'));
        $this->assertSame($soma('Transferência enviada', 'saida'), $this->totalDe($r, 'transferencias_enviadas'));
    }

    public function test_movimentacao_ordenada_da_mais_recente_e_paginada_sem_perder_linhas(): void
    {
        $r = $this->mov();
        $datas = array_column($r->json('data'), 'data');
        $ordenadas = $datas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $datas, 'padrão: data decrescente');

        $asc = array_column($this->mov(null, ['ordenar' => 'data'])->json('data'), 'data');
        $ordenadasAsc = $asc;
        sort($ordenadasAsc);
        $this->assertSame($ordenadasAsc, $asc);

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
            $p = $this->mov(null, ['por_pagina' => 4, 'page' => $pagina]);
            $p->assertJsonPath('meta.paginacao.total', 9)->assertJsonPath('meta.paginacao.last_page', 3);
            foreach ($p->json('data') as $l) {
                $vistos[] = $l['tipo'] . '#' . $l['referencia'];
            }
            $this->assertSame('330.50', $this->totalDe($p, 'variacao_liquida'), 'totais cobrem todo o filtro, não a página');
        }
        $this->assertCount(9, $vistos);
        $this->assertCount(9, array_unique($vistos));
    }

    public function test_auxiliar_so_recebe_as_proprias_entradas_e_despesas_sem_transferencias_nem_ajustes(): void
    {
        $r = $this->mov($this->m->aux1);

        $this->assertEqualsCanonicalizing(['Entrada#' . $this->m->e1->id, 'Despesa paga#' . $this->m->d1->id], collect($r->json('data'))->map(fn ($l) => $l['tipo'] . '#' . $l['referencia'])->all());
        $r->assertJsonPath('meta.escopo', 'proprios');
        $this->assertSame(
            ['quantidade', 'total_despesas_pagas', 'total_entradas', 'variacao_liquida'],
            collect(array_column($r->json('meta.totais'), 'chave'))->sort()->values()->all(),
            'sem chaves de transferência/ajuste para o Auxiliar'
        );
        $this->assertSame('100.00', $this->totalDe($r, 'total_entradas'));
        $this->assertSame('30.00', $this->totalDe($r, 'total_despesas_pagas'));
        $this->assertSame('70.00', $this->totalDe($r, 'variacao_liquida'));
        foreach ([$this->m->pastor->name, $this->m->tesoureiro->name, 'Reforço do caixa', 'Conciliação bancária', 'Caixa B'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $r->getContent());
        }
    }

    public function test_administrador_e_tesoureiro_veem_transferencias_e_ajustes(): void
    {
        foreach ([PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $r = $this->mov($this->como($perfil));
            $this->assertSame(9, $this->totalDe($r, 'quantidade'));
            $this->assertSame('300.00', $this->totalDe($r, 'transferencias_recebidas'));
        }
    }

    public function test_movimentacao_sem_dados_no_mes(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'movimentacoes', ['ano_mes' => '2000-01'])->assertOk();

        $r->assertJsonPath('data', []);
        $this->assertSame(0, $this->totalDe($r, 'quantidade'));
        $this->assertSame('0.00', $this->totalDe($r, 'variacao_liquida'));
    }

    // ================================================================ SALDOS

    public function test_saldos_do_mes_corrente_vem_do_saldo_service_atual(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'saldos')->assertOk();
        $porNome = collect($r->json('data'))->keyBy('nome');

        foreach (Conta::all() as $conta) {
            $this->assertSame($this->saldos()->saldoAtual($conta->fresh()), $porNome[$conta->nome]['saldo'], $conta->nome);
        }
        $r->assertJsonPath('meta.filtros.saldo_referencia', 'atual');
        $total = '0.00';
        foreach ($r->json('data') as $l) {
            $total = bcadd($total, $l['saldo'], 2);
        }
        $this->assertSame($total, $this->totalDe($r, 'total'));
    }

    public function test_saldos_de_mes_anterior_vem_do_saldo_ate_o_ultimo_dia_do_mes(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'saldos', ['ano_mes' => $this->m->mes])->assertOk();
        $fim = $this->ultimoDiaDe($this->m->mes);
        $porNome = collect($r->json('data'))->keyBy('nome');

        foreach (Conta::all() as $conta) {
            $this->assertSame($this->saldos()->saldoAte($conta->fresh(), $fim), $porNome[$conta->nome]['saldo'], $conta->nome);
        }
        $r->assertJsonPath('meta.filtros.saldo_referencia', $fim);
        // Conta A ao fim do mês (conferido à mão no razão): saldo inicial 1000 + entrada de 77 do mês anterior + 100 + 40
        // − despesa paga de 30 − transferência enviada de 300 + ajuste de crédito de 25 = 912. Os pares de estorno se
        // anulam (60/−60, 70/+70, 80/−80); a despesa de 99 (paga no mês seguinte) e a entrada de 88 (mês seguinte) ficam fora.
        $this->assertSame('912.00', $porNome['Banco A']['saldo']);
        // No fim do mês seguinte entram a entrada de 88 (dia 01) e a despesa de 99 paga no dia 02: 912 + 88 − 99 = 901.
        $seguinte = $this->relatorioApi($this->m->pastor, 'saldos', ['ano_mes' => $this->mesSeguinte($this->m->mes)])->assertOk();
        $this->assertSame('901.00', collect($seguinte->json('data'))->firstWhere('nome', 'Banco A')['saldo']);
    }

    public function test_saldos_traz_colunas_situacao_e_contas_inativas_mas_nao_as_excluidas(): void
    {
        $excluida = $this->conta('Excluída', 'banco', '999.00');
        $excluida->delete();

        $r = $this->relatorioApi($this->m->pastor, 'saldos')->assertOk();
        $porNome = collect($r->json('data'))->keyBy('nome');

        $this->assertSame(['id', 'nome', 'tipo', 'ativa', 'saldo'], array_column($r->json('meta.colunas'), 'chave'));
        $this->assertSame('Inativa', $porNome['Banco C inativo']['ativa']);
        $this->assertSame('Ativa', $porNome['Banco A']['ativa']);
        $this->assertSame('Caixa', $porNome['Caixa B']['tipo']);
        $this->assertFalse($porNome->has('Excluída'));
        $this->assertSame(3, $r->json('meta.paginacao.total'));
    }

    public function test_saldos_filtro_de_conta_ordenacao_e_paginacao(): void
    {
        $pastor = $this->m->pastor;

        $uma = $this->relatorioApi($pastor, 'saldos', ['conta_id' => $this->m->contaB->id])->assertOk();
        $this->assertSame([$this->m->contaB->id], array_column($uma->json('data'), 'id'));
        $this->assertSame($uma->json('data.0.saldo'), $this->totalDe($uma, 'total'));

        $porSaldo = array_column($this->relatorioApi($pastor, 'saldos', ['ordenar' => '-saldo'])->assertOk()->json('data'), 'saldo');
        $ordenado = $porSaldo;
        usort($ordenado, fn ($a, $b) => bccomp($b, $a, 2));
        $this->assertSame($ordenado, $porSaldo);

        $p1 = $this->relatorioApi($pastor, 'saldos', ['por_pagina' => 2, 'page' => 1])->assertOk();
        $p2 = $this->relatorioApi($pastor, 'saldos', ['por_pagina' => 2, 'page' => 2])->assertOk();
        $this->assertCount(2, $p1->json('data'));
        $this->assertCount(1, $p2->json('data'));
        $this->assertSame($this->totalDe($p1, 'total'), $this->totalDe($p2, 'total'), 'total cobre todas as contas, não a página');
    }

    public function test_saldo_negativo_e_preservado_com_sinal(): void
    {
        $this->conta('Banco no vermelho', 'banco', '-150.75');

        $r = $this->relatorioApi($this->m->pastor, 'saldos')->assertOk();

        $this->assertSame('-150.75', collect($r->json('data'))->firstWhere('nome', 'Banco no vermelho')['saldo']);
    }

    // ================================================================ RESUMO

    public function test_resumo_completo_lista_indicadores_saldos_e_situacao_do_periodo(): void
    {
        $this->fecharApi($this->m->pastor, $this->m->mes)->assertOk();

        $r = $this->relatorioApi($this->m->pastor, 'resumo', ['ano_mes' => $this->m->mes])->assertOk();
        $linhas = collect($r->json('data'))->pluck('valor', 'indicador');

        $this->assertSame(substr($this->m->mes, 5, 2) . '/' . substr($this->m->mes, 0, 4), $linhas['Período']);
        $this->assertSame('390.50', $linhas['Total de entradas']);
        $this->assertSame('75.00', $linhas['Total de despesas pagas']);
        $this->assertSame('32.34', $linhas['Total de despesas pendentes']);
        $this->assertSame(2, $linhas['Quantidade de despesas pendentes']);
        $this->assertSame('Fechado', $linhas['Situação do período']);
        $this->assertSame(Carbon::createFromFormat('!Y-m-d', $this->ultimoDiaDe($this->m->mes))->format('d/m/Y'), $linhas['Data de referência do saldo']);
        $this->assertSame('912.00', $linhas['Saldo — Banco A']);
        $this->assertSame('Saldo — Banco C inativo (inativa)', collect($linhas->keys())->first(fn ($k) => str_contains($k, 'inativ')));
        $this->assertArrayHasKey('Saldo total', $linhas->all());
    }

    public function test_resumo_do_auxiliar_so_tem_os_totais_dos_proprios_lancamentos_sem_saldo(): void
    {
        $r = $this->relatorioApi($this->m->aux1, 'resumo', ['ano_mes' => $this->m->mes])->assertOk();
        $linhas = collect($r->json('data'))->pluck('valor', 'indicador');

        $this->assertSame(
            ['Período', 'Total de entradas', 'Total de despesas pagas', 'Total de despesas pendentes', 'Quantidade de despesas pendentes'],
            $linhas->keys()->all()
        );
        $this->assertSame('100.00', $linhas['Total de entradas']);
        $this->assertSame('30.00', $linhas['Total de despesas pagas']);
        $this->assertSame('12.34', $linhas['Total de despesas pendentes']);
        $this->assertSame(1, $linhas['Quantidade de despesas pendentes']);
        foreach (['Saldo', 'Banco A', 'Caixa B', 'Situação do período'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $r->getContent());
        }
    }

    public function test_resumo_linhas_carregam_o_tipo_de_cada_valor(): void
    {
        $r = $this->relatorioApi($this->m->pastor, 'resumo', ['ano_mes' => $this->m->mes])->assertOk();
        $tipos = collect($r->json('data'))->mapWithKeys(fn ($l) => [$l['indicador'] => $l['_tipos']['valor']]);

        $this->assertSame('dinheiro', $tipos['Total de entradas']);
        $this->assertSame('inteiro', $tipos['Quantidade de despesas pendentes']);
        $this->assertSame('texto', $tipos['Situação do período']);
    }
}
