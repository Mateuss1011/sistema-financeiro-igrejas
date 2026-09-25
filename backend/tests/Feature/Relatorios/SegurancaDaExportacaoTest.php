<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use App\Models\User;
use App\Support\Exportacao\TextoSeguro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proteção contra CSV/Excel Formula Injection: todo TEXTO que comece com = + - @ (ou TAB/CR) sai com apóstrofo e nunca
 * como fórmula; números/dinheiro (inclusive negativos legítimos) ficam intactos.
 */
class SegurancaDaExportacaoTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    private const HOSTIS = [
        'descricao' => '=HYPERLINK("http://evil.example","clique")',
        'categoria' => '+cmd|calc',
        'conta' => '@conta',
        'usuario' => '=Maria',
        'fornecedor' => '-2+3',
        'descricao_despesa' => '@SUM(A1:A9)',
    ];

    private string $mes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mes = $this->mesPassado(2);
    }

    /** Cenário com texto hostil em todos os campos textuais controlados pelo usuário. */
    private function cenarioHostil(): User
    {
        $autor = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create(['name' => self::HOSTIS['usuario']]);
        $conta = $this->conta(self::HOSTIS['conta'], 'banco', '500.00');
        $catE = $this->categoria(self::HOSTIS['categoria']);
        $catD = $this->categoriaDespesa('=Categoria despesa');

        $this->entrada($conta, $catE, $autor, '10.00', $this->mes . '-05', ['descricao' => self::HOSTIS['descricao']]);
        $this->despesaPaga($conta, $catD, $autor, ['valor' => '20.00', 'data_competencia' => $this->mes . '-06', 'data_pagamento' => $this->mes . '-06', 'fornecedor_nome' => self::HOSTIS['fornecedor'], 'descricao' => self::HOSTIS['descricao_despesa']]);
        $this->despesaPendente($catD, $autor, ['valor' => '5.00', 'data_competencia' => $this->mes . '-07', 'fornecedor_nome' => "\t=cmd", 'descricao' => "\r=cmd"]);
        $this->transferenciaDireta($conta, $this->conta('+Destino', 'caixa', '0.00'), $autor, '15.00', $this->mes . '-08', ['descricao' => '-transf']);
        $this->ajusteDireto($conta, $autor, '3.00', 'credito', ['data_ajuste' => $this->mes . '-09', 'justificativa' => '=ajuste']);

        return $this->como(PerfilSlug::Pastor);
    }

    // ================================================================ unidade

    public function test_texto_seguro_neutraliza_os_prefixos_perigosos(): void
    {
        foreach (['=1+1', '+1', '-2+3', '@SUM(A1)', "\t=cmd", "\r=cmd", '=HYPERLINK("x")', '-cmd|calc'] as $perigoso) {
            $this->assertSame("'" . $perigoso, TextoSeguro::neutralizar($perigoso), var_export($perigoso, true));
        }
    }

    public function test_texto_seguro_nao_altera_texto_normal(): void
    {
        foreach (['Dízimo de domingo', '', 'Oferta 5-1', 'a=b', 'x+y', 'email@dominio.com', ' =espaco antes', "'já com apóstrofo", '10,50', 'Banco A'] as $normal) {
            $this->assertSame($normal, TextoSeguro::neutralizar($normal), var_export($normal, true));
        }
        $this->assertSame('123', TextoSeguro::neutralizar(123));
    }

    // ================================================================ CSV

    public function test_csv_neutraliza_todo_texto_hostil(): void
    {
        $pastor = $this->cenarioHostil();

        $entradas = $this->lerCsv($this->exportarApi($pastor, 'entradas', 'csv', ['ano_mes' => $this->mes])->assertOk()->getContent());
        $linha = $entradas['linhas'][0];
        $this->assertSame("'" . self::HOSTIS['categoria'], $linha[2]);
        $this->assertSame("'" . self::HOSTIS['conta'], $linha[3]);
        $this->assertSame("'" . self::HOSTIS['descricao'], $linha[5]);
        $this->assertSame("'" . self::HOSTIS['usuario'], $linha[8]);
        $this->assertSame('10,00', $linha[6], 'o valor financeiro não é tocado');

        $despesas = $this->lerCsv($this->exportarApi($pastor, 'despesas', 'csv', ['ano_mes' => $this->mes, 'status' => 'paga'])->assertOk()->getContent());
        $this->assertSame("'" . self::HOSTIS['fornecedor'], $despesas['linhas'][0][5]);
        $this->assertSame("'" . self::HOSTIS['descricao_despesa'], $despesas['linhas'][0][6]);
        $this->assertSame("'=Categoria despesa", $despesas['linhas'][0][3]);

        $pendente = $this->lerCsv($this->exportarApi($pastor, 'despesas', 'csv', ['ano_mes' => $this->mes, 'status' => 'pendente'])->assertOk()->getContent());
        $this->assertSame("'\t=cmd", $pendente['linhas'][0][5]);
        $this->assertSame("'\r=cmd", $pendente['linhas'][0][6]);
    }

    public function test_csv_movimentacao_neutraliza_descricao_de_transferencia_ajuste_e_nomes_de_conta(): void
    {
        $pastor = $this->cenarioHostil();

        $csv = $this->lerCsv($this->exportarApi($pastor, 'movimentacoes', 'csv', ['ano_mes' => $this->mes])->assertOk()->getContent());

        $ajuste = collect($csv['linhas'])->first(fn ($l) => str_starts_with($l[1], 'Ajuste'));
        $this->assertSame("'=ajuste", $ajuste[3]);
        $this->assertSame("'" . self::HOSTIS['conta'], $ajuste[2]);
        $transferencia = collect($csv['linhas'])->first(fn ($l) => $l[1] === 'Transferência recebida');
        $this->assertSame("'+Destino", $transferencia[2]);
    }

    public function test_propriedade_nenhuma_celula_de_texto_de_nenhum_relatorio_csv_comeca_com_caractere_de_formula(): void
    {
        $pastor = $this->cenarioHostil();

        foreach (self::RELATORIOS as $relatorio) {
            $json = $this->relatorioApi($pastor, $relatorio, ['ano_mes' => $this->mes, 'por_pagina' => 100])->assertOk();
            $csv = $this->lerCsv($this->exportarApi($pastor, $relatorio, 'csv', ['ano_mes' => $this->mes])->assertOk()->getContent());

            $this->assertNotEmpty($csv['linhas']);
            foreach ($csv['linhas'] as $i => $linha) {
                foreach ($json->json('meta.colunas') as $c => $coluna) {
                    $tipo = $json->json("data.$i._tipos.{$coluna['chave']}") ?? $coluna['tipo'];
                    if ($tipo === 'texto') {
                        $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $linha[$c], "$relatorio linha $i coluna {$coluna['chave']}");
                    }
                }
            }
            foreach ($csv['cabecalho'] as $cabecalho) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cabecalho);
            }
        }
    }

    // ================================================================ XLSX

    public function test_xlsx_grava_texto_hostil_como_string_com_apostrofo_e_nunca_como_formula(): void
    {
        $pastor = $this->cenarioHostil();

        $x = $this->lerXlsx($this->exportarApi($pastor, 'entradas', 'xlsx', ['ano_mes' => $this->mes])->assertOk()->getContent());
        $linha = $x['linhas'][0];

        foreach ([2 => self::HOSTIS['categoria'], 3 => self::HOSTIS['conta'], 5 => self::HOSTIS['descricao'], 8 => self::HOSTIS['usuario']] as $coluna => $original) {
            $this->assertSame('s', $linha[$coluna]['t'], "coluna $coluna deve ser string (não fórmula)");
            $this->assertSame("'" . $original, $linha[$coluna]['v']);
        }
    }

    public function test_propriedade_nenhuma_celula_de_nenhuma_planilha_e_formula_e_nenhum_texto_comeca_com_caractere_de_formula(): void
    {
        $pastor = $this->cenarioHostil();

        foreach (self::RELATORIOS as $relatorio) {
            $x = $this->lerXlsx($this->exportarApi($pastor, $relatorio, 'xlsx', ['ano_mes' => $this->mes])->assertOk()->getContent());
            $todas = array_merge($x['linhas'], array_map(fn ($c) => [$c], $x['totais']), [$x['cabecalho'] ? array_map(fn ($v) => ['v' => $v, 't' => 's', 'f' => 'General'], $x['cabecalho']) : []]);

            foreach ($todas as $linha) {
                foreach ($linha as $celula) {
                    $this->assertNotSame('f', $celula['t'], "$relatorio: célula de fórmula encontrada");
                    if ($celula['t'] === 's' && is_string($celula['v'])) {
                        $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $celula['v'], "$relatorio: texto perigoso '{$celula['v']}'");
                    }
                }
            }
        }
    }

    // ================================================================ números negativos legítimos

    public function test_valores_monetarios_negativos_nao_sao_neutralizados_em_nenhum_formato(): void
    {
        $this->conta('Banco no vermelho', 'banco', '-150.75');
        $pastor = $this->como(PerfilSlug::Pastor);

        $csv = $this->lerCsv($this->exportarApi($pastor, 'saldos', 'csv', ['ano_mes' => $this->mesAtual()])->assertOk()->getContent());
        $this->assertSame('-150,75', collect($csv['linhas'])->first(fn ($l) => $l[1] === 'Banco no vermelho')[4]);
        $this->assertSame('-150,75', $csv['totais']['Saldo total']);

        $x = $this->lerXlsx($this->exportarApi($pastor, 'saldos', 'xlsx', ['ano_mes' => $this->mesAtual()])->assertOk()->getContent());
        $celula = collect($x['linhas'])->first(fn ($l) => $l[1]['v'] === 'Banco no vermelho')[4];
        $this->assertSame('n', $celula['t']);
        $this->assertEqualsWithDelta(-150.75, $celula['v'], 0.0001);
        $this->assertEqualsWithDelta(-150.75, $x['totais']['Saldo total']['v'], 0.0001);
    }

    public function test_a_neutralizacao_vale_so_para_o_arquivo_a_consulta_json_devolve_o_texto_original(): void
    {
        $pastor = $this->cenarioHostil();

        $json = $this->relatorioApi($pastor, 'entradas', ['ano_mes' => $this->mes])->assertOk();

        $this->assertSame(self::HOSTIS['descricao'], $json->json('data.0.descricao'));
        $this->assertSame(self::HOSTIS['categoria'], $json->json('data.0.categoria'));
    }
}
