<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Models\User;
use App\Support\Relatorios\CatalogoRelatorios;

/**
 * Plano oficial (§4, matriz "Relatórios/Exportação") + prompt da Fase 12:
 *  - Pastor, Administrador e Tesoureiro: visualizam TODOS os relatórios e exportam (CSV/XLSX);
 *  - Auxiliar financeiro: visualiza (só o que ele mesmo criou), NUNCA exporta e NUNCA vê saldos — o relatório
 *    "Saldos por conta" lhe é negado;
 *  - Secretário: sem acesso a nada.
 * Não há exceção pontual para este módulo. O que cada perfil enxerga DENTRO de um relatório (escopo do Auxiliar,
 * transferências e ajustes só para quem já os enxerga) reaproveita as Policies existentes — nada é decidido aqui.
 */
class RelatorioPolicy
{
    public function viewAny(User $ator): bool
    {
        return $this->completo($ator) || $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }

    /** Um relatório específico: "saldos" exige perfil completo; os demais, qualquer perfil com acesso. */
    public function view(User $ator, string $relatorio): bool
    {
        if (! CatalogoRelatorios::existe($relatorio)) {
            return false;
        }

        return $relatorio === 'saldos' ? $this->completo($ator) : $this->viewAny($ator);
    }

    public function export(User $ator): bool
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
