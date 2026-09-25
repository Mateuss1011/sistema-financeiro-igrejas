<?php

namespace App\Enums;

/**
 * Catálogo central das permissões excepcionais que o sistema conhece.
 * A coluna permissoes_excecao.permissao continua sendo texto livre no banco;
 * este enum é a fonte única das chaves válidas.
 */
enum PermissaoExcecaoChave: string
{
    case UsuariosGerenciarPrivilegiado = 'usuarios.gerenciar_privilegiado';
    case EntradasOperar = 'entradas.operar';
    case DespesasOperar = 'despesas.operar';
    case DespesasEstornarPaga = 'despesas.estornar_paga';
    case TransferenciasOperar = 'transferencias.operar';
    case TransferenciasEstornar = 'transferencias.estornar';
    case AjustesOperar = 'ajustes.operar';

    public function descricao(): string
    {
        return match ($this) {
            self::UsuariosGerenciarPrivilegiado => 'Permite a um Administrador criar, editar e desativar Administradores e Pastores.',
            self::EntradasOperar => 'Permite a um Administrador registrar e estornar entradas (receitas).',
            self::DespesasOperar => 'Permite a um Administrador criar, editar (Pendente), pagar e cancelar despesas.',
            self::DespesasEstornarPaga => 'Permite estornar despesas pagas (Tesoureiro; para o Administrador vale junto com despesas.operar).',
            self::TransferenciasOperar => 'Permite a um Administrador registrar transferências entre contas.',
            self::TransferenciasEstornar => 'Permite a um Administrador estornar transferências (exige também transferencias.operar).',
            self::AjustesOperar => 'Permite a um Administrador registrar ajustes de saldo.',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(fn (self $chave) => $chave->value, self::cases());
    }
}
