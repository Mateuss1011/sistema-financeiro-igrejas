<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\Entrada;
use App\Models\User;

/**
 * Plano oficial: Pastor e Tesoureiro criam/estornam; Auxiliar cria e vê só as próprias;
 * Administrador só visualiza (operar exige a exceção `entradas.operar`); Secretário sem acesso.
 */
class EntradaPolicy
{
    public function viewAny(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro)
            || $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }

    /** Ver as entradas de todos (o Auxiliar vê apenas as que ele mesmo criou). */
    public function viewAll(User $ator): bool
    {
        return $this->viewAny($ator) && ! $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }

    public function create(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro)
            || $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro)
            || $this->administradorOperador($ator);
    }

    public function reverse(User $ator, ?Entrada $entrada = null): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro)
            || $this->administradorOperador($ator);
    }

    private function administradorOperador(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Administrador)
            && $ator->temExcecao(PermissaoExcecaoChave::EntradasOperar->value);
    }
}
