<?php

namespace Tests\Feature\Periodos;

use App\Enums\PerfilSlug;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `SaldoService::saldoAte()` — saldo histórico dinâmico, sem persistir nada (D5/D6). */
class SaldoHistoricoTest extends TestCase
{
    use RefreshDatabase, CenarioPeriodos;

    private function saldos(): SaldoService
    {
        return app(SaldoService::class);
    }

    public function test_entrada_antes_da_data_conta_entrada_depois_nao_conta(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Sh1', 'banco', '100.00');
        $categoria = $this->categoria('Dízimo Sh1');
        $this->entrada($conta, $categoria, $pastor, '30.00', '2026-03-10');
        $this->entrada($conta, $categoria, $pastor, '20.00', '2026-03-20');

        $this->assertSame('130.00', $this->saldos()->saldoAte($conta, '2026-03-10'));
        $this->assertSame('150.00', $this->saldos()->saldoAte($conta, '2026-03-20'));
        $this->assertSame('100.00', $this->saldos()->saldoAte($conta, '2026-03-09'));
    }

    public function test_despesa_paga_usa_data_de_pagamento_nao_a_competencia(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Sh2', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa('Energia Sh2');
        // Competência em fevereiro, paga em abril: o corte deve considerar ABRIL, não fevereiro.
        $this->despesaPaga($conta, $categoria, $pastor, ['data_competencia' => '2026-02-05', 'data_pagamento' => '2026-04-15', 'valor' => '100.00']);

        $this->assertSame('1000.00', $this->saldos()->saldoAte($conta, '2026-02-28'), 'competência não move o saldo');
        $this->assertSame('1000.00', $this->saldos()->saldoAte($conta, '2026-04-14'), 'um dia antes do pagamento ainda não desconta');
        $this->assertSame('900.00', $this->saldos()->saldoAte($conta, '2026-04-15'), 'na data do pagamento já desconta');
    }

    public function test_despesa_pendente_e_cancelada_nunca_afetam_o_saldo_historico(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Sh3', 'banco', '500.00');
        $categoria = $this->categoriaDespesa('Energia Sh3');
        $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-03-05']);
        $this->despesaCancelada($categoria, $pastor, ['data_competencia' => '2026-03-06']);

        $this->assertSame('500.00', $this->saldos()->saldoAte($conta, '2026-12-31'));
    }

    public function test_despesa_estornada_original_e_estorno_se_anulam(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Sh4', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa('Energia Sh4');
        // despesaEstornada grava original+estorno com data_pagamento = hoje().
        $this->despesaEstornada($conta, $categoria, $pastor);

        $this->assertSame('1000.00', $this->saldos()->saldoAte($conta, $this->hoje()));
        $this->assertSame('1000.00', $this->saldos()->saldoAte($conta, now()->addYears(2)->toDateString()));
    }

    public function test_transferencia_move_saldo_na_data_de_corte(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Banco Sh5', 'banco', '1000.00');
        $destino = $this->conta('Caixa Sh5', 'caixa', '0.00');
        $this->transferenciaDireta($origem, $destino, $pastor, '200.00', '2026-05-10');

        $this->assertSame('1000.00', $this->saldos()->saldoAte($origem, '2026-05-09'));
        $this->assertSame('0.00', $this->saldos()->saldoAte($destino, '2026-05-09'));
        $this->assertSame('800.00', $this->saldos()->saldoAte($origem, '2026-05-10'));
        $this->assertSame('200.00', $this->saldos()->saldoAte($destino, '2026-05-10'));
    }

    public function test_ajuste_move_saldo_na_data_de_corte_e_respeita_o_sentido(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Sh6', 'banco', '100.00');
        $this->ajusteDireto($conta, $pastor, '30.00', 'credito', ['data_ajuste' => '2026-06-05']);
        $this->ajusteDireto($conta, $pastor, '10.00', 'debito', ['data_ajuste' => '2026-06-15']);

        $this->assertSame('100.00', $this->saldos()->saldoAte($conta, '2026-06-04'));
        $this->assertSame('130.00', $this->saldos()->saldoAte($conta, '2026-06-05'));
        $this->assertSame('120.00', $this->saldos()->saldoAte($conta, '2026-06-15'));
    }

