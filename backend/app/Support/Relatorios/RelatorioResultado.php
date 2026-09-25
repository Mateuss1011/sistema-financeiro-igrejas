<?php

namespace App\Support\Relatorios;

/**
 * Resultado de um relatório, no formato ÚNICO consumido pela tela (JSON), pelo CSV e pelo XLSX — por isso o
 * conteúdo dos três é idêntico.
 *
 * - colunas: lista de {chave, rotulo, tipo} com tipo em texto|dinheiro|data|datahora|inteiro;
 * - linhas: lista de arrays associativos (chave da coluna => valor). Dinheiro = string decimal com 2 casas ou null;
 *   data = 'Y-m-d'; datahora = ISO-8601 UTC; textos já vêm rotulados em português. Uma linha pode trazer `_tipos`
 *   (chave => tipo) para sobrescrever o tipo da coluna (só o Resumo, cujas linhas misturam dinheiro/inteiro/texto);
 * - totais: lista de {chave, rotulo, tipo, valor} — os mesmos totais da tela vão para o arquivo.
 */
final class RelatorioResultado
{
    /**
     * @param  list<array{chave: string, rotulo: string, tipo: string}>  $colunas
     * @param  list<array<string, mixed>>  $linhas
     * @param  list<array{chave: string, rotulo: string, tipo: string, valor: mixed}>  $totais
     * @param  array<string, mixed>  $filtros filtros efetivamente aplicados (sem nulos)
     * @param  array{current_page: int, last_page: int, per_page: int, total: int}|null  $paginacao
     * @param  array<string, mixed>|null  $indicadores mesmo formato do Dashboard (só no Resumo)
     */
    public function __construct(
        public readonly string $relatorio,
        public readonly string $titulo,
        public readonly string $anoMes,
        public readonly string $escopo,
        public readonly array $colunas,
        public readonly array $linhas,
        public readonly array $totais,
        public readonly array $filtros,
        public readonly ?array $paginacao = null,
        public readonly ?array $indicadores = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return [
            'relatorio' => $this->relatorio,
            'titulo' => $this->titulo,
            'ano_mes' => $this->anoMes,
            'escopo' => $this->escopo,
            'colunas' => $this->colunas,
            'totais' => $this->totais,
            'filtros' => $this->filtros,
            'paginacao' => $this->paginacao,
            'indicadores' => $this->indicadores,
        ];
    }

    /** Valor de um total pela chave (para testes/auditoria). */
    public function total(string $chave): mixed
    {
        foreach ($this->totais as $total) {
            if ($total['chave'] === $chave) {
                return $total['valor'];
            }
        }

        return null;
    }
}
