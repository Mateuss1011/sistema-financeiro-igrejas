<?php

namespace Tests\Feature\Auditoria;

use App\Enums\PerfilSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Filtros, ordenação, paginação, formato da resposta e catálogo da consulta de auditoria (Fase 10). */
class ConsultaDeAuditoriaTest extends TestCase
{
    use RefreshDatabase, CenarioAuditoria;

    private function pastor(): User
    {
        return $this->como(PerfilSlug::Pastor);
    }

    private function ids($resposta): array
    {
        return array_column($resposta->json('data'), 'id');
    }

    // ---------------------------------------------------------------- filtros

    public function test_sem_filtros_devolve_tudo_do_mais_recente_para_o_mais_antigo(): void
    {
        $a = $this->log([], '2026-05-01 10:00:00');
        $b = $this->log([], '2026-05-03 10:00:00');
        $c = $this->log([], '2026-05-02 10:00:00');

        $resposta = $this->consultarAuditoria($this->pastor())->assertOk();

        $this->assertSame([$b->id, $c->id, $a->id], $this->ids($resposta));
        $resposta->assertJsonPath('meta.total', 3);
    }

    public function test_filtra_por_modulo(): void
    {
        $conta = $this->log(['modulo' => 'contas']);
        $this->log(['modulo' => 'entradas']);

        $resposta = $this->consultarAuditoria($this->pastor(), ['modulo' => 'contas'])->assertOk();

        $this->assertSame([$conta->id], $this->ids($resposta));
    }

    public function test_filtra_por_acao(): void
    {
        $this->log(['acao' => 'created']);
        $estorno = $this->log(['acao' => 'reversed', 'modulo' => 'entradas']);

        $resposta = $this->consultarAuditoria($this->pastor(), ['acao' => 'reversed'])->assertOk();

        $this->assertSame([$estorno->id], $this->ids($resposta));
    }

    public function test_filtra_por_usuario_responsavel(): void
    {
        $maria = $this->como(PerfilSlug::Tesoureiro);
        $joao = $this->como(PerfilSlug::Pastor);
        $daMaria = $this->log(['user_id' => $maria->id, 'user_nome_congelado' => $maria->name]);
        $this->log(['user_id' => $joao->id, 'user_nome_congelado' => $joao->name]);

        $resposta = $this->consultarAuditoria($this->pastor(), ['user_id' => $maria->id])->assertOk();

        $this->assertSame([$daMaria->id], $this->ids($resposta));
    }

    public function test_filtra_eventos_sem_usuario_como_login_falho_de_email_inexistente(): void
    {
        $pastor = $this->pastor();
        $anonimo = $this->log(['modulo' => 'auth', 'acao' => 'login_failed', 'justificativa' => 'Credenciais inválidas']);
        $this->log(['user_id' => $pastor->id, 'user_nome_congelado' => $pastor->name]);

        $resposta = $this->consultarAuditoria($pastor, ['sem_usuario' => 'true'])->assertOk();

        $this->assertSame([$anonimo->id], $this->ids($resposta));
        $resposta->assertJsonPath('data.0.usuario.id', null);
    }

    public function test_sem_usuario_false_nao_restringe(): void
    {
        $this->log();
        $this->log(['user_id' => $this->pastor()->id]);

        $this->consultarAuditoria($this->pastor(), ['sem_usuario' => 'false'])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_filtra_por_registro_id(): void
    {
        $alvo = $this->log(['modulo' => 'despesas', 'registro_id' => 77]);
        $this->log(['modulo' => 'despesas', 'registro_id' => 78]);
        $this->log(['modulo' => 'despesas', 'registro_id' => null]);

        $resposta = $this->consultarAuditoria($this->pastor(), ['modulo' => 'despesas', 'registro_id' => 77])->assertOk();

        $this->assertSame([$alvo->id], $this->ids($resposta));
    }

    public function test_combina_filtros_com_e(): void
    {
        $alvo = $this->log(['modulo' => 'contas', 'acao' => 'deactivated', 'registro_id' => 5]);
        $this->log(['modulo' => 'contas', 'acao' => 'created', 'registro_id' => 5]);
        $this->log(['modulo' => 'categorias', 'acao' => 'deactivated', 'registro_id' => 5]);
        $this->log(['modulo' => 'contas', 'acao' => 'deactivated', 'registro_id' => 6]);

        $resposta = $this->consultarAuditoria($this->pastor(), ['modulo' => 'contas', 'acao' => 'deactivated', 'registro_id' => 5])->assertOk();

        $this->assertSame([$alvo->id], $this->ids($resposta));
    }

    public function test_intervalo_de_datas_usa_o_dia_do_fuso_da_igreja_e_e_inclusivo_nas_duas_pontas(): void
    {
        // America/Sao_Paulo = UTC-3. O dia 2026-05-10 em Brasília vai de 2026-05-10 03:00:00 a 2026-05-11 02:59:59 UTC.
        $antes = $this->log([], '2026-05-10 02:59:59');   // 09/05 23:59:59 em Brasília: fora
        $inicio = $this->log([], '2026-05-10 03:00:00');   // 10/05 00:00:00: dentro
        $noite = $this->log([], '2026-05-11 02:59:59');    // 10/05 23:59:59: dentro
        $depois = $this->log([], '2026-05-11 03:00:00');   // 11/05 00:00:00: fora

        $resposta = $this->consultarAuditoria($this->pastor(), ['data_de' => '2026-05-10', 'data_ate' => '2026-05-10'])->assertOk();

        $ids = $this->ids($resposta);
        $this->assertEqualsCanonicalizing([$inicio->id, $noite->id], $ids);
        $this->assertNotContains($antes->id, $ids);
        $this->assertNotContains($depois->id, $ids);
    }

    public function test_so_data_de_ou_so_data_ate(): void
    {
        $a = $this->log([], '2026-05-01 12:00:00');
        $b = $this->log([], '2026-05-10 12:00:00');
        $c = $this->log([], '2026-05-20 12:00:00');

        $desde = $this->consultarAuditoria($this->pastor(), ['data_de' => '2026-05-10'])->assertOk();
        $ate = $this->consultarAuditoria($this->pastor(), ['data_ate' => '2026-05-10'])->assertOk();

        $this->assertEqualsCanonicalizing([$b->id, $c->id], $this->ids($desde));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($ate));
    }

