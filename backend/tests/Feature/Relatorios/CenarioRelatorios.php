<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Dashboard\CenarioDashboard;

/**
 * Helpers dos testes da Fase 12 (reaproveita como, conta, categoria, entrada, despesaPaga e afins, transferenciaDireta,
 * ajusteDireto, mesAtual, mesPassado, ultimoDiaDe, dashboardApi, comExcecoes, fecharApi etc. das fases anteriores).
 */
trait CenarioRelatorios
{
    use CenarioDashboard;

    protected const RELATORIOS = ['resumo', 'entradas', 'despesas', 'movimentacoes', 'saldos'];

    protected function relatorioApi(User $ator, string $relatorio, array $query = [])
    {
        $sufixo = $query === [] ? '' : '?' . http_build_query($query);

        return $this->actingAs($ator)->getJson("/api/v1/relatorios/{$relatorio}" . $sufixo);
    }

    /** Exportação (resposta binária: usar get(), não getJson()). */
    protected function exportarApi(User $ator, string $relatorio, string $formato, array $query = [])
    {
        $sufixo = $query === [] ? '' : '?' . http_build_query($query);

        return $this->actingAs($ator)->get("/api/v1/relatorios/{$relatorio}/exportar/{$formato}" . $sufixo);
    }

    protected function catalogoRelatoriosApi(User $ator)
    {
        return $this->actingAs($ator)->getJson('/api/v1/relatorios');
    }

    /** Valor de um total (meta.totais) pela chave. */
    protected function totalDe($resposta, string $chave): mixed
    {
        foreach ($resposta->json('meta.totais') as $total) {
            if ($total['chave'] === $chave) {
                return $total['valor'];
            }
        }

        return null;
    }

    /**
     * Massa determinística no mês $mes (formato AAAA-MM, no passado). Devolve os objetos criados.
     *
     * Esperado (perfis completos): entradas 390.50 (3) · pagas 75.00 (2) · pendentes 2 / 32.34 · transferências
     * 300.00 (A→B) · ajustes +25.00 (A) e −10.00 (B) · variação líquida 330.50 · 9 movimentações.
     * Esperado (Auxiliar 1): entradas 100.00 · pagas 30.00 · pendentes 1 / 12.34 · variação 70.00 · 2 movimentações.
     */
    protected function massaDoMes(string $mes): array
    {
        $m = new \stdClass();
        $m->mes = $mes;
        $m->pastor = $this->como(PerfilSlug::Pastor);
        $m->tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $m->aux1 = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $m->aux2 = $this->como(PerfilSlug::AuxiliarFinanceiro);

        $m->contaA = $this->conta('Banco A', 'banco', '1000.00');
        $m->contaB = $this->conta('Caixa B', 'caixa', '200.00');
        $m->contaC = $this->conta('Banco C inativo', 'banco', '50.00', false);

        $m->catE1 = $this->categoria('Dízimos');
        $m->catE2 = $this->categoria('Ofertas');
        $m->catD1 = $this->categoriaDespesa('Energia');
        $m->catD2 = $this->categoriaDespesa('Água');

        $anterior = $this->mesAnterior($mes);
        $seguinte = $this->mesSeguinte($mes);

        // ---- entradas
        $m->e1 = $this->entrada($m->contaA, $m->catE1, $m->aux1, '100.00', "$mes-05", ['descricao' => 'Dízimo de domingo']);
        $m->e2 = $this->entrada($m->contaB, $m->catE2, $m->tesoureiro, '250.50', "$mes-10", ['descricao' => 'Oferta especial']);
        $m->e3 = $this->entrada($m->contaA, $m->catE1, $m->aux2, '40.00', "$mes-12");
        $m->e4 = $this->entrada($m->contaA, $m->catE1, $m->aux1, '60.00', "$mes-15", ['status' => 'estornada']);
        $m->e4estorno = $this->entrada($m->contaA, $m->catE1, $m->pastor, '60.00', "$mes-15", ['entrada_estornada_id' => $m->e4->id, 'motivo_estorno' => 'setup']);
        $m->eForaAntes = $this->entrada($m->contaA, $m->catE1, $m->pastor, '77.00', "$anterior-28");
        $m->eForaDepois = $this->entrada($m->contaA, $m->catE1, $m->pastor, '88.00', "$seguinte-01");

        // ---- despesas
        $m->d1 = $this->despesaPaga($m->contaA, $m->catD1, $m->aux1, ['valor' => '30.00', 'data_competencia' => "$anterior-20", 'data_pagamento' => "$mes-06", 'descricao' => 'Luz', 'fornecedor_nome' => 'Companhia de Energia']);
        $m->d2 = $this->despesaPaga($m->contaB, $m->catD2, $m->tesoureiro, ['valor' => '45.00', 'data_competencia' => "$mes-08", 'data_pagamento' => "$mes-08", 'descricao' => 'Água']);
        $m->d3 = $this->despesaPaga($m->contaA, $m->catD1, $m->pastor, ['valor' => '99.00', 'data_competencia' => "$mes-20", 'data_pagamento' => "$seguinte-02", 'descricao' => 'Paga no mês seguinte']);
        $m->d4 = $this->despesaPendente($m->catD1, $m->aux1, ['valor' => '12.34', 'data_competencia' => "$mes-09", 'descricao' => 'Pendente do auxiliar']);
        $m->d5 = $this->despesaPendente($m->catD2, $m->tesoureiro, ['valor' => '20.00', 'data_competencia' => "$mes-11", 'descricao' => 'Pendente do tesoureiro']);
        $m->d6 = $this->despesaCancelada($m->catD1, $m->tesoureiro, ['valor' => '500.00', 'data_competencia' => "$mes-13", 'descricao' => 'Cancelada']);
        $m->d7 = $this->despesaPaga($m->contaA, $m->catD2, $m->aux1, ['valor' => '70.00', 'data_competencia' => "$mes-14", 'data_pagamento' => "$mes-14", 'status' => 'estornada', 'descricao' => 'Estornada']);
        Despesa::create([
            'categoria_id' => $m->catD2->id, 'conta_id' => $m->contaA->id, 'valor' => '70.00',
            'data_competencia' => "$mes-14", 'data_pagamento' => "$mes-14", 'status' => 'paga',
            'despesa_estornada_id' => $m->d7->id, 'motivo_estorno' => 'setup', 'criado_por' => $m->pastor->id,
        ]);

        // ---- transferências (T1 vale; T2 foi estornada: par original+estorno fica de fora)
        $m->t1 = $this->transferenciaDireta($m->contaA, $m->contaB, $m->tesoureiro, '300.00', "$mes-16", ['descricao' => 'Reforço do caixa']);
        $m->t2 = $this->transferenciaDireta($m->contaB, $m->contaA, $m->tesoureiro, '80.00', "$mes-17", ['status' => 'estornada']);
        $this->transferenciaDireta($m->contaA, $m->contaB, $m->pastor, '80.00', "$mes-17", ['transferencia_estornada_id' => $m->t2->id, 'motivo_estorno' => 'setup']);

        // ---- ajustes
        $m->aj1 = $this->ajusteDireto($m->contaA, $m->pastor, '25.00', 'credito', ['data_ajuste' => "$mes-18", 'justificativa' => 'Conciliação bancária']);
        $m->aj2 = $this->ajusteDireto($m->contaB, $m->tesoureiro, '10.00', 'debito', ['data_ajuste' => "$mes-19", 'justificativa' => 'Diferença no caixa']);

        return (array) $m;
    }

