<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matriz da Fase 12: Pastor/Administrador/Tesoureiro visualizam e exportam; Auxiliar visualiza (só o que criou), nunca
 * exporta e nunca vê saldos; Secretário sem acesso. O backend é a autoridade.
 */
class PermissoesDeRelatoriosTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    public function test_pastor_administrador_e_tesoureiro_consultam_os_cinco_relatorios(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $ator = $this->como($perfil);
            foreach (self::RELATORIOS as $relatorio) {
                $this->relatorioApi($ator, $relatorio)->assertOk()->assertJsonPath('meta.relatorio', $relatorio);
            }
        }
    }

    public function test_auxiliar_consulta_resumo_entradas_despesas_e_movimentacoes_mas_nao_saldos(): void
    {
        $aux = $this->como(PerfilSlug::AuxiliarFinanceiro);

        foreach (['resumo', 'entradas', 'despesas', 'movimentacoes'] as $relatorio) {
            $this->relatorioApi($aux, $relatorio)->assertOk()->assertJsonPath('meta.escopo', 'proprios');
        }
        $this->relatorioApi($aux, 'saldos')->assertStatus(403)->assertJsonMissingPath('data');
    }

    public function test_secretario_nao_tem_acesso_a_nada(): void
    {
        $sec = $this->como(PerfilSlug::Secretario);

        $this->catalogoRelatoriosApi($sec)->assertStatus(403);
        foreach (self::RELATORIOS as $relatorio) {
            $this->relatorioApi($sec, $relatorio)->assertStatus(403)->assertJsonMissingPath('data');
            foreach (['csv', 'xlsx'] as $formato) {
                $this->exportarApi($sec, $relatorio, $formato)->assertStatus(403);
            }
        }
    }

    public function test_sem_autenticacao_retorna_401(): void
    {
        $this->getJson('/api/v1/relatorios')->assertStatus(401);
        $this->getJson('/api/v1/relatorios/resumo')->assertStatus(401);
        $this->getJson('/api/v1/relatorios/entradas/exportar/csv')->assertStatus(401);
        $this->getJson('/api/v1/relatorios/entradas/exportar/xlsx')->assertStatus(401);
    }

    public function test_auxiliar_nao_exporta_nenhum_relatorio_em_nenhum_formato_e_nada_e_auditado(): void
    {
        $aux = $this->como(PerfilSlug::AuxiliarFinanceiro);

        foreach (self::RELATORIOS as $relatorio) {
            foreach (['csv', 'xlsx'] as $formato) {
                $resposta = $this->exportarApi($aux, $relatorio, $formato)->assertStatus(403);
                $this->assertStringNotContainsString("\xEF\xBB\xBF", $resposta->getContent());
                $this->assertStringNotContainsString('PK', substr($resposta->getContent(), 0, 2));
            }
        }
        $this->assertSame(0, AuditLog::where('modulo', 'exportacoes')->count());
    }

    public function test_pastor_administrador_e_tesoureiro_exportam_csv_e_xlsx_de_todos_os_relatorios(): void
    {
        foreach ([PerfilSlug::Pastor, PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $ator = $this->como($perfil);
            foreach (self::RELATORIOS as $relatorio) {
                foreach (['csv', 'xlsx'] as $formato) {
                    $this->exportarApi($ator, $relatorio, $formato)->assertOk();
                }
            }
        }
        $this->assertSame(3 * 5 * 2, AuditLog::where('modulo', 'exportacoes')->count());
    }

    public function test_nenhuma_excecao_existente_libera_relatorios_ou_exportacao_para_quem_nao_pode(): void
    {
        $sec = $this->comExcecoes(PerfilSlug::Secretario, PermissaoExcecaoChave::valores());
        $this->relatorioApi($sec, 'resumo')->assertStatus(403);
        $this->exportarApi($sec, 'entradas', 'csv')->assertStatus(403);

        $aux = $this->comExcecoes(PerfilSlug::AuxiliarFinanceiro, PermissaoExcecaoChave::valores());
        $this->exportarApi($aux, 'entradas', 'csv')->assertStatus(403);
        $this->exportarApi($aux, 'entradas', 'xlsx')->assertStatus(403);
        $this->relatorioApi($aux, 'saldos')->assertStatus(403);
    }

    public function test_autorizacao_vem_antes_da_validacao_dos_filtros(): void
    {
        $sec = $this->como(PerfilSlug::Secretario);
        $aux = $this->como(PerfilSlug::AuxiliarFinanceiro);

        $this->relatorioApi($sec, 'entradas', ['ano_mes' => 'lixo', 'por_pagina' => 9999])->assertStatus(403);
        $this->exportarApi($aux, 'entradas', 'csv', ['ano_mes' => 'lixo'])->assertStatus(403);
        $this->relatorioApi($aux, 'saldos', ['ano_mes' => '2999-01'])->assertStatus(403);
    }

    public function test_parametros_de_perfil_visao_ou_escopo_enviados_pelo_cliente_sao_ignorados(): void
    {
        $aux = $this->como(PerfilSlug::AuxiliarFinanceiro);

        $resposta = $this->relatorioApi($aux, 'resumo', ['visao' => 'completo', 'escopo' => 'todos', 'perfil' => 'pastor', 'exportar' => 'true'])->assertOk();

        $resposta->assertJsonPath('meta.escopo', 'proprios')->assertJsonPath('meta.indicadores.visao', 'parcial');
        $this->assertArrayNotHasKey('saldo', $resposta->json('meta.indicadores'));
        $this->assertArrayNotHasKey('periodo', $resposta->json('meta.indicadores'));
        $this->exportarApi($aux, 'resumo', 'csv', ['perfil' => 'pastor', 'visao' => 'completo'])->assertStatus(403);
    }

    public function test_catalogo_lista_so_o_que_cada_perfil_pode_ver_e_dizer_se_pode_exportar(): void
    {
        $completo = $this->catalogoRelatoriosApi($this->como(PerfilSlug::Tesoureiro))->assertOk();
        $this->assertSame(self::RELATORIOS, array_column($completo->json('data'), 'codigo'));
        $completo->assertJsonPath('meta.permissoes.exportar', true)->assertJsonPath('meta.formatos', ['csv', 'xlsx']);

        $aux = $this->catalogoRelatoriosApi($this->como(PerfilSlug::AuxiliarFinanceiro))->assertOk();
        $this->assertSame(['resumo', 'entradas', 'despesas', 'movimentacoes'], array_column($aux->json('data'), 'codigo'));
        $aux->assertJsonPath('meta.permissoes.exportar', false)->assertJsonPath('meta.formatos', []);
    }

    public function test_opcoes_de_filtro_do_catalogo_nao_carregam_saldo(): void
    {
        $this->conta('Banco Visivel', 'banco', '4321.00');
        $this->categoria('Categoria X');

        $resposta = $this->catalogoRelatoriosApi($this->como(PerfilSlug::AuxiliarFinanceiro))->assertOk();

        $this->assertSame(['ativa', 'id', 'nome', 'tipo'], collect(array_keys($resposta->json('meta.opcoes.contas.0')))->sort()->values()->all());
        // O que se prova aqui são as OPÇÕES dos filtros (o texto descritivo dos relatórios pode citar a palavra "saldos").
        $opcoes = json_encode($resposta->json('meta.opcoes'));
        $this->assertStringNotContainsString('saldo', $opcoes);
        $this->assertStringNotContainsString('4321', $opcoes);
        $this->assertStringNotContainsString('4321', $resposta->getContent());
    }

    public function test_relatorio_e_formato_desconhecidos_retornam_404_e_nao_existe_pdf(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->relatorioApi($pastor, 'inexistente')->assertStatus(404);
        $this->exportarApi($pastor, 'entradas', 'pdf')->assertStatus(404);
        $this->exportarApi($pastor, 'entradas', 'xls')->assertStatus(404);
        $this->exportarApi($pastor, 'inexistente', 'csv')->assertStatus(404);
        $this->assertSame(0, AuditLog::where('modulo', 'exportacoes')->count());
    }

    public function test_erros_seguem_o_formato_padrao_da_api_em_json(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->relatorioApi($this->como(PerfilSlug::Secretario), 'resumo')->assertStatus(403)->assertJsonStructure(['message']);
        $this->relatorioApi($pastor, 'entradas', ['ano_mes' => '2999-01'])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['ano_mes']]);
        $this->exportarApi($pastor, 'entradas', 'csv', ['ano_mes' => 'x'])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }
}