    public function test_sem_resultado_devolve_lista_vazia_e_total_zero(): void
    {
        $this->log(['modulo' => 'contas']);

        $this->consultarAuditoria($this->pastor(), ['modulo' => 'entradas'])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    // ---------------------------------------------------------------- validação dos filtros

    public function test_filtros_invalidos_retornam_422(): void
    {
        $pastor = $this->pastor();

        foreach ([
            ['modulo' => 'inexistente'],
            ['acao' => 'inexistente'],
            ['user_id' => 'abc'],
            ['user_id' => 0],
            ['registro_id' => -1],
            ['sem_usuario' => 'talvez'],
            ['data_de' => '10/05/2026'],
            ['data_ate' => '2026-13-40'],
            ['data_de' => '2026-05-10', 'data_ate' => '2026-05-09'],
            ['ordenar' => 'dados_novos'],
            ['ordenar' => '-created_at,ip'],
            ['por_pagina' => 0],
            ['por_pagina' => 101],
            ['page' => 0],
        ] as $filtro) {
            $this->consultarAuditoria($pastor, $filtro)->assertStatus(422);
        }
    }

    public function test_tentativa_de_injecao_nos_filtros_e_tratada_como_valor_invalido_ou_literal(): void
    {
        $this->log(['modulo' => 'contas']);
        $pastor = $this->pastor();

        $this->consultarAuditoria($pastor, ['modulo' => "contas' OR '1'='1"])->assertStatus(422);
        $this->consultarAuditoria($pastor, ['ordenar' => 'id; DROP TABLE audit_logs'])->assertStatus(422);
        $this->consultarAuditoria($pastor, ['registro_id' => '1 OR 1=1'])->assertStatus(422);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    // ---------------------------------------------------------------- ordenação e paginação

    public function test_ordenacao_por_data_crescente_e_por_modulo_e_acao(): void
    {
        $a = $this->log(['modulo' => 'entradas', 'acao' => 'reversed'], '2026-05-01 10:00:00');
        $b = $this->log(['modulo' => 'contas', 'acao' => 'created'], '2026-05-02 10:00:00');
        $c = $this->log(['modulo' => 'contas', 'acao' => 'activated'], '2026-05-03 10:00:00');

        $pastor = $this->pastor();

        $this->assertSame([$a->id, $b->id, $c->id], $this->ids($this->consultarAuditoria($pastor, ['ordenar' => 'created_at'])));
        $this->assertSame([$c->id, $b->id, $a->id], $this->ids($this->consultarAuditoria($pastor, ['ordenar' => '-created_at'])));
        $this->assertSame([$c->id, $b->id, $a->id], $this->ids($this->consultarAuditoria($pastor, ['ordenar' => 'modulo,acao'])));
        $this->assertSame([$a->id, $b->id, $c->id], $this->ids($this->consultarAuditoria($pastor, ['ordenar' => '-modulo,-acao'])));
    }

    public function test_id_desempata_registros_com_a_mesma_data(): void
    {
        $a = $this->log([], '2026-05-01 10:00:00');
        $b = $this->log([], '2026-05-01 10:00:00');
        $c = $this->log([], '2026-05-01 10:00:00');

        $pastor = $this->pastor();

        $this->assertSame([$c->id, $b->id, $a->id], $this->ids($this->consultarAuditoria($pastor)));
        $this->assertSame([$a->id, $b->id, $c->id], $this->ids($this->consultarAuditoria($pastor, ['ordenar' => 'created_at'])));
    }

    public function test_paginacao_percorre_todos_sem_repetir_nem_perder(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->log([], sprintf('2026-05-%02d 10:00:00', $i));
        }
        $pastor = $this->pastor();

        $pagina1 = $this->consultarAuditoria($pastor, ['por_pagina' => 3, 'page' => 1])->assertOk();
        $pagina2 = $this->consultarAuditoria($pastor, ['por_pagina' => 3, 'page' => 2])->assertOk();
        $pagina3 = $this->consultarAuditoria($pastor, ['por_pagina' => 3, 'page' => 3])->assertOk();

        $pagina1->assertJsonPath('meta.total', 7)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 3);
        $this->assertCount(3, $pagina1->json('data'));
        $this->assertCount(3, $pagina2->json('data'));
        $this->assertCount(1, $pagina3->json('data'));

        $todos = array_merge($this->ids($pagina1), $this->ids($pagina2), $this->ids($pagina3));
        $this->assertCount(7, array_unique($todos));
    }

