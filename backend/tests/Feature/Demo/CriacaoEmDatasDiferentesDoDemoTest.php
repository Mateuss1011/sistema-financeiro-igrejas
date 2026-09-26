<?php

namespace Tests\Feature\Demo;

use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\User;
use App\Services\PeriodoFinanceiroService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A demonstração pode ser montada em qualquer dia: nunca gera data futura nem data inexistente, e os totais não dependem
 * do dia (dia 1, fim de mês, virada de ano, fevereiro).
 */
class CriacaoEmDatasDiferentesDoDemoTest extends TestCase
{
    use RefreshDatabase, CenarioDemo;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function datas(): array
    {
        return [
            'dia 1 do mês' => ['2026-04-01 09:00:00'],
            'último dia do mês' => ['2026-05-31 20:00:00'],
            'virada de ano (janeiro)' => ['2027-01-02 12:00:00'],
            'depois de fevereiro curto' => ['2026-03-31 23:00:00'],
            'meio do mês' => ['2026-09-17 10:00:00'],
        ];
    }

    #[DataProvider('datas')]
    public function test_monta_sem_data_futura_e_com_os_mesmos_totais(string $agora): void
    {
        Carbon::setTestNow(Carbon::parse($agora, 'America/Sao_Paulo'));
        $this->comCategoriasPadrao();

        [$codigo, $saida] = $this->rodarDemo();

        $this->assertSame(0, $codigo, $saida);
        $hoje = Carbon::now('America/Sao_Paulo')->toDateString();
        $ids = User::query()->where('email', 'like', '%@sfg.demo')->pluck('id');

        $this->assertSame(0, Entrada::query()->whereIn('criado_por', $ids)->where('data_competencia', '>', $hoje)->count(), 'entrada com data futura');
        $this->assertSame(0, Despesa::query()->whereIn('criado_por', $ids)->where(fn ($q) => $q->where('data_competencia', '>', $hoje)->orWhere('data_pagamento', '>', $hoje))->count(), 'despesa com data futura');
        $this->assertSame(0, \App\Models\Transferencia::query()->where('data_transferencia', '>', $hoje)->count());

        $this->assertTrue(app(PeriodoFinanceiroService::class)->estaFechado($this->mesAnterior()));
        $this->assertFalse(app(PeriodoFinanceiroService::class)->estaFechado($this->mesAtual()));

        $this->app['auth']->forgetGuards();
        $dash = $this->actingAs($this->usuarioDemo('pastor'))->getJson('/api/v1/dashboard?ano_mes=' . $this->mesAtual())->assertOk()->json('data');
        $this->assertSame('4616.00', $dash['entradas']['total']);
        $this->assertSame('10663.00', $dash['saldo']['total']);

        // E continua idempotente naquela data.
        $antes = $this->fotoDoBanco();
        $this->rodarDemo();
        $this->assertSame($antes, $this->fotoDoBanco());
    }

    public function test_reexecutar_em_outro_dia_nao_duplica_nem_falha_por_mudanca_de_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00', 'America/Sao_Paulo'));
        $this->comCategoriasPadrao();
        $this->rodarDemo();
        $antes = $this->fotoDoBanco();

        Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00', 'America/Sao_Paulo'));
        [$codigo] = $this->rodarDemo();

        $this->assertSame(0, $codigo);
        $this->assertSame($antes, $this->fotoDoBanco());
    }
}
