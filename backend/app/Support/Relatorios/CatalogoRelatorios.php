<?php

namespace App\Support\Relatorios;

use App\Enums\StatusDespesa;

/**
 * Catálogo OFICIAL dos relatórios da Fase 12 (fonte única de nomes, filtros aceitos e ordenações). Também serve de
 * "classe de recurso" para a RelatorioPolicy (`$ator->can('viewAny', CatalogoRelatorios::class)`).
 *
 * Filtro principal de todos: `ano_mes` (AAAA-MM). Filtros extras só existem onde fazem sentido; um filtro que não se
 * aplica ao relatório pedido é simplesmente ignorado (mesmo comportamento do Dashboard com parâmetros desconhecidos).
 */
final class CatalogoRelatorios
{
    private const RELATORIOS = [
        'resumo' => [
            'nome' => 'Resumo financeiro',
            'descricao' => 'Totais do mês, saldos e situação do período — os mesmos números do Dashboard.',
            'filtros' => [],
            'ordenacao' => [],
            'ordenar_padrao' => null,
            'paginado' => false,
        ],
        'entradas' => [
            'nome' => 'Entradas',
            'descricao' => 'Entradas originais em vigor do mês, pela data de competência.',
            'filtros' => ['conta_id', 'categoria_id'],
            'ordenacao' => ['data_competencia', 'valor', 'created_at', 'id'],
            'ordenar_padrao' => '-data_competencia,-id',
            'paginado' => true,
        ],
        'despesas' => [
            'nome' => 'Despesas',
            'descricao' => 'Despesas do mês: pagas pela data de pagamento; pendentes e canceladas pela competência.',
            'filtros' => ['conta_id', 'categoria_id', 'status'],
            'ordenacao' => ['data_competencia', 'data_pagamento', 'valor', 'created_at', 'id'],
            'ordenar_padrao' => '-data_competencia,-id',
            'paginado' => true,
        ],
        'movimentacoes' => [
            'nome' => 'Movimentação financeira',
            'descricao' => 'Tudo que altera saldo no mês: entradas, despesas pagas, transferências e ajustes.',
            'filtros' => ['conta_id'],
            'ordenacao' => ['data', 'referencia_id'],
            'ordenar_padrao' => '-data,-referencia_id',
            'paginado' => true,
        ],
        'saldos' => [
            'nome' => 'Saldos por conta',
            'descricao' => 'Saldo de cada conta (atual no mês corrente; ao fim do mês nos anteriores).',
            'filtros' => ['conta_id'],
            'ordenacao' => ['tipo', 'nome', 'saldo', 'id'],
            'ordenar_padrao' => 'tipo,nome',
            'paginado' => true,
        ],
    ];

    public const FORMATOS = ['csv', 'xlsx'];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::RELATORIOS);
    }

    public static function existe(string $relatorio): bool
    {
        return isset(self::RELATORIOS[$relatorio]);
    }

    public static function nome(string $relatorio): string
    {
        return self::RELATORIOS[$relatorio]['nome'];
    }

    public static function descricao(string $relatorio): string
    {
        return self::RELATORIOS[$relatorio]['descricao'];
    }

    /** @return list<string> */
    public static function filtros(string $relatorio): array
    {
        return self::RELATORIOS[$relatorio]['filtros'];
    }

    /** @return list<string> */
    public static function ordenacao(string $relatorio): array
    {
        return self::RELATORIOS[$relatorio]['ordenacao'];
    }

    public static function ordenarPadrao(string $relatorio): ?string
    {
        return self::RELATORIOS[$relatorio]['ordenar_padrao'];
    }

    public static function paginado(string $relatorio): bool
    {
        return self::RELATORIOS[$relatorio]['paginado'];
    }

    /** @return list<array{valor: string, rotulo: string}> opções do filtro `status` das despesas, com rótulo */
    public static function statusDespesaRotulos(): array
    {
        return [
            ['valor' => 'pendente', 'rotulo' => 'Pendente'],
            ['valor' => 'paga', 'rotulo' => 'Paga'],
            ['valor' => 'estornada', 'rotulo' => 'Estornada'],
            ['valor' => 'cancelada', 'rotulo' => 'Cancelada'],
        ];
    }

    /** @return list<string> valores aceitos no filtro `status` das despesas */
    public static function statusDespesa(): array
    {
        return array_map(fn (StatusDespesa $s) => $s->value, StatusDespesa::cases());
    }
}
