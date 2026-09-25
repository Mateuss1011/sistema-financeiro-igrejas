<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\Transferencia;
use App\Models\User;

/**
 * Pastor e Tesoureiro: listam, criam e estornam. Administrador: lista; cria com `transferencias.operar`;
 * estorna com `transferencias.operar` E `transferencias.estornar`. Auxiliar e Secretário: sem acesso.
 * O estado do registro e o período NÃO são decididos aqui: o TransferenciaService devolve 409.
 */
class TransferenciaPolicy
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

    public function reverse(User $ator, ?Transferencia $transferencia = null): bool
    {
        return $this->permissoes($ator)['estornar'];
    }

    /**
     * Calculado com UMA consulta de exceções (sem N+1 nas flags por linha).
     *
     * @return array{criar: bool, estornar: bool}
     */
    public function permissoes(User $ator): array
    {
        if ($ator->ehPerfil(PerfilSlug::Pastor) || $ator->ehPerfil(PerfilSlug::Tesoureiro)) {
            return ['criar' => true, 'estornar' => true];
        }

        if ($ator->ehPerfil(PerfilSlug::Administrador)) {
            $excecoes = $ator->permissoesExcecao()->pluck('permissao')->all();
            $operar = in_array(PermissaoExcecaoChave::TransferenciasOperar->value, $excecoes, true);
            $estornar = in_array(PermissaoExcecaoChave::TransferenciasEstornar->value, $excecoes, true);

            return ['criar' => $operar, 'estornar' => $operar && $estornar];
        }

        return ['criar' => false, 'estornar' => false];
    }
}