    public function test_por_pagina_padrao_e_20_e_maximo_100(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->log();
        }
        $pastor = $this->pastor();

        $this->consultarAuditoria($pastor)->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->assertCount(20, $this->consultarAuditoria($pastor)->json('data'));
        $this->consultarAuditoria($pastor, ['por_pagina' => 100])->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_pagina_alem_do_fim_devolve_lista_vazia(): void
    {
        $this->log();

        $this->consultarAuditoria($this->pastor(), ['page' => 5])->assertOk()->assertJsonPath('data', []);
    }

    // ---------------------------------------------------------------- formato da resposta

    public function test_resposta_traz_os_campos_esperados_com_rotulos_em_portugues(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $log = $this->log([
            'user_id' => $tesoureiro->id,
            'user_nome_congelado' => 'Maria Tesoureira',
            'user_perfil_congelado' => 'tesoureiro',
            'modulo' => 'periodos_financeiros',
            'acao' => 'reopened',
            'registro_id' => 12,
            'dados_anteriores' => ['status' => 'fechado'],
            'dados_novos' => ['ano_mes' => '2026-03', 'status' => 'aberto'],
            'justificativa' => 'Lançamento retroativo necessário',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 Teste',
        ]);

        $resposta = $this->consultarAuditoria($this->pastor())->assertOk();

        $resposta->assertJsonPath('data.0', [
            'id' => $log->id,
            'created_at' => $log->created_at->toJSON(),
            'modulo' => 'periodos_financeiros',
            'modulo_rotulo' => 'Fechamento de período',
            'acao' => 'reopened',
            'acao_rotulo' => 'Reabertura',
            'registro_id' => 12,
            'usuario' => ['id' => $tesoureiro->id, 'nome' => 'Maria Tesoureira', 'perfil' => 'tesoureiro'],
            'justificativa' => 'Lançamento retroativo necessário',
            'dados_anteriores' => ['status' => 'fechado'],
            'dados_novos' => ['ano_mes' => '2026-03', 'status' => 'aberto'],
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 Teste',
        ]);
        $resposta->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_responsavel_vem_do_nome_congelado_e_nao_muda_se_o_usuario_for_renomeado(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);
        $usuario->update(['name' => 'Nome Antigo']);
        $this->log(['user_id' => $usuario->id, 'user_nome_congelado' => 'Nome Antigo', 'user_perfil_congelado' => 'tesoureiro']);

        $usuario->update(['name' => 'Nome Novo']);

        $this->consultarAuditoria($this->pastor())->assertOk()
            ->assertJsonPath('data.0.usuario.nome', 'Nome Antigo')
            ->assertJsonPath('data.0.usuario.perfil', 'tesoureiro');
    }

    public function test_resposta_nunca_expoe_senha_token_nem_email_do_responsavel(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);
        $this->log([
            'user_id' => $usuario->id,
            'user_nome_congelado' => $usuario->name,
            'user_perfil_congelado' => 'tesoureiro',
            'modulo' => 'usuarios',
            'dados_novos' => [
                'name' => 'X',
                'password' => 'segredo-em-claro',
                'nested' => ['remember_token' => 'abc123', 'api_token' => 'tok', 'senha_atual' => 's3nh4', 'ok' => 'visivel'],
            ],
            'dados_anteriores' => ['Password' => 'outro-segredo'],
        ]);

