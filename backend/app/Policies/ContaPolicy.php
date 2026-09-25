<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Models\Conta;
use App\Models\User;

/**
 * Conforme o plano oficial: Secretário não tem acesso a dados financeiros;
 * a exclusão é exclusiva do Pastor.
 */
class ContaPolicy
{
    public function viewAny(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro)
            || $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }

    public function create(User $ator): bool
    {
        return $this->podeAdministrar($ator);
    }

    public function update(User $ator, Conta $conta): bool
    {
        return $this->podeAdministrar($ator);
    }

    public function delete(User $ator, Conta $conta): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor);
    }

    private function podeAdministrar(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor) || $ator->ehPerfil(PerfilSlug::Administrador);
    }
}