    public function test_multiplas_contas_em_lote_bate_com_o_calculo_individual(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco Sh7 A', 'banco', '500.00');
        $b = $this->conta('Banco Sh7 B', 'banco', '300.00');
        $categoria = $this->categoria('Dízimo Sh7');
        $this->entrada($a, $categoria, $pastor, '50.00', '2026-07-10');
        $this->transferenciaDireta($a, $b, $pastor, '100.00', '2026-07-15');

        $emLote = $this->saldos()->saldosAte([$a, $b], '2026-07-20');
        $this->assertSame($this->saldos()->saldoAte($a->fresh(), '2026-07-20'), $emLote[$a->id]);
        $this->assertSame($this->saldos()->saldoAte($b->fresh(), '2026-07-20'), $emLote[$b->id]);
        $this->assertSame('450.00', $emLote[$a->id]);
        $this->assertSame('400.00', $emLote[$b->id]);
    }

    public function test_saldo_historico_pode_ficar_negativo_em_conta_bancaria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $banco = $this->conta('Banco Sh8', 'banco', '10.00');
        $this->ajusteDireto($banco, $pastor, '50.00', 'debito', ['data_ajuste' => '2026-08-05']);

        $this->assertSame('-40.00', $this->saldos()->saldoAte($banco, '2026-08-05'));
    }

    public function test_saldo_historico_com_casas_decimais(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Sh9', 'banco', '10.07');
        $categoria = $this->categoria('Dízimo Sh9');
        $this->entrada($conta, $categoria, $pastor, '1.11', '2026-09-05');
        $this->ajusteDireto($conta, $pastor, '3.33', 'debito', ['data_ajuste' => '2026-09-06']);
        $this->ajusteDireto($conta, $pastor, '0.07', 'credito', ['data_ajuste' => '2026-09-07']);

        $this->assertSame('7.92', $this->saldos()->saldoAte($conta, '2026-09-07'));
    }

    public function test_saldo_ate_hoje_e_igual_ao_saldo_atual(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('Banco Sh10 A', 'banco', '1000.00');
        $b = $this->conta('Caixa Sh10 B', 'caixa', '50.00');
        $categoriaEntrada = $this->categoria('Dízimo Sh10');
        $categoriaDespesa = $this->categoriaDespesa('Energia Sh10');

        $this->entrada($a, $categoriaEntrada, $pastor, '25.00', $this->hoje());
        $this->despesaPaga($a, $categoriaDespesa, $pastor, ['data_competencia' => $this->hoje(), 'data_pagamento' => $this->hoje(), 'valor' => '15.00']);
        $this->transferenciaDireta($a, $b, $pastor, '40.00', $this->hoje());
        $this->ajusteDireto($b, $pastor, '5.00', 'credito', ['data_ajuste' => $this->hoje()]);

        foreach ([$a, $b] as $conta) {
            $conta->refresh();
            $this->assertSame($this->saldos()->saldoAtual($conta), $this->saldos()->saldoAte($conta, $this->hoje()));
        }
    }

    public function test_conta_sem_nenhum_movimento_no_periodo_retorna_o_saldo_inicial(): void
    {
        $conta = $this->conta('Banco Sh11', 'banco', '77.00');

        $this->assertSame('77.00', $this->saldos()->saldoAte($conta, '2020-01-01'));
        $this->assertSame('77.00', $this->saldos()->saldoAte($conta, now()->addYears(5)->toDateString()));
    }

    public function test_lote_vazio_retorna_array_vazio(): void
    {
        $this->assertSame([], $this->saldos()->saldosAte([], '2026-01-01'));
    }
}
