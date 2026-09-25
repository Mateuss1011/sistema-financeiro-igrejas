<?php

namespace App\Support\Exportacao;

use App\Support\Relatorios\RelatorioResultado;
use Carbon\Carbon;

/**
 * CSV da Fase 12: UTF-8 com BOM (Excel), separador `;`, quebra CRLF, dinheiro com 2 casas e vírgula decimal SEM
 * separador de milhar (`-1234,50`), datas `DD/MM/AAAA`, data e hora `DD/MM/AAAA HH:mm:ss` (America/Sao_Paulo).
 * Primeira linha = cabeçalhos; depois as linhas; depois uma linha em branco e os totais (mesmos totais da tela).
 * Textos passam por TextoSeguro (fórmula neutralizada); dinheiro e números, nunca.
 */
final class CsvRelatorioWriter
{
    private const BOM = "\xEF\xBB\xBF";
    private const FUSO = 'America/Sao_Paulo';

    public function gerar(RelatorioResultado $resultado): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, self::BOM);

        $this->linha($h, array_map(fn ($c) => TextoSeguro::neutralizar($c['rotulo']), $resultado->colunas));

        foreach ($resultado->linhas as $linha) {
            $celulas = [];
            foreach ($resultado->colunas as $coluna) {
                $tipo = $linha['_tipos'][$coluna['chave']] ?? $coluna['tipo'];
                $celulas[] = self::celula($linha[$coluna['chave']] ?? null, $tipo);
            }
            $this->linha($h, $celulas);
        }

        if ($resultado->totais !== []) {
            fwrite($h, "\r\n");
            foreach ($resultado->totais as $total) {
                $this->linha($h, [TextoSeguro::neutralizar($total['rotulo']), self::celula($total['valor'], $total['tipo'])]);
            }
        }

        rewind($h);
        $conteudo = stream_get_contents($h);
        fclose($h);

        return $conteudo;
    }

    /** Valor tipado -> texto da célula (compartilhado com os testes para provar equivalência com a tela). */
    public static function celula(mixed $valor, string $tipo): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match ($tipo) {
            'dinheiro' => str_replace('.', ',', (string) $valor),
            'inteiro' => (string) (int) $valor,
            'data' => Carbon::createFromFormat('!Y-m-d', (string) $valor)->format('d/m/Y'),
            'datahora' => Carbon::parse((string) $valor)->setTimezone(self::FUSO)->format('d/m/Y H:i:s'),
            default => TextoSeguro::neutralizar($valor),
        };
    }

    /** @param  resource  $h */
    private function linha($h, array $celulas): void
    {
        fputcsv($h, $celulas, ';', '"', '', "\r\n");
    }
}
