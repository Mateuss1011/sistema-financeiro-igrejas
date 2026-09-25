<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Models\User;

/**
 * Pastor e Tesoureiro: consultam e fecham. Só Pastor reabre. Administrador: só consulta — sem
 * exceção possível (decisão de negócio D1 da Fase 9: fechamento/reabertura não têm mecanismo de
 * exceção, diferente de entradas/despesas/transferências/ajustes). Auxiliar e Secretário: sem acesso.
 */
class PeriodoFinanceiroPolicy
{
    public function viewAny(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro);
    }

    public function fechar(User $ator): bool
    {
        return $this->permissoes($ator)['fechar'];
    }

    public function reabrir(User $ator): bool
    {
        return $this->permissoes($ator)['reabrir'];
    }

    /** @return array{fechar: bool, reabrir: bool} */
    public function permissoes(User $ator): array
    {
        return [
            'fechar' => $ator->ehPerfil(PerfilSlug::Pastor) || $ator->ehPerfil(PerfilSlug::Tesoureiro),
            'reabrir' => $ator->ehPerfil(PerfilSlug::Pastor),
        ];
    }
}
