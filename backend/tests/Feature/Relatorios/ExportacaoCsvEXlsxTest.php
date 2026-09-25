<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use App\Models\Entrada;
use App\Support\Exportacao\CsvRelatorioWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

/** Formato do arquivo CSV (UTF-8+BOM, ';', CRLF, vírgula decimal, datas BR) e do XLSX (células tipadas), com nomes de arquivo. */
class ExportacaoCsvEXlsxTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    private object $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = (object) $this->massaDoMes($this->mesPassado(2));
    }

    private function csv(string $relatorio, array $q = [])
    {
        return $this->exportarApi($this->m->pastor, $relatorio, 'csv', ['ano_mes' => $this->m->mes] + $q)->assertOk();
    }

    private function xlsx(string $relatorio, array $q = [])
    {
        return $this->exportarApi($this->m->pastor, $relatorio, 'xlsx', ['ano_mes' => $this->m->mes] + $q)->assertOk();
    }

    // ================================================================ CSV

    public function test_csv_cabecalhos_http_nome_do_arquivo_e_tipo(): void
    {
        foreach (self::RELATORIOS as $relatorio) {
            $r = $this->csv($relatorio);

            $r->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $r->assertHeader('Content-Disposition', 'attachment; filename="sfg-relatorio-' . $relatorio . '-' . $this->m->mes . '.csv"');
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            $r->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_csv_e_utf8_com_bom_separador_ponto_e_virgula_e_quebra_crlf(): void
    {
        $conteudo = $this->csv('entradas', ['ordenar' => 'data_competencia'])->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo);
        $this->assertTrue(mb_check_encoding($conteudo, 'UTF-8'));
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $conteudo), 'toda quebra de linha é CRLF');
        // Cabeçalho = primeira linha, separada por ';'. (Campos com espaço vêm entre aspas: CSV válido, lido pelo Excel.)
        $primeira = explode("\r\n", substr($conteudo, 3))[0];
        $this->assertSame(
            ['ID', 'Data de competência', 'Categoria', 'Conta', 'Tipo', 'Descrição', 'Valor', 'Status', 'Criado por', 'Data de criação'],
            str_getcsv($primeira, ';', '"', '')
        );
        $this->assertStringNotContainsString(',', $primeira, 'o separador é ponto e vírgula, nunca vírgula');
        $this->assertStringContainsString('Dízimos', $conteudo, 'acentos preservados');
    }

    public function test_csv_entradas_formata_dinheiro_datas_e_data_hora(): void
    {
        // 10/03/2026 02:30:00 UTC = 09/03/2026 23:30:00 em São Paulo.
        DB::table('entradas')->where('id', $this->m->e1->id)->update(['created_at' => '2026-03-10 02:30:00']);

        $csv = $this->lerCsv($this->csv('entradas', ['ordenar' => 'data_competencia'])->getContent());
        $linha = $csv['linhas'][0];
        [$ano, $mes] = explode('-', $this->m->mes);

        $this->assertSame((string) $this->m->e1->id, $linha[0]);
        $this->assertSame("05/$mes/$ano", $linha[1]);
        $this->assertSame('Dízimos', $linha[2]);
        $this->assertSame('Banco A', $linha[3]);
        $this->assertSame('Banco', $linha[4]);
        $this->assertSame('Dízimo de domingo', $linha[5]);
        $this->assertSame('100,00', $linha[6], 'duas casas, vírgula decimal, sem separador de milhar');
        $this->assertSame('Confirmada', $linha[7]);
        $this->assertSame($this->m->aux1->name, $linha[8]);
        $this->assertSame('09/03/2026 23:30:00', $linha[9]);
        $this->assertCount(3, $csv['linhas']);
    }

    public function test_csv_totais_ficam_apos_uma_linha_em_branco_e_iguais_aos_da_tela(): void
    {
        $conteudo = $this->csv('entradas')->getContent();
        $tela = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->m->mes])->assertOk();
        $csv = $this->lerCsv($conteudo);

        // Estrutura: linhas de dados, UMA linha em branco (CRLF CRLF) e os totais (rótulo;valor) até o fim, tudo em CRLF.
        $this->assertSame(1, substr_count($conteudo, "\r\n\r\n"), 'exatamente uma linha em branco separando os totais');
        $blocoTotais = substr($conteudo, strpos($conteudo, "\r\n\r\n") + 4);
        $this->assertSame(['Quantidade de entradas;3', 'Total de entradas;390,50'], array_map(fn ($l) => str_replace('"', '', $l), explode("\r\n", rtrim($blocoTotais, "\r\n"))));
        $this->assertStringEndsWith(";390,50\r\n", $conteudo);
        $this->assertSame(['Quantidade de entradas' => '3', 'Total de entradas' => '390,50'], $csv['totais']);
        $this->assertSame((string) $this->totalDe($tela, 'quantidade'), $csv['totais']['Quantidade de entradas']);
    }

    public function test_csv_exporta_todas_as_linhas_e_nao_so_a_pagina_da_tela(): void
    {
        $conta = $this->conta('Banco Volume');
        $categoria = $this->categoria('Volume');
        for ($i = 1; $i <= 25; $i++) {
            $this->entrada($conta, $categoria, $this->m->pastor, '1.00', sprintf('%s-%02d', $this->m->mes, $i));
        }

        $tela = $this->relatorioApi($this->m->pastor, 'entradas', ['ano_mes' => $this->m->mes, 'por_pagina' => 5, 'page' => 2])->assertOk();
        $csv = $this->lerCsv($this->csv('entradas', ['por_pagina' => 5, 'page' => 2])->getContent());

        $this->assertCount(5, $tela->json('data'));
        $this->assertCount(28, $csv['linhas'], '3 da massa + 25 novas, ignorando por_pagina/page');
        $this->assertSame('415.50', str_replace(',', '.', $csv['totais']['Total de entradas']));
    }

    public function test_csv_de_cada_relatorio_confere_linha_a_linha_com_a_consulta_json(): void
    {
        foreach (['entradas', 'despesas', 'movimentacoes', 'saldos'] as $relatorio) {
            $json = $this->relatorioApi($this->m->pastor, $relatorio, ['ano_mes' => $this->m->mes, 'por_pagina' => 100])->assertOk();
            $csv = $this->lerCsv($this->csv($relatorio)->getContent());

            $this->assertSame(array_column($json->json('meta.colunas'), 'rotulo'), $csv['cabecalho'], "$relatorio cabeçalho");
            $this->assertCount(count($json->json('data')), $csv['linhas'], "$relatorio nº de linhas");
            foreach ($json->json('data') as $i => $linha) {
                $esperado = [];
                foreach ($json->json('meta.colunas') as $coluna) {
                    $esperado[] = CsvRelatorioWriter::celula($linha[$coluna['chave']] ?? null, $coluna['tipo']);
                }
                $this->assertSame($esperado, $csv['linhas'][$i], "$relatorio linha $i");
            }
            foreach ($json->json('meta.totais') as $total) {
                $this->assertSame(CsvRelatorioWriter::celula($total['valor'], $total['tipo']), $csv['totais'][$total['rotulo']], "$relatorio total {$total['rotulo']}");
            }
        }
    }

    public function test_csv_despesas_mostra_data_de_pagamento_vazia_para_pendentes(): void
    {
        $csv = $this->lerCsv($this->csv('despesas', ['status' => 'pendente'])->getContent());

        $this->assertCount(2, $csv['linhas']);
        foreach ($csv['linhas'] as $linha) {
            $this->assertSame('', $linha[2], 'data de pagamento vazia');
            $this->assertSame('', $linha[4], 'conta vazia');
            $this->assertSame('Pendente', $linha[8]);
        }
    }

    public function test_csv_movimentacao_deixa_entrada_ou_saida_em_branco(): void
    {
        $csv = $this->lerCsv($this->csv('movimentacoes')->getContent());

        foreach ($csv['linhas'] as $linha) {
            $this->assertTrue(($linha[4] === '') xor ($linha[5] === ''), 'entrada XOR saída: ' . $linha[1]);
        }
        $despesa = collect($csv['linhas'])->first(fn ($l) => $l[1] === 'Despesa paga' && $l[7] === (string) $this->m->d1->id);
        $this->assertSame('30,00', $despesa[5]);
        $this->assertSame('-30,00', $despesa[6], 'valor negativo legítimo continua número (sem apóstrofo)');
    }

    public function test_csv_saldo_negativo_mantem_sinal_e_nao_e_tratado_como_texto(): void
    {
        $this->conta('Banco no vermelho', 'banco', '-150.75');

        $conteudo = $this->csv('saldos')->getContent();
        $csv = $this->lerCsv($conteudo);

        $linha = collect($csv['linhas'])->first(fn ($l) => $l[1] === 'Banco no vermelho');
        $this->assertSame('-150,75', $linha[4]);
        $this->assertStringNotContainsString("'-150,75", $conteudo);
    }

    public function test_csv_escapa_separador_aspas_e_quebra_de_linha_dentro_do_campo(): void
    {
        $this->entrada($this->m->contaA, $this->m->catE1, $this->m->pastor, '5.00', $this->m->mes . '-25', ['descricao' => "Oferta; \"especial\"\nsegunda linha"]);

        $conteudo = $this->csv('entradas', ['ordenar' => '-data_competencia'])->getContent();
        $csv = $this->lerCsv($conteudo);

        $this->assertSame("Oferta; \"especial\"\nsegunda linha", $csv['linhas'][0][5]);
        $this->assertStringContainsString('"Oferta; ""especial""' . "\n" . 'segunda linha"', $conteudo);
        $this->assertCount(4, $csv['linhas'], 'a quebra de linha DENTRO do campo não cria linha nova');
    }

    public function test_csv_de_relatorio_vazio_tem_so_cabecalho_e_totais_zerados(): void
    {
        $csv = $this->lerCsv($this->exportarApi($this->m->pastor, 'entradas', 'csv', ['ano_mes' => '2000-01'])->assertOk()->getContent());

        $this->assertSame([], $csv['linhas']);
        $this->assertSame(['Quantidade de entradas' => '0', 'Total de entradas' => '0,00'], $csv['totais']);
    }

    public function test_csv_resumo_lista_indicador_e_valor(): void
    {
        $csv = $this->lerCsv($this->csv('resumo')->getContent());
        $porIndicador = collect($csv['linhas'])->pluck(1, 0);

        $this->assertSame(['Indicador', 'Valor'], $csv['cabecalho']);
        $this->assertSame([], $csv['totais']);
        $this->assertSame('390,50', $porIndicador['Total de entradas']);
        $this->assertSame('2', $porIndicador['Quantidade de despesas pendentes']);
        $this->assertSame('912,00', $porIndicador['Saldo — Banco A']);
        $this->assertSame('Aberto', $porIndicador['Situação do período']);
    }

    // ================================================================ XLSX

    public function test_xlsx_cabecalhos_http_nome_do_arquivo_e_assinatura_zip(): void
    {
        foreach (self::RELATORIOS as $relatorio) {
            $r = $this->xlsx($relatorio);

            $r->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $r->assertHeader('Content-Disposition', 'attachment; filename="sfg-relatorio-' . $relatorio . '-' . $this->m->mes . '.xlsx"');
            $this->assertSame('PK', substr($r->getContent(), 0, 2));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        }
    }

    public function test_xlsx_entradas_tem_cabecalho_titulo_e_celulas_tipadas(): void
    {
        DB::table('entradas')->where('id', $this->m->e1->id)->update(['created_at' => '2026-03-10 02:30:00']);

        $x = $this->lerXlsx($this->xlsx('entradas', ['ordenar' => 'data_competencia'])->getContent());
        $linha = $x['linhas'][0];

        $this->assertSame('Entradas', $x['titulo']);
        $this->assertSame(['ID', 'Data de competência', 'Categoria', 'Conta', 'Tipo', 'Descrição', 'Valor', 'Status', 'Criado por', 'Data de criação'], $x['cabecalho']);
        $this->assertCount(3, $x['linhas']);

        $this->assertSame('n', $linha[0]['t']);
        $this->assertSame($this->m->e1->id, (int) $linha[0]['v']);
        $this->assertSame('n', $linha[1]['t']);
        $this->assertSame('dd/mm/yyyy', $linha[1]['f']);
        $this->assertSame($this->m->mes . '-05', Date::excelToDateTimeObject($linha[1]['v'])->format('Y-m-d'));
        $this->assertSame('Dízimos', $linha[2]['v']);
        $this->assertSame('s', $linha[2]['t']);
        $this->assertSame('Banco A', $linha[3]['v']);
        $this->assertSame('Dízimo de domingo', $linha[5]['v']);
        $this->assertSame('n', $linha[6]['t'], 'dinheiro é célula numérica');
        $this->assertSame('#,##0.00', $linha[6]['f']);
        $this->assertEqualsWithDelta(100.0, $linha[6]['v'], 0.0001);
        $this->assertSame('Confirmada', $linha[7]['v']);
        $this->assertSame('dd/mm/yyyy hh:mm:ss', $linha[9]['f']);
        $this->assertSame('2026-03-09 23:30:00', Date::excelToDateTimeObject($linha[9]['v'])->format('Y-m-d H:i:s'), 'UTC convertido para São Paulo');
    }

    public function test_xlsx_totais_apos_linha_em_branco_com_valor_numerico(): void
    {
        $x = $this->lerXlsx($this->xlsx('entradas')->getContent());

        $this->assertSame(['Quantidade de entradas', 'Total de entradas'], array_keys($x['totais']));
        $this->assertEqualsWithDelta(3.0, $x['totais']['Quantidade de entradas']['v'], 0.0001);
        $this->assertSame('n', $x['totais']['Total de entradas']['t']);
        $this->assertEqualsWithDelta(390.50, $x['totais']['Total de entradas']['v'], 0.0001);
        $this->assertSame('#,##0.00', $x['totais']['Total de entradas']['f']);
    }

    public function test_xlsx_exporta_todas_as_linhas_e_nao_so_a_pagina(): void
    {
        $conta = $this->conta('Banco Volume');
        $categoria = $this->categoria('Volume');
        for ($i = 1; $i <= 25; $i++) {
            $this->entrada($conta, $categoria, $this->m->pastor, '1.00', sprintf('%s-%02d', $this->m->mes, $i));
        }

        $x = $this->lerXlsx($this->xlsx('entradas', ['por_pagina' => 5, 'page' => 2])->getContent());

        $this->assertCount(28, $x['linhas']);
        $this->assertEqualsWithDelta(415.50, $x['totais']['Total de entradas']['v'], 0.0001);
    }

    public function test_xlsx_de_cada_relatorio_confere_com_a_consulta_json(): void
    {
        foreach (['entradas', 'despesas', 'movimentacoes', 'saldos'] as $relatorio) {
            $json = $this->relatorioApi($this->m->pastor, $relatorio, ['ano_mes' => $this->m->mes, 'por_pagina' => 100])->assertOk();
            $x = $this->lerXlsx($this->xlsx($relatorio)->getContent());
            $colunas = $json->json('meta.colunas');

            $this->assertSame(array_column($colunas, 'rotulo'), $x['cabecalho'], "$relatorio cabeçalho");
            $this->assertCount(count($json->json('data')), $x['linhas'], "$relatorio nº de linhas");
            foreach ($json->json('data') as $i => $linha) {
                foreach ($colunas as $c => $coluna) {
                    $celula = $x['linhas'][$i][$c];
                    $valor = $linha[$coluna['chave']] ?? null;
                    if ($valor === null) {
                        $this->assertNull($celula['v'], "$relatorio [$i][{$coluna['chave']}] deveria estar vazia");
                    } elseif ($coluna['tipo'] === 'dinheiro') {
                        $this->assertEqualsWithDelta((float) $valor, $celula['v'], 0.0001, "$relatorio [$i][{$coluna['chave']}]");
                    } elseif ($coluna['tipo'] === 'inteiro') {
                        $this->assertSame((int) $valor, (int) $celula['v']);
                    } elseif ($coluna['tipo'] === 'texto') {
                        $this->assertSame($valor, $celula['v'], "$relatorio [$i][{$coluna['chave']}]");
                    }
                }
            }
            foreach ($json->json('meta.totais') as $total) {
                $this->assertEqualsWithDelta((float) $total['valor'], (float) $x['totais'][$total['rotulo']]['v'], 0.0001, "$relatorio total {$total['rotulo']}");
            }
        }
    }

    public function test_xlsx_saldo_negativo_e_numero_negativo_e_nao_texto(): void
    {
        $this->conta('Banco no vermelho', 'banco', '-150.75');

        $x = $this->lerXlsx($this->xlsx('saldos')->getContent());
        $linha = collect($x['linhas'])->first(fn ($l) => $l[1]['v'] === 'Banco no vermelho');

        $this->assertSame('n', $linha[4]['t']);
        $this->assertEqualsWithDelta(-150.75, $linha[4]['v'], 0.0001);
    }

    public function test_xlsx_resumo_mistura_dinheiro_inteiro_e_texto_pelo_tipo_de_cada_linha(): void
    {
        $x = $this->lerXlsx($this->xlsx('resumo')->getContent());
        $porIndicador = collect($x['linhas'])->mapWithKeys(fn ($l) => [$l[0]['v'] => $l[1]]);

        $this->assertSame('n', $porIndicador['Total de entradas']['t']);
        $this->assertSame('#,##0.00', $porIndicador['Total de entradas']['f']);
        $this->assertEqualsWithDelta(390.5, $porIndicador['Total de entradas']['v'], 0.0001);
        $this->assertSame('n', $porIndicador['Quantidade de despesas pendentes']['t']);
        $this->assertSame(2, (int) $porIndicador['Quantidade de despesas pendentes']['v']);
        $this->assertSame('s', $porIndicador['Situação do período']['t']);
        $this->assertSame('Aberto', $porIndicador['Situação do período']['v']);
    }

    public function test_xlsx_de_relatorio_vazio_tem_cabecalho_e_totais_zerados(): void
    {
        $x = $this->lerXlsx($this->exportarApi($this->m->pastor, 'entradas', 'xlsx', ['ano_mes' => '2000-01'])->assertOk()->getContent());

        $this->assertSame([], $x['linhas']);
        $this->assertEqualsWithDelta(0.0, $x['totais']['Total de entradas']['v'], 0.0001);
    }

    public function test_administrador_e_tesoureiro_recebem_arquivos_identicos_aos_do_pastor(): void
    {
        $pastor = $this->csv('despesas')->getContent();

        foreach ([PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $outro = $this->exportarApi($this->como($perfil), 'despesas', 'csv', ['ano_mes' => $this->m->mes])->assertOk()->getContent();
            $this->assertSame($pastor, $outro);
        }
        $this->assertSame(3, Entrada::whereNull('entrada_estornada_id')->where('status', 'confirmada')->whereBetween('data_competencia', [$this->m->mes . '-01', $this->ultimoDiaDe($this->m->mes)])->count());
    }
}
