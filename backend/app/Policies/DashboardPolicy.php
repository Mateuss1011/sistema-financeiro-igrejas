<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Models\User;

/**
 * Plano oficial (§4), refinado pela aprovação da Fase 11:
 *  - Pastor, Administrador e Tesoureiro: dashboard COMPLETO (indicadores + saldos + situação do período);
 *  - Auxiliar financeiro: dashboard PARCIAL (só totais de entradas/despesas pagas/pendentes, sem saldos
 *    nem situação do período);
 *  - Secretário: sem acesso.
 * Não há exceção pontual para este módulo. Que lançamentos o Auxiliar enxerga NÃO é decidido aqui: o
 * DashboardService reaproveita `viewAll` de EntradaPolicy/DespesaPolicy (Auxiliar vê só os próprios).
 */
class DashboardPolicy
{
    public function viewAny(User $ator): bool
    {
        return $this->completo($ator) || $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }

    /** Saldos (total e por conta) e situação do período — nunca para o Auxiliar. */
    public function viewCompleto(User $ator): bool
    {
        return $this->completo($ator);
    }

    private function completo(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro);
    }
}
