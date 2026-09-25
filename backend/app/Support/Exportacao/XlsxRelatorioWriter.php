<?php

namespace App\Support\Exportacao;

use App\Support\Relatorios\RelatorioResultado;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * XLSX (.xlsx) da Fase 12, mesmo conteúdo do CSV: cabeçalhos na linha 1, dados, uma linha em branco e os totais.
 * Dinheiro = célula NUMÉRICA com formato `#,##0.00` (soma no Excel); datas = datas reais formatadas DD/MM/AAAA;
 * inteiros numéricos.
 *
 * SEGURANÇA: todo texto é gravado com `setCellValueExplicit(..., TYPE_STRING)` — nunca `setCellValue`, que trata
 * `=...` como FÓRMULA — e, além disso, passa por TextoSeguro (mesma neutralização do CSV).
 */
final class XlsxRelatorioWriter
{
    private const FUSO = 'America/Sao_Paulo';

    public function gerar(RelatorioResultado $resultado): string
    {
        $planilha = new Spreadsheet();
        $folha = $planilha->getActiveSheet();
        // Título de aba: até 31 caracteres e sem \ / ? * [ ] :
        $folha->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $resultado->titulo), 0, 31));

        $linhaAtual = 1;
        foreach ($resultado->colunas as $i => $coluna) {
            $this->texto($folha, $i + 1, $linhaAtual, $coluna['rotulo']);
        }
        $folha->getStyle([1, 1, max(1, count($resultado->colunas)), 1])->getFont()->setBold(true);
        $folha->freezePane('A2');

        foreach ($resultado->linhas as $linha) {
            $linhaAtual++;
            foreach ($resultado->colunas as $i => $coluna) {
                $tipo = $linha['_tipos'][$coluna['chave']] ?? $coluna['tipo'];
                $this->celula($folha, $i + 1, $linhaAtual, $linha[$coluna['chave']] ?? null, $tipo);
            }
        }

        if ($resultado->totais !== []) {
            $linhaAtual++; // linha em branco
            foreach ($resultado->totais as $total) {
                $linhaAtual++;
                $this->texto($folha, 1, $linhaAtual, $total['rotulo']);
                $folha->getStyle([1, $linhaAtual])->getFont()->setBold(true);
                $this->celula($folha, 2, $linhaAtual, $total['valor'], $total['tipo']);
            }
        }

        foreach (range(1, max(1, count($resultado->colunas))) as $indice) {
            $folha->getColumnDimensionByColumn($indice)->setAutoSize(true);
        }

        $arquivo = tempnam(sys_get_temp_dir(), 'sfg-xlsx-');
        try {
            IOFactory::createWriter($planilha, 'Xlsx')->save($arquivo);

            return (string) file_get_contents($arquivo);
        } finally {
            @unlink($arquivo);
            $planilha->disconnectWorksheets();
        }
    }

    private function celula(Worksheet $folha, int $coluna, int $linha, mixed $valor, string $tipo): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        switch ($tipo) {
            case 'dinheiro':
                // DECIMAL(14,2) cabe em 14 dígitos significativos: exato em ponto flutuante de dupla precisão.
                $folha->setCellValueExplicit([$coluna, $linha], (float) $valor, DataType::TYPE_NUMERIC);
                $folha->getStyle([$coluna, $linha])->getNumberFormat()->setFormatCode('#,##0.00');
                break;
            case 'inteiro':
                $folha->setCellValueExplicit([$coluna, $linha], (int) $valor, DataType::TYPE_NUMERIC);
                $folha->getStyle([$coluna, $linha])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                break;
            case 'data':
                $folha->setCellValueExplicit([$coluna, $linha], Date::dateTimeToExcel(Carbon::createFromFormat('!Y-m-d', (string) $valor)->toDateTime()), DataType::TYPE_NUMERIC);
                $folha->getStyle([$coluna, $linha])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                break;
            case 'datahora':
                $local = Carbon::parse((string) $valor)->setTimezone(self::FUSO);
                $folha->setCellValueExplicit([$coluna, $linha], Date::dateTimeToExcel(new \DateTime($local->format('Y-m-d H:i:s'))), DataType::TYPE_NUMERIC);
                $folha->getStyle([$coluna, $linha])->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm:ss');
                break;
            default:
                $this->texto($folha, $coluna, $linha, $valor);
        }
    }

    private function texto(Worksheet $folha, int $coluna, int $linha, mixed $valor): void
    {
        $folha->setCellValueExplicit([$coluna, $linha], TextoSeguro::neutralizar($valor), DataType::TYPE_STRING);
    }
}
