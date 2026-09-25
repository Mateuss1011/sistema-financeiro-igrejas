<?php

namespace App\Policies;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\Despesa;
use App\Models\User;

/**
 * Plano oficial (§4/§18):
 *  - Pastor: tudo (estorno de paga incluso);
 *  - Administrador: só visualiza; opera (criar/editar Pendente/pagar/cancelar/excluir) com `despesas.operar`;
 *    estorno de paga exige TAMBÉM `despesas.estornar_paga`;
 *  - Tesoureiro: criar/editar Pendente/pagar/cancelar; estorno de paga só com `despesas.estornar_paga`;
 *  - Auxiliar: cria (nasce Pendente) e vê só as próprias; nada além disso;
 *  - Secretário: sem acesso.
 * Estado do registro (Pendente, Paga…) e período NÃO são decididos aqui: o DespesaService devolve 409.
 */
class DespesaPolicy
{
    public function viewAny(User $ator): bool
    {
        return $this->perfilFinanceiro($ator);
    }

    /** Ver as despesas de todos (o Auxiliar vê apenas as que ele mesmo criou). */
    public function viewAll(User $ator): bool
    {
        return $this->perfilFinanceiro($ator) && ! $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }

    public function create(User $ator): bool
    {
        return $this->permissoes($ator)['criar'];
    }

    public function update(User $ator, ?Despesa $despesa = null): bool
    {
        return $this->permissoes($ator)['editar'];
    }

    public function pay(User $ator, ?Despesa $despesa = null): bool
    {
        return $this->permissoes($ator)['pagar'];
    }

    public function cancel(User $ator, ?Despesa $despesa = null): bool
    {
        return $this->permissoes($ator)['cancelar'];
    }

    public function reverse(User $ator, ?Despesa $despesa = null): bool
    {
        return $this->permissoes($ator)['estornar'];
    }

    /**
     * Excluir: o Pastor exclui qualquer Pendente (a exceção da regra de dono/48h é tratada no Service);
     * Tesoureiro e Administrador (com `despesas.operar`) só as PRÓPRIAS. Auxiliar nunca.
     */
    public function delete(User $ator, Despesa $despesa): bool
    {
        if ($ator->ehPerfil(PerfilSlug::Pastor)) {
            return true;
        }

        return $this->permissoes($ator)['excluir'] && $despesa->criado_por === $ator->id;
    }

    /**
     * Permissões do usuário em nível de perfil/exceção, calculadas com UMA consulta de exceções.
     * Usadas em `meta.permissoes` e nas flags por linha (sem N+1).
     *
     * @return array{criar: bool, editar: bool, pagar: bool, cancelar: bool, estornar: bool, excluir: bool}
     */
    public function permissoes(User $ator): array
    {
        $pastor = $ator->ehPerfil(PerfilSlug::Pastor);
        $tesoureiro = $ator->ehPerfil(PerfilSlug::Tesoureiro);
        $administrador = $ator->ehPerfil(PerfilSlug::Administrador);
        $auxiliar = $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);

        $excecoes = ($administrador || $tesoureiro)
            ? $ator->permissoesExcecao()->pluck('permissao')->all()
            : [];
        $operar = $administrador && in_array(PermissaoExcecaoChave::DespesasOperar->value, $excecoes, true);
        $estornarPaga = in_array(PermissaoExcecaoChave::DespesasEstornarPaga->value, $excecoes, true);

        $opera = $pastor || $tesoureiro || $operar;

        return [
            'criar' => $opera || $auxiliar,
            'editar' => $opera,
            'pagar' => $opera,
            'cancelar' => $opera,
            'estornar' => $pastor || ($tesoureiro && $estornarPaga) || ($operar && $estornarPaga),
            'excluir' => $opera,
        ];
    }

    private function perfilFinanceiro(User $ator): bool
    {
        return $ator->ehPerfil(PerfilSlug::Pastor)
            || $ator->ehPerfil(PerfilSlug::Administrador)
            || $ator->ehPerfil(PerfilSlug::Tesoureiro)
            || $ator->ehPerfil(PerfilSlug::AuxiliarFinanceiro);
    }
}
