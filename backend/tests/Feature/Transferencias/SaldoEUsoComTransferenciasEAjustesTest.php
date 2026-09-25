<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use App\Models\Conta;
use App\Models\Transferencia;
use App\Services\ContaService;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaldoEUsoComTransferenciasEAjustesTest extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    // ================= saldo =================

    public function test_formula_completa_com_todos_os_tipos_de_movimento_e_calculo_exato(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Conta A', 'banco', '1000.00');
        $b = $this->conta('Conta B', 'banco', '200.00');
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();
        $saldos = app(SaldoService::class);

        // A: entradas +100,10 −estorno 0,10 | despesa −40,00 | estorno de despesa +5,00
        $entrada = $this->entrada($a, $catE, $pastor, '100.10');
        $this->entrada($a, $catE, $pastor, '0.10', null, ['entrada_estornada_id' => $entrada->id, 'motivo_estorno' => 'x']);
        $despesa = $this->despesaPaga($a, $catD, $pastor, ['valor' => '40.00']);
        \App\Models\Despesa::create([
            'categoria_id' => $catD->id, 'conta_id' => $a->id, 'valor' => '5.00', 'data_competencia' => $this->hoje(), 'data_pagamento' => $this->hoje(),
            'status' => 'paga', 'despesa_estornada_id' => $despesa->id, 'motivo_estorno' => 'x', 'criado_por' => $pastor->id,
        ]);
        $this->assertSame('1065.00', $saldos->saldoAtual($a));   // 1000 + 100,10 − 0,10 − 40 + 5

        // transferências: A→B 30,30 (A −30,30 / B +30,30) e B→A 10,10 (B −10,10 / A +10,10)
        $this->transferenciaDireta($a, $b, $pastor, '30.30');
        $this->transferenciaDireta($b, $a, $pastor, '10.10');
        $this->assertSame('1044.80', $saldos->saldoAtual($a));   // 1065 − 30,30 + 10,10
        $this->assertSame('220.20', $saldos->saldoAtual($b));    // 200 + 30,30 − 10,10

        // ajustes: A crédito 7,00 e débito 2,50; B débito 0,20
        $this->ajusteDireto($a, $pastor, '7.00', 'credito');
        $this->ajusteDireto($a, $pastor, '2.50', 'debito');
        $this->ajusteDireto($b, $pastor, '0.20', 'debito');
        $this->assertSame('1049.30', $saldos->saldoAtual($a));   // 1044,80 + 7 − 2,50
        $this->assertSame('220.00', $saldos->saldoAtual($b));    // 220,20 − 0,20

        // Total consolidado: só as entradas/despesas/ajustes externos o alteram, nunca a transferência.
        $this->assertSame('1269.30', bcadd($saldos->saldoAtual($a), $saldos->saldoAtual($b), 2));
    }

    public function test_transferencia_e_estorno_nao_alteram_o_saldo_consolidado_e_a_soma_e_sempre_zero(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $contas = [$this->conta('C1', 'banco', '100.00'), $this->conta('C2', 'caixa', '50.00'), $this->conta('C3', 'banco', '-20.00')];
        $saldos = app(SaldoService::class);
        $total = fn () => array_reduce($contas, fn ($c, $conta) => bcadd($c, $saldos->saldoAtual($conta->fresh()), 2), '0');
        $antes = $total();

        $t1 = $this->transferenciaDireta($contas[0], $contas[1], $pastor, '33.33');
        $this->transferenciaDireta($contas[1], $contas[2], $pastor, '10.01');
        $this->transferenciaDireta($contas[2], $contas[0], $pastor, '0.02');
        $this->assertSame($antes, $total());

        $this->estornarTransferencia($pastor, $t1)->assertStatus(201);
        $this->assertSame($antes, $total());

        // Contribuição das transferências por conta soma exatamente zero.
        $contribuicao = '0';
        foreach ($contas as $conta) {
            $contribuicao = bcadd($contribuicao, bcsub($saldos->saldoAtual($conta->fresh()), (string) $conta->saldo_inicial, 2), 2);
        }
        $this->assertSame('0.00', $contribuicao);
    }

    public function test_estornada_mais_estorno_tem_efeito_liquido_zero_em_cada_conta_e_independe_do_status(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Conta A', 'banco', '100.00');
        $b = $this->conta('Conta B', 'banco', '0.00');
        $original = $this->transferenciaDireta($a, $b, $pastor, '60.00');
        $this->transferenciaDireta($b, $a, $pastor, '60.00', null, ['transferencia_estornada_id' => $original->id, 'motivo_estorno' => 'x']);

        foreach (['confirmada', 'estornada'] as $status) {
            DB::table('transferencias')->where('id', $original->id)->update(['status' => $status]);
            $this->assertSame('100.00', $this->saldoDe($a), "status $status");
            $this->assertSame('0.00', $this->saldoDe($b), "status $status");
        }
    }

    public function test_saldo_em_lote_e_igual_ao_individual_com_todos_os_movimentos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $contas = [$this->conta('L1', 'banco', '10.00'), $this->conta('L2', 'caixa', '0.00'), $this->conta('L3', 'banco', '-5.50'), $this->conta('L4', 'caixa', '1.00')];
        $this->entrada($contas[0], $this->categoria(), $pastor, '1.11');
        $this->despesaPaga($contas[1], $this->categoriaDespesa(), $pastor, ['valor' => '0.11', 'conta_id' => $contas[1]->id]);
        $this->transferenciaDireta($contas[0], $contas[1], $pastor, '3.33');
        $this->transferenciaDireta($contas[2], $contas[0], $pastor, '0.07');
        $this->ajusteDireto($contas[2], $pastor, '9.99', 'credito');
        $this->ajusteDireto($contas[1], $pastor, '0.01', 'debito');

        $service = app(SaldoService::class);
        $lote = $service->saldosAtuais($contas);

        $this->assertSame('7.85', $lote[$contas[0]->id]);   // 10 + 1,11 − 3,33 + 0,07
        $this->assertSame('3.21', $lote[$contas[1]->id]);   // 0 − 0,11 + 3,33 − 0,01
        $this->assertSame('4.42', $lote[$contas[2]->id]);   // −5,50 − 0,07 + 9,99
        $this->assertSame('1.00', $lote[$contas[3]->id]);   // sem movimentos
        foreach ($contas as $conta) {
            $this->assertSame($service->saldoAtual($conta), $lote[$conta->id]);
            $this->assertIsString($lote[$conta->id]);
        }
    }

    public function test_contas_sem_movimento_e_lote_vazio(): void
    {
        $service = app(SaldoService::class);
        $conta = $this->conta('Vazia', 'banco', '12.34');

        $this->assertSame('12.34', $service->saldoAtual($conta));
        $this->assertSame([], $service->saldosAtuais([]));
    }

    public function test_listagem_de_contas_tem_quantidade_de_consultas_constante_e_saldo_correto(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $base = $this->conta('Base', 'banco', '10000.00');
        foreach (range(1, 6) as $i) {
            $conta = $this->conta("Conta $i", 'banco', '10.00');
            $this->transferenciaDireta($base, $conta, $pastor, '5.00');
            $this->ajusteDireto($conta, $pastor, '1.00', 'credito');
        }
        $this->actingAs($pastor)->getJson('/api/v1/contas')->assertOk(); // aquece

        DB::enableQueryLog();
        $resposta = $this->actingAs($pastor)->getJson('/api/v1/contas?por_pagina=100')->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query');

        // UNION ALL: o texto da consulta menciona `transferencias` duas vezes, mas é UMA única consulta.
        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'from `transferencias`') && str_contains($q, 'union all'))->count());
        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'from `ajustes_saldo`'))->count());
        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'from `entradas`'))->count());
        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'from `despesas`'))->count());

        $porNome = collect($resposta->json('data'))->keyBy('nome');
        $this->assertSame('9970.00', $porNome['Base']['saldo_atual']);
        foreach (range(1, 6) as $i) {
            $this->assertSame('16.00', $porNome["Conta $i"]['saldo_atual']); // 10 + 5 + 1
        }
    }

    public function test_saldo_atual_continua_sem_coluna_persistida_em_qualquer_tabela_nova(): void
    {
        foreach (['transferencias', 'ajustes_saldo'] as $tabela) {
            $this->assertNotContains('saldo_atual', collect(DB::select("SHOW COLUMNS FROM $tabela"))->pluck('Field')->all());
        }
        $this->assertNotContains('saldo_atual', collect(DB::select('SHOW COLUMNS FROM contas'))->pluck('Field')->all());
    }

    // ================= estaEmUso / exclusão de conta =================

    public function test_estaemuso_considera_transferencia_como_origem_como_destino_e_como_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $service = app(ContaService::class);
        $origem = $this->conta('So origem', 'banco', '10.00');
        $destino = $this->conta('So destino', 'banco', '0.00');
        $livre = $this->conta('Livre', 'banco', '0.00');
        $this->assertFalse($service->estaEmUso($livre));

        $original = $this->transferenciaDireta($origem, $destino, $pastor, '1.00');
        $this->assertTrue($service->estaEmUso($origem));
        $this->assertTrue($service->estaEmUso($destino));
        $this->assertFalse($service->estaEmUso($livre));

        // Só a linha de estorno vinculando uma terceira conta: também é uso.
        $terceira = $this->conta('Terceira', 'banco', '0.00');
        Transferencia::create([
            'conta_origem_id' => $terceira->id, 'conta_destino_id' => $origem->id, 'valor' => '1.00', 'data_transferencia' => $this->hoje(),
            'status' => 'confirmada', 'transferencia_estornada_id' => $original->id, 'motivo_estorno' => 'x', 'criado_por' => $pastor->id,
        ]);
        $this->assertTrue($service->estaEmUso($terceira));
    }

    public function test_estaemuso_considera_ajuste(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $service = app(ContaService::class);
        $conta = $this->conta('So ajuste', 'banco', '0.00');
        $this->assertFalse($service->estaEmUso($conta));

        $this->ajusteDireto($conta, $pastor, '1.00');
        $this->assertTrue($service->estaEmUso($conta));
    }

    public function test_conta_usada_somente_por_transferencia_nao_pode_ser_excluida_mas_pode_ser_inativada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Com transferencia', 'banco', '100.00');
        $b = $this->conta('Outra', 'banco', '0.00');
        $livre = $this->conta('Livre', 'banco', '0.00');
        $this->transferir($pastor, $a, $b, ['valor' => '10.00'])->assertStatus(201);

        foreach ([$a, $b] as $emUso) {
            $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$emUso->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
            $this->assertNull($emUso->fresh()->deleted_at);
        }
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$b->id}", ['ativa' => false])->assertOk()->assertJsonPath('data.ativa', false);
        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$livre->id}")->assertOk();
        $this->assertSoftDeleted('contas', ['id' => $livre->id]);
    }

    public function test_conta_usada_somente_por_ajuste_nao_pode_ser_excluida(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Com ajuste', 'banco', '0.00');
        $this->ajustar($pastor, $conta, ['valor' => '1.00'])->assertStatus(201);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
        $this->assertNull(Conta::find($conta->id)->deleted_at);
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])->assertOk();
    }

    public function test_conta_com_transferencia_estornada_continua_em_uso(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('A estornada', 'banco', '100.00');
        $b = $this->conta('B estornada', 'banco', '0.00');
        $id = $this->transferir($pastor, $a, $b, ['valor' => '10.00'])->json('data.id');
        $this->estornarTransferencia($pastor, $id)->assertStatus(201);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$a->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$b->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
    }

    // ================= ordem de locks =================

    public function test_transferencias_em_direcoes_opostas_travam_as_contas_sempre_em_ordem_crescente_de_id(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Menor id', 'banco', '1000.00');
        $b = $this->conta('Maior id', 'banco', '1000.00');
        $this->assertLessThan($b->id, $a->id);

        $travas = function (callable $acao) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $acao();
            $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `contas`') && str_contains($q['query'], 'for update'))->values();
            DB::disableQueryLog();

            return $consultas;
        };

        $ab = $travas(fn () => $this->transferir($tes, $a, $b, ['valor' => '1.00'])->assertStatus(201));
        $ba = $travas(fn () => $this->transferir($tes, $b, $a, ['valor' => '1.00'])->assertStatus(201));

        foreach ([$ab, $ba] as $consultas) {
            $this->assertCount(1, $consultas, 'as duas contas são travadas em UMA consulta ordenada');
            $this->assertStringContainsString('order by `id` asc for update', $consultas[0]['query']);
            $this->assertSame([$a->id, $b->id], $consultas[0]['bindings'], 'bindings sempre em ordem crescente: min(id), max(id)');
        }
        // A→B e B→A geram exatamente a mesma consulta de trava.
        $this->assertSame($ab[0]['query'], $ba[0]['query']);
        $this->assertSame($ab[0]['bindings'], $ba[0]['bindings']);
    }

    public function test_estorno_trava_a_transferencia_primeiro_e_depois_as_contas_em_ordem_e_so_entao_le_o_saldo(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Menor', 'banco', '1000.00');
        $b = $this->conta('Maior', 'banco', '0.00');
        $original = $this->transferenciaDireta($b, $a, $pastor, '10.00'); // origem = maior id
        $this->actingAs($pastor);

        DB::enableQueryLog();
        $this->postJson("/api/v1/transferencias/{$original->id}/estornar", ['justificativa' => 'teste'])->assertStatus(201);
        $q = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();

        $posDoc = $q->search(fn ($s) => str_contains($s, 'from `transferencias`') && str_contains($s, 'for update'));
        $posContas = $q->search(fn ($s) => str_contains($s, 'from `contas`') && str_contains($s, 'order by `id` asc for update'));
        $posPeriodo = $q->search(fn ($s) => str_contains($s, 'from `periodos_financeiros`'));
        $posSaldo = $q->search(fn ($s) => stripos($s, 'sum(valor)') !== false);

        $this->assertNotFalse($posDoc);
        $this->assertNotFalse($posContas);
        $this->assertLessThan($posContas, $posDoc, 'documento antes das contas');
        $this->assertLessThan($posPeriodo, $posContas, 'contas antes da leitura de período');
        $this->assertLessThan($posSaldo, $posContas, 'contas antes do cálculo de saldo');
    }

    public function test_criacao_trava_as_contas_antes_da_consulta_de_idempotencia_e_do_saldo(): void
    {
        $tes = $this->como(PerfilSlug::Tesoureiro);
        $a = $this->conta('Menor', 'banco', '1000.00');
        $b = $this->conta('Maior', 'banco', '0.00');
        $this->actingAs($tes);

        foreach ([['/api/v1/transferencias', $this->corpoTransferencia($b, $a, ['confirmar_saldo_negativo' => true]), 'from `transferencias`'], ['/api/v1/ajustes', $this->corpoAjuste($a, ['sentido' => 'debito']), 'from `ajustes_saldo`']] as [$url, $corpo, $tabelaChave]) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->postJson($url, $corpo, ['Idempotency-Key' => 'ordem-' . md5($url)])->assertStatus(201);
            $q = collect(DB::getQueryLog())->pluck('query')->values();
            DB::disableQueryLog();

            $posTrava = $q->search(fn ($s) => str_contains($s, 'from `contas`') && str_contains($s, 'for update'));
            $posChave = $q->search(fn ($s) => str_contains($s, $tabelaChave) && str_contains($s, 'chave_idempotencia'));
            $posPeriodo = $q->search(fn ($s) => str_contains($s, 'from `periodos_financeiros`'));
            $posSaldo = $q->search(fn ($s) => stripos($s, 'sum(') !== false);

            $this->assertNotFalse($posTrava, $url);
            $this->assertNotFalse($posChave, $url);
            $this->assertLessThan($posChave, $posTrava, "$url: a trava das contas vem ANTES da consulta de idempotência");
            $this->assertLessThan($posPeriodo, $posTrava, "$url: trava antes do período");
            $this->assertLessThan($posSaldo, $posTrava, "$url: trava antes do saldo");
        }
    }
}
