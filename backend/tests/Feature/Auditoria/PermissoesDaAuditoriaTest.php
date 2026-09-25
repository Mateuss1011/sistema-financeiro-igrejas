<?php

namespace Tests\Feature\Auditoria;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Matriz da seção 4 do plano: Auditoria = Pastor e Administrador consultam; os demais perfis não têm acesso. */
class PermissoesDaAuditoriaTest extends TestCase
{
    use RefreshDatabase, CenarioAuditoria;

    public function test_pastor_e_administrador_consultam_listagem_e_catalogo(): void
    {
        $this->log(['modulo' => 'contas']);

        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador] as $perfil) {
            $ator = $this->como($perfil);

            $this->consultarAuditoria($ator)->assertOk()->assertJsonPath('meta.total', 1);
            $this->catalogoAuditoria($ator)->assertOk();
        }
    }

    public function test_tesoureiro_auxiliar_e_secretario_nao_tem_acesso_nem_ao_catalogo(): void
    {
        $this->log();

        foreach ([PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $ator = $this->como($perfil);

            $this->consultarAuditoria($ator)->assertStatus(403)->assertJsonMissingPath('data');
            $this->catalogoAuditoria($ator)->assertStatus(403)->assertJsonMissingPath('data');
        }
    }

    public function test_nenhuma_excecao_existente_libera_a_auditoria_para_quem_nao_tem_acesso(): void
    {
        // Não existe exceção pontual para este módulo: nem todas as chaves juntas concedem acesso.
        foreach ([PerfilSlug::Tesoureiro, PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario] as $perfil) {
            $ator = $this->comExcecoes($perfil, PermissaoExcecaoChave::valores());

            $this->consultarAuditoria($ator)->assertStatus(403);
            $this->catalogoAuditoria($ator)->assertStatus(403);
        }
    }

    public function test_autorizacao_vem_antes_da_validacao_dos_filtros(): void
    {
        // Quem não pode consultar recebe 403 mesmo com filtros inválidos (nada do formato da API vaza em 422).
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);

        $this->consultarAuditoria($tesoureiro, ['modulo' => 'inexistente', 'por_pagina' => 9999])->assertStatus(403);
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/auditoria')->assertStatus(401);
        $this->getJson('/api/v1/auditoria/catalogo')->assertStatus(401);
    }

    public function test_filtrar_por_user_id_de_outro_usuario_nao_da_acesso_a_quem_nao_pode_consultar(): void
    {
        // Filtrar por user_id de outro usuário não dá acesso a quem não é Pastor/Administrador.
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->log(['user_id' => $pastor->id, 'user_nome_congelado' => $pastor->name]);

        $this->consultarAuditoria($this->como(PerfilSlug::Secretario), ['user_id' => $pastor->id])->assertStatus(403);
    }
}
