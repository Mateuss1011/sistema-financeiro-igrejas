<?php

namespace Tests\Feature\Relatorios;

use App\Support\Exportacao\CsvRelatorioWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQUISITO CENTRAL DA FASE 12 (plano §16): para o mesmo ano_mes, Dashboard, Relatórios (tela/API) e Exportações
 * mostram números IDÊNTICOS. Todos vêm de IndicadoresFinanceirosService; estes testes provam isso ponta a ponta.
 */
class EquivalenciaComDashboardTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    /** Compara Dashboard x todos os relatórios/exportações do mês para o ator. */
    private function conferirEquivalencia(object $ator, string $mes, bool $completo): void
    {
        $dash = $this->dashboardApi($ator, ['ano_mes' => $mes])->assertOk()->json('data');
        $q = ['ano_mes' => $mes];

        // RESUMO: o bloco de indicadores é EXATAMENTE o do Dashboard (mesma estrutura, mesmos valores).
        $resumo = $this->relatorioApi($ator, 'resumo', $q)->assertOk();
        $this->assertSame($dash, $resumo->json('meta.indicadores'), 'resumo.indicadores !== dashboard');
        $linhas = collect($resumo->json('data'))->pluck('valor', 'indicador');
        $this->assertSame($dash['entradas']['total'], $linhas['Total de entradas']);
        $this->assertSame($dash['despesas_pagas']['total'], $linhas['Total de despesas pagas']);
        $this->assertSame($dash['despesas_pendentes']['valor'], $linhas['Total de despesas pendentes']);
        $this->assertSame($dash['despesas_pendentes']['quantidade'], $linhas['Quantidade de despesas pendentes']);

        // ENTRADAS
        $entradas = $this->relatorioApi($ator, 'entradas', $q)->assertOk();
        $this->assertSame($dash['entradas']['total'], $this->totalDe($entradas, 'total'));

        // DESPESAS
        $despesas = $this->relatorioApi($ator, 'despesas', $q)->assertOk();
        $this->assertSame($dash['despesas_pagas']['total'], $this->totalDe($despesas, 'pagas_total'));
        $this->assertSame($dash['despesas_pendentes']['quantidade'], $this->totalDe($despesas, 'pendentes_quantidade'));
        $this->assertSame($dash['despesas_pendentes']['valor'], $this->totalDe($despesas, 'pendentes_total'));

        // MOVIMENTAÇÃO
        $mov = $this->relatorioApi($ator, 'movimentacoes', $q)->assertOk();
        $this->assertSame($dash['entradas']['total'], $this->totalDe($mov, 'total_entradas'));
        $this->assertSame($dash['despesas_pagas']['total'], $this->totalDe($mov, 'total_despesas_pagas'));

        if ($completo) {
            // SALDOS: total e cada conta iguais ao Dashboard (ambos via SaldoService).
            $saldos = $this->relatorioApi($ator, 'saldos', $q)->assertOk();
            $this->assertSame($dash['saldo']['total'], $this->totalDe($saldos, 'total'));
            $this->assertSame($dash['saldo']['total'], $linhas['Saldo total']);
            $porId = collect($saldos->json('data'))->pluck('saldo', 'id');
            foreach ($dash['saldo']['contas'] as $conta) {
                $this->assertSame($conta['saldo'], $porId[$conta['id']], 'saldo da conta ' . $conta['nome']);
            }
            $this->assertSame($dash['periodo']['status'] === 'fechado' ? 'Fechado' : 'Aberto', $linhas['Situação do período']);
        } else {
            $this->assertArrayNotHasKey('saldo', $dash);
            $this->assertArrayNotHasKey('periodo', $dash);
            $this->assertArrayNotHasKey('Saldo total', $linhas->all());
        }
    }

    public function test_mes_passado_todos_os_perfis_dashboard_igual_relatorios(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(2));

        foreach ([$m->pastor, $m->tesoureiro, $this->como(\App\Enums\PerfilSlug::Administrador)] as $ator) {
            $this->conferirEquivalencia($ator, $m->mes, true);
        }
        $this->conferirEquivalencia($m->aux1, $m->mes, false);
        $this->conferirEquivalencia($m->aux2, $m->mes, false);
    }

    public function test_mes_corrente_dashboard_igual_relatorios(): void
    {
        $m = (object) $this->massaDoMes($this->mesAtual());

        $this->conferirEquivalencia($m->pastor, $m->mes, true);
        $this->conferirEquivalencia($m->aux1, $m->mes, false);
        // Sem ano_mes (mês corrente implícito) também bate.
        $this->assertSame(
            $this->dashboardApi($m->pastor)->json('data'),
            $this->relatorioApi($m->pastor, 'resumo')->json('meta.indicadores')
        );
    }

    public function test_periodo_fechado_e_refletido_igualmente(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(3));
        $this->fecharApi($m->pastor, $m->mes)->assertOk();

        $this->conferirEquivalencia($m->pastor, $m->mes, true);
        $this->assertSame('fechado', $this->dashboardApi($m->pastor, ['ano_mes' => $m->mes])->json('data.periodo.status'));
    }

    public function test_valores_esperados_conferidos_a_mao_para_os_tres_lados(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(2));
        $dash = $this->dashboardApi($m->pastor, ['ano_mes' => $m->mes])->assertOk();

        $dash->assertJsonPath('data.entradas.total', '390.50')
            ->assertJsonPath('data.despesas_pagas.total', '75.00')
            ->assertJsonPath('data.despesas_pendentes.quantidade', 2)
            ->assertJsonPath('data.despesas_pendentes.valor', '32.34');
        $this->assertSame('390.50', $this->totalDe($this->relatorioApi($m->pastor, 'entradas', ['ano_mes' => $m->mes]), 'total'));
        $this->assertSame('75.00', $this->totalDe($this->relatorioApi($m->pastor, 'despesas', ['ano_mes' => $m->mes]), 'pagas_total'));
        $this->assertSame('390.50', $this->totalDe($this->relatorioApi($m->pastor, 'movimentacoes', ['ano_mes' => $m->mes]), 'total_entradas'));
    }

    public function test_exportacao_traz_os_mesmos_totais_do_dashboard_em_csv(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(2));
        $dash = $this->dashboardApi($m->pastor, ['ano_mes' => $m->mes])->json('data');
        $br = fn (string $v) => str_replace('.', ',', $v);

        $entradas = $this->lerCsv($this->exportarApi($m->pastor, 'entradas', 'csv', ['ano_mes' => $m->mes])->assertOk()->getContent());
        $this->assertSame($br($dash['entradas']['total']), $entradas['totais']['Total de entradas']);

        $despesas = $this->lerCsv($this->exportarApi($m->pastor, 'despesas', 'csv', ['ano_mes' => $m->mes])->assertOk()->getContent());
        $this->assertSame($br($dash['despesas_pagas']['total']), $despesas['totais']['Despesas pagas — total']);
        $this->assertSame((string) $dash['despesas_pendentes']['quantidade'], $despesas['totais']['Despesas pendentes — quantidade']);
        $this->assertSame($br($dash['despesas_pendentes']['valor']), $despesas['totais']['Despesas pendentes — total']);

        $saldos = $this->lerCsv($this->exportarApi($m->pastor, 'saldos', 'csv', ['ano_mes' => $m->mes])->assertOk()->getContent());
        $this->assertSame($br($dash['saldo']['total']), $saldos['totais']['Saldo total']);

        $resumo = $this->lerCsv($this->exportarApi($m->pastor, 'resumo', 'csv', ['ano_mes' => $m->mes])->assertOk()->getContent());
        $porIndicador = collect($resumo['linhas'])->pluck(1, 0);
        $this->assertSame($br($dash['entradas']['total']), $porIndicador['Total de entradas']);
        $this->assertSame($br($dash['saldo']['total']), $porIndicador['Saldo total']);
    }

    public function test_exportacao_traz_os_mesmos_totais_do_dashboard_em_xlsx(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(2));
        $dash = $this->dashboardApi($m->pastor, ['ano_mes' => $m->mes])->json('data');

        $entradas = $this->lerXlsx($this->exportarApi($m->pastor, 'entradas', 'xlsx', ['ano_mes' => $m->mes])->assertOk()->getContent());
        $this->assertEqualsWithDelta((float) $dash['entradas']['total'], $entradas['totais']['Total de entradas']['v'], 0.0001);

        $despesas = $this->lerXlsx($this->exportarApi($m->pastor, 'despesas', 'xlsx', ['ano_mes' => $m->mes])->assertOk()->getContent());
        $this->assertEqualsWithDelta((float) $dash['despesas_pagas']['total'], $despesas['totais']['Despesas pagas — total']['v'], 0.0001);
        $this->assertSame($dash['despesas_pendentes']['quantidade'], (int) $despesas['totais']['Despesas pendentes — quantidade']['v']);
    }

    public function test_exportacao_do_auxiliar_e_negada_mas_a_tela_dele_bate_com_o_dashboard_parcial(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(2));

        $this->exportarApi($m->aux1, 'entradas', 'csv', ['ano_mes' => $m->mes])->assertStatus(403);
        $this->conferirEquivalencia($m->aux1, $m->mes, false);
        $this->assertSame('100.00', $this->dashboardApi($m->aux1, ['ano_mes' => $m->mes])->json('data.entradas.total'));
    }

    public function test_csv_e_xlsx_do_mesmo_relatorio_tem_os_mesmos_dados(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(2));

        foreach (self::RELATORIOS as $relatorio) {
            $csv = $this->lerCsv($this->exportarApi($m->pastor, $relatorio, 'csv', ['ano_mes' => $m->mes])->assertOk()->getContent());
            $xlsx = $this->lerXlsx($this->exportarApi($m->pastor, $relatorio, 'xlsx', ['ano_mes' => $m->mes])->assertOk()->getContent());

            $this->assertSame($csv['cabecalho'], $xlsx['cabecalho'], "$relatorio: cabeçalho");
            $this->assertCount(count($csv['linhas']), $xlsx['linhas'], "$relatorio: nº de linhas");
            $this->assertSame(array_keys($csv['totais']), array_keys($xlsx['totais']), "$relatorio: rótulos de totais");
        }
        // usa o helper para não ficar "não usado" e garantir que o formatador do CSV é o mesmo dos testes
        $this->assertSame('1,50', CsvRelatorioWriter::celula('1.50', 'dinheiro'));
    }
}