    /** Foto (contagem + hash) das tabelas financeiras/estruturais — para provar que consultar/exportar não altera nada. */
    protected function fotoDasTabelas(): array
    {
        $tabelas = ['entradas', 'despesas', 'contas', 'categorias', 'transferencias', 'ajustes_saldo', 'periodos_financeiros', 'permissoes_excecao', 'users'];

        return collect($tabelas)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count() . ':' . md5(json_encode(DB::table($t)->orderBy('id')->get()))])->all();
    }

    // ------------------------------------------------------------------ leitura dos arquivos exportados

    /**
     * CSV -> ['cabecalho' => [...], 'linhas' => [[...]], 'totais' => [rotulo => valor]]. Confere BOM UTF-8 e CRLF.
     */
    protected function lerCsv(string $conteudo): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo, 'CSV sem BOM UTF-8');
        $corpo = substr($conteudo, 3);
        $this->assertStringEndsWith("\r\n", $corpo, 'CSV sem CRLF no fim');

        $h = fopen('php://temp', 'r+');
        fwrite($h, $corpo);
        rewind($h);
        $linhas = [];
        while (($l = fgetcsv($h, 0, ';', '"', '')) !== false) {
            $linhas[] = $l;
        }
        fclose($h);

        $cabecalho = array_shift($linhas);
        $dados = [];
        $totais = [];
        $emTotais = false;
        foreach ($linhas as $l) {
            if ($l === [null]) {
                $emTotais = true;
                continue;
            }
            if ($emTotais) {
                $totais[$l[0]] = $l[1];
            } else {
                $dados[] = $l;
            }
        }

        return ['cabecalho' => $cabecalho, 'linhas' => $dados, 'totais' => $totais];
    }

    /**
     * XLSX -> ['cabecalho' => [...], 'linhas' => [[celula,...]], 'totais' => [rotulo => celula], 'titulo' => ...], onde
     * cada célula é ['v' => valor bruto, 't' => tipo ('s','n','f'...), 'f' => formato numérico].
     */
    protected function lerXlsx(string $conteudo): array
    {
        $this->assertStringStartsWith('PK', $conteudo, 'XLSX não é um zip válido');
        $arquivo = tempnam(sys_get_temp_dir(), 'sfg-teste-xlsx-');
        file_put_contents($arquivo, $conteudo);
        try {
            $planilha = IOFactory::load($arquivo);
        } finally {
            @unlink($arquivo);
        }
        $folha = $planilha->getActiveSheet();

        $ultimaLinha = $folha->getHighestDataRow();
        $ultimaColuna = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($folha->getHighestDataColumn());

        $matriz = [];
        for ($r = 1; $r <= $ultimaLinha; $r++) {
            $linha = [];
            for ($c = 1; $c <= $ultimaColuna; $c++) {
                $cel = $folha->getCell([$c, $r]);
                $linha[] = ['v' => $cel->getValue(), 't' => $cel->getDataType(), 'f' => $cel->getStyle()->getNumberFormat()->getFormatCode()];
            }
            $matriz[] = $linha;
        }

        $cabecalho = array_map(fn ($c) => $c['v'], array_shift($matriz));
        $dados = [];
        $totais = [];
        $emTotais = false;
        foreach ($matriz as $linha) {
            $vazia = collect($linha)->every(fn ($c) => $c['v'] === null);
            if ($vazia) {
                $emTotais = true;
                continue;
            }
            if ($emTotais) {
                $totais[$linha[0]['v']] = $linha[1];
            } else {
                $dados[] = $linha;
            }
        }

        return ['cabecalho' => $cabecalho, 'linhas' => $dados, 'totais' => $totais, 'titulo' => $folha->getTitle()];
    }
}
