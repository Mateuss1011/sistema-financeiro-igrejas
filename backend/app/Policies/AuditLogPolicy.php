<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Models\User;

/**
 * Auditoria é somente consulta (plano, seção 4): Pastor e Administrador consultam; Tesoureiro, Auxiliar
 * financeiro e Secretário não têm acesso. Não há exceção pontual para este módulo. Deliberadamente NÃO
 * existem create/update/delete: nenhum perfil edita ou exclui um log, nem por exceção.
 */
class AuditLogPolicy
{
    public function viewAny(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor) || $ator->ehPerfil(PerfilSlug::Administrador);
    }
}
