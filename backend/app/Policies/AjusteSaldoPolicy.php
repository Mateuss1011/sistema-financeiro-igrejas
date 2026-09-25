<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\User;

/**
 * Pastor e Tesoureiro: listam e criam. Administrador: lista; cria somente com `ajustes.operar`.
 * Auxiliar e Secretário: sem acesso. Não há edição, exclusão nem estorno de ajuste.
 */
class AjusteSaldoPolicy
{
    public function viewAny(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro);
    }

    public function create(User $ator): bool
    {
        return $this->permissoes($ator)['criar'];
    }

    /** @return array{criar: bool} */
    public function permissoes(User $ator): array
    {
        if ($ator->ehPerfil(PerfilSlug::Pastor) || $ator->ehPerfil(PerfilSlug::Tesoureiro)) {
            return ['criar' => true];
        }

        if ($ator->ehPerfil(PerfilSlug::Administrador)) {
            return ['criar' => $ator->temExcecao(PermissaoExcecaoChave::AjustesOperar->value)];
        }

        return ['criar' => false];
    }
}