        $resposta = $this->consultarAuditoria($this->pastor())->assertOk();
        $corpo = $resposta->getContent();

        $this->assertStringNotContainsString('segredo-em-claro', $corpo);
        $this->assertStringNotContainsString('outro-segredo', $corpo);
        $this->assertStringNotContainsString('abc123', $corpo);
        $this->assertStringNotContainsString('s3nh4', $corpo);
        $this->assertStringNotContainsString($usuario->email, $corpo);
        $resposta->assertJsonPath('data.0.dados_novos.password', '[oculto]')
            ->assertJsonPath('data.0.dados_novos.nested.remember_token', '[oculto]')
            ->assertJsonPath('data.0.dados_novos.nested.api_token', '[oculto]')
            ->assertJsonPath('data.0.dados_novos.nested.senha_atual', '[oculto]')
            ->assertJsonPath('data.0.dados_novos.nested.ok', 'visivel')
            ->assertJsonPath('data.0.dados_anteriores.Password', '[oculto]');
        // O registro guardado no banco não é alterado pela ocultação (só a resposta).
        $this->assertDatabaseHas('audit_logs', ['modulo' => 'usuarios']);
    }

    public function test_registro_sem_dados_traz_nulos(): void
    {
        $this->log(['modulo' => 'auth', 'acao' => 'logout']);

        $this->consultarAuditoria($this->pastor())->assertOk()
            ->assertJsonPath('data.0.dados_anteriores', null)
            ->assertJsonPath('data.0.dados_novos', null)
            ->assertJsonPath('data.0.justificativa', null);
    }

    public function test_modulo_desconhecido_gravado_no_banco_aparece_com_o_proprio_valor_como_rotulo(): void
    {
        // (Fase 12: "exportacoes" passou a existir no catálogo; o exemplo de módulo desconhecido agora é outro.)
        $this->log(['modulo' => 'modulo_futuro', 'acao' => 'acao_futura']);

        $this->consultarAuditoria($this->pastor())->assertOk()
            ->assertJsonPath('data.0.modulo_rotulo', 'modulo_futuro')
            ->assertJsonPath('data.0.acao_rotulo', 'acao_futura');
    }

    public function test_consultar_nao_gera_novos_registros_de_auditoria(): void
    {
        $this->log();
        $antes = \App\Models\AuditLog::count();

        $this->consultarAuditoria($this->pastor(), ['modulo' => 'categorias'])->assertOk();
        $this->catalogoAuditoria($this->pastor())->assertOk();

        $this->assertSame($antes, \App\Models\AuditLog::count());
    }

    // ---------------------------------------------------------------- catálogo

    public function test_catalogo_traz_modulos_com_acoes_rotuladas(): void
    {
        $resposta = $this->catalogoAuditoria($this->pastor())->assertOk();

        $modulos = collect($resposta->json('data.modulos'));
        $this->assertSame(
            ['auth', 'usuarios', 'categorias', 'contas', 'entradas', 'despesas', 'transferencias', 'ajustes_saldo', 'periodos_financeiros', 'exportacoes'],
            $modulos->pluck('valor')->all()
        );

        $despesas = $modulos->firstWhere('valor', 'despesas');
        $this->assertSame('Despesas', $despesas['rotulo']);
        $this->assertSame(
            ['created', 'updated', 'paid', 'canceled', 'reversed', 'deleted'],
            array_column($despesas['acoes'], 'valor')
        );
        $this->assertSame('Pagamento', collect($despesas['acoes'])->firstWhere('valor', 'paid')['rotulo']);
    }

    public function test_catalogo_lista_so_usuarios_com_log_e_apenas_id_e_nome(): void
    {
        $comLog = $this->como(PerfilSlug::Tesoureiro);
        $excluido = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $semLog = $this->como(PerfilSlug::Secretario);
        $this->log(['user_id' => $comLog->id]);
        $this->log(['user_id' => $comLog->id]);
        $this->log(['user_id' => $excluido->id]);
        $excluido->delete(); // soft delete: o histórico continua filtrável por ele.

        $resposta = $this->catalogoAuditoria($this->pastor())->assertOk();

        $usuarios = $resposta->json('data.usuarios');
        $this->assertEqualsCanonicalizing([$comLog->id, $excluido->id], array_column($usuarios, 'id'));
        $this->assertNotContains($semLog->id, array_column($usuarios, 'id'));
        foreach ($usuarios as $usuario) {
            $this->assertSame(['id', 'nome'], array_keys($usuario));
        }
        $this->assertStringNotContainsString($comLog->email, $resposta->getContent());
    }

    public function test_catalogo_sem_logs_devolve_usuarios_vazio(): void
    {
        $this->catalogoAuditoria($this->pastor())->assertOk()->assertJsonPath('data.usuarios', []);
    }
}
