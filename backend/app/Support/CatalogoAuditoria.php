<?php

namespace App\Support;

/**
 * Fonte única dos módulos e ações que existem em audit_logs, com o rótulo mostrado na tela de auditoria.
 * Serve de whitelist para os filtros da consulta e de base do checklist de cobertura da Fase 10: o teste
 * de cobertura garante que toda chamada a AuditoriaService::registrar() no código usa um par (módulo, ação)
 * que consta aqui, e que cada item da lista auditável do plano (seção 6) de fato gera registro.
 *
 * "Exportações" entrou na Fase 12 (toda exportação CSV/XLSX é auditada). O módulo "configurações" (seção 6 do plano)
 * ainda não existe no sistema — entra neste catálogo junto com a funcionalidade, em fase posterior.
 */
final class CatalogoAuditoria
{
    private const ACOES = [
        'created' => 'Criação',
        'updated' => 'Edição',
        'activated' => 'Ativação',
        'deactivated' => 'Desativação',
        'deleted' => 'Exclusão',
        'reversed' => 'Estorno',
        'paid' => 'Pagamento',
        'canceled' => 'Cancelamento',
        'closed' => 'Fechamento',
        'reopened' => 'Reabertura',
        'login' => 'Login',
        'login_failed' => 'Falha de login',
        'logout' => 'Logout',
        'permission_granted' => 'Permissão concedida',
        'permission_revoked' => 'Permissão revogada',
        'exported' => 'Exportação',
    ];

    private const MODULOS = [
        'auth' => ['Autenticação', ['login', 'login_failed', 'logout']],
        'usuarios' => ['Usuários', ['created', 'updated', 'activated', 'deactivated', 'permission_granted', 'permission_revoked']],
        'categorias' => ['Categorias', ['created', 'updated', 'activated', 'deactivated', 'deleted']],
        'contas' => ['Contas', ['created', 'updated', 'activated', 'deactivated', 'deleted']],
        'entradas' => ['Entradas', ['created', 'reversed']],
        'despesas' => ['Despesas', ['created', 'updated', 'paid', 'canceled', 'reversed', 'deleted']],
        'transferencias' => ['Transferências', ['created', 'reversed']],
        'ajustes_saldo' => ['Ajustes de saldo', ['created']],
        'periodos_financeiros' => ['Fechamento de período', ['closed', 'reopened']],
        'exportacoes' => ['Exportações', ['exported']],
    ];

    /** @return list<string> */
    public static function modulos(): array
    {
        return array_keys(self::MODULOS);
    }

    /** Todas as ações conhecidas (união de todos os módulos). @return list<string> */
    public static function acoes(): array
    {
        return array_keys(self::ACOES);
    }

    /** Pares (módulo, ação) válidos. @return list<array{0: string, 1: string}> */
    public static function pares(): array
    {
        $pares = [];
        foreach (self::MODULOS as $modulo => [, $acoes]) {
            foreach ($acoes as $acao) {
                $pares[] = [$modulo, $acao];
            }
        }

        return $pares;
    }

    public static function rotuloModulo(string $modulo): string
    {
        return self::MODULOS[$modulo][0] ?? $modulo;
    }

    public static function rotuloAcao(string $acao): string
    {
        return self::ACOES[$acao] ?? $acao;
    }

    /** @return list<array{valor: string, rotulo: string, acoes: list<array{valor: string, rotulo: string}>}> */
    public static function paraFiltros(): array
    {
        $catalogo = [];
        foreach (self::MODULOS as $modulo => [$rotulo, $acoes]) {
            $catalogo[] = [
                'valor' => $modulo,
                'rotulo' => $rotulo,
                'acoes' => array_map(fn (string $acao) => ['valor' => $acao, 'rotulo' => self::ACOES[$acao]], $acoes),
            ];
        }

        return $catalogo;
    }
}
