<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Models\Categoria;
use App\Models\User;

class CategoriaPolicy
{
    /** Todos os perfis do sistema podem consultar categorias. */
    public function viewAny(User $ator): bool
    {
        return $ator->perfil !== null;
    }

    public function create(User $ator): bool
    {
        return $this->podeGerenciar($ator);
    }

    public function update(User $ator, Categoria $categoria): bool
    {
        return $this->podeGerenciar($ator);
    }

    public function delete(User $ator, Categoria $categoria): bool
    {
        return $this->podeGerenciar($ator);
    }

    private function podeGerenciar(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor) || $ator->ehPerfil(PerfilSlug::Administrador);
    }
}
