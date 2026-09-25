<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\User;

class UsuarioPolicy
{
    public function viewAny(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Secretario);
    }

    public function view(User $ator, User $alvo): bool
    {
        return $this->viewAny($ator);
    }

    public function create(User $ator, PerfilSlug $perfilAlvo): bool
    {
        if ($ator->ehPerfil(PerfilSlug::Pastor)) {
            return true;
        }

        if ($ator->ehPerfil(PerfilSlug::Administrador)) {
            return $this->naoEhPrivilegiado($perfilAlvo)
                || $ator->temExcecao(PermissaoExcecaoChave::UsuariosGerenciarPrivilegiado->value);
        }

        return false;
    }

    public function update(User $ator, User $alvo): bool
    {
        if ($ator->ehPerfil(PerfilSlug::Pastor)) {
            return true;
        }

        if ($ator->ehPerfil(PerfilSlug::Administrador)) {
            return $this->naoEhPrivilegiado($alvo->perfil?->slug)
                || $ator->temExcecao(PermissaoExcecaoChave::UsuariosGerenciarPrivilegiado->value);
        }

        return false;
    }

    public function delete(User $ator, User $alvo): bool
    {
        return $this->update($ator, $alvo);
    }

    public function gerenciarExcecoes(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor);
    }

    private function naoEhPrivilegiado(?PerfilSlug $perfil): bool
    {
        return ! in_array($perfil, [PerfilSlug::Pastor, PerfilSlug::Administrador], true);
    }
}
