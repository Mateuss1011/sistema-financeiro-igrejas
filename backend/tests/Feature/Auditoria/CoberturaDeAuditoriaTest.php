<?php

namespace Tests\Feature\Auditoria;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AnoMes;
use App\Support\CatalogoAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CHECKLIST DE COBERTURA DA AUDITORIA (Fase 10, plano seções 6 e 17).
 *
 * Cada item da lista "auditado, no mínimo" do plano é executado pela API real e o teste confere que um registro
 * foi gravado com o par (módulo, ação) esperado e o responsável correto. Exportações passaram a ser cobertas na
 * Fase 12. Item da lista que ainda não tem funcionalidade no sistema (e portanto não pode gerar log): alterações de
 * configurações (tela de Configurações, fase posterior) — entra no catálogo e neste checklist quando existir.
 */
class CoberturaDeAuditoriaTest extends TestCase
{
    use RefreshDatabase, CenarioAuditoria;

    /** Confere que existe log do par para o responsável (e, se informado, para o registro). */
    private function assertAuditado(string $modulo, string $acao, User $responsavel, ?int $registroId = null): void
    {
        $filtro = ['modulo' => $modulo, 'acao' => $acao, 'user_id' => $responsavel->id];
        if ($registroId !== null) {
            $filtro['registro_id'] = $registroId;
        }

        $this->assertDatabaseHas('audit_logs', $filtro);
    }

    private function postLogin(array $corpo)
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/v1/auth/login', $corpo);
    }

    private function perfilId(PerfilSlug $slug): int
    {
        return \App\Models\Perfil::where('slug', $slug->value)->value('id');
    }

    public function test_item_autenticacao_login_sucesso_falha_e_logout(): void
    {
        $usuario = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create(['password' => 'senha-correta-123']);
        $inativo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create(['password' => 'senha-correta-123', 'ativo' => false]);

        $this->postLogin(['email' => $usuario->email, 'password' => 'errada-errada'])->assertStatus(422);
        $this->assertAuditado('auth', 'login_failed', $usuario, $usuario->id);

        $this->postLogin(['email' => $inativo->email, 'password' => 'senha-correta-123'])->assertStatus(422);
        $this->assertAuditado('auth', 'login_failed', $inativo, $inativo->id);

        $this->postLogin(['email' => 'ninguem@example.com', 'password' => 'qualquer-coisa'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['modulo' => 'auth', 'acao' => 'login_failed', 'user_id' => null, 'registro_id' => null]);

        $this->postLogin(['email' => $usuario->email, 'password' => 'senha-correta-123'])->assertOk();
        $this->assertAuditado('auth', 'login', $usuario, $usuario->id);

        $this->actingAs($usuario)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertAuditado('auth', 'logout', $usuario, $usuario->id);
    }

    public function test_item_usuarios_criacao_edicao_desativacao_e_permissoes(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $criado = $this->actingAs($pastor)->postJson('/api/v1/usuarios', [
            'name' => 'Novo Tesoureiro',
            'email' => 'novo.tesoureiro@example.com',
            'password' => 'senha-valida-123!Zx',
            'perfil_id' => $this->perfilId(PerfilSlug::Tesoureiro),
        ])->assertCreated();
        $id = $criado->json('data.id');
        $this->assertAuditado('usuarios', 'created', $pastor, $id);

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$id}", ['name' => 'Renomeado'])->assertOk();
        $this->assertAuditado('usuarios', 'updated', $pastor, $id);

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$id}", ['ativo' => false])->assertOk();
        $this->assertAuditado('usuarios', 'deactivated', $pastor, $id);

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$id}", ['ativo' => true])->assertOk();
        $this->assertAuditado('usuarios', 'activated', $pastor, $id);

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$id}/permissoes-excecao", ['permissao' => 'despesas.estornar_paga'])->assertSuccessful();
        $this->assertAuditado('usuarios', 'permission_granted', $pastor, $id);

        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$id}/permissoes-excecao/despesas.estornar_paga")->assertSuccessful();
        $this->assertAuditado('usuarios', 'permission_revoked', $pastor, $id);

        // DELETE /usuarios/{id} é desativação (não existe exclusão de usuário).
        $antes = AuditLog::where('modulo', 'usuarios')->where('acao', 'deactivated')->count();
        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$id}")->assertOk();
        $this->assertSame($antes + 1, AuditLog::where('modulo', 'usuarios')->where('acao', 'deactivated')->count());
    }

    public function test_item_categorias_e_contas_crud_e_inativacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $categoriaId = $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => 'Cobertura', 'tipo' => 'entrada'])->assertCreated()->json('data.id');
        $this->assertAuditado('categorias', 'created', $pastor, $categoriaId);
        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoriaId}", ['nome' => 'Cobertura 2'])->assertOk();
        $this->assertAuditado('categorias', 'updated', $pastor, $categoriaId);
        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoriaId}", ['ativa' => false])->assertOk();
        $this->assertAuditado('categorias', 'deactivated', $pastor, $categoriaId);
        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoriaId}", ['ativa' => true])->assertOk();
        $this->assertAuditado('categorias', 'activated', $pastor, $categoriaId);
        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$categoriaId}")->assertSuccessful();
        $this->assertAuditado('categorias', 'deleted', $pastor, $categoriaId);

        $contaId = $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Conta Cob', 'tipo' => 'banco', 'saldo_inicial' => '10.00'])->assertCreated()->json('data.id');
        $this->assertAuditado('contas', 'created', $pastor, $contaId);
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$contaId}", ['nome' => 'Conta Cob 2'])->assertOk();
        $this->assertAuditado('contas', 'updated', $pastor, $contaId);
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$contaId}", ['ativa' => false])->assertOk();
        $this->assertAuditado('contas', 'deactivated', $pastor, $contaId);
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$contaId}", ['ativa' => true])->assertOk();
        $this->assertAuditado('contas', 'activated', $pastor, $contaId);
        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$contaId}")->assertSuccessful();
        $this->assertAuditado('contas', 'deleted', $pastor, $contaId);
    }

    public function test_item_entradas_criacao_e_estorno(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        $id = $this->actingAs($tesoureiro)->postJson('/api/v1/entradas', $this->payload($conta, $categoria))->assertCreated()->json('data.id');
        $this->assertAuditado('entradas', 'created', $tesoureiro, $id);

        $this->actingAs($tesoureiro)->postJson("/api/v1/entradas/{$id}/estornar", ['justificativa' => 'Lançada por engano'])->assertCreated();
        $this->assertAuditado('entradas', 'reversed', $tesoureiro, $id);
    }

    public function test_item_despesas_criacao_edicao_pagamento_cancelamento_exclusao_e_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Desp', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();

        $criar = fn () => $this->actingAs($pastor)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertCreated()->json('data.id');

        $id = $criar();
        $this->assertAuditado('despesas', 'created', $pastor, $id);

        $this->actingAs($pastor)->putJson("/api/v1/despesas/{$id}", ['descricao' => 'Descrição editada'])->assertOk();
        $this->assertAuditado('despesas', 'updated', $pastor, $id);

        $this->pagar($pastor, $id, $this->corpoPagamento($conta))->assertOk();
        $this->assertAuditado('despesas', 'paid', $pastor, $id);

        $this->estornarDespesa($pastor, $id)->assertCreated();
        $this->assertAuditado('despesas', 'reversed', $pastor, $id);

        $paraCancelar = $criar();
        $this->cancelar($pastor, $paraCancelar)->assertOk();
        $this->assertAuditado('despesas', 'canceled', $pastor, $paraCancelar);

        $paraExcluir = $criar();
        $this->actingAs($pastor)->deleteJson("/api/v1/despesas/{$paraExcluir}")->assertSuccessful();
        $this->assertAuditado('despesas', 'deleted', $pastor, $paraExcluir);
    }

    public function test_item_transferencias_e_estornos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $origem = $this->conta('Origem', 'banco', '500.00');
        $destino = $this->conta('Destino', 'banco', '0.00');

        $id = $this->transferir($pastor, $origem, $destino)->assertCreated()->json('data.id');
        $this->assertAuditado('transferencias', 'created', $pastor, $id);

        $this->estornarTransferencia($pastor, $id)->assertCreated();
        $this->assertAuditado('transferencias', 'reversed', $pastor, $id);
    }

    public function test_item_ajustes_de_saldo(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Conta Ajuste', 'banco', '100.00');

        $id = $this->ajustar($tesoureiro, $conta)->assertCreated()->json('data.id');

        $this->assertAuditado('ajustes_saldo', 'created', $tesoureiro, $id);
    }

    public function test_item_fechamento_e_reabertura_de_periodo_com_justificativa_e_periodo_afetado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->fecharApi($pastor, '2026-03')->assertOk();
        $this->reabrirApi($pastor, '2026-03', 'Correção de lançamento retroativo')->assertOk();

        $this->assertAuditado('periodos_financeiros', 'closed', $pastor);
        $this->assertAuditado('periodos_financeiros', 'reopened', $pastor);
        $reabertura = AuditLog::where('acao', 'reopened')->firstOrFail();
        $this->assertSame('Correção de lançamento retroativo', $reabertura->justificativa);
        $this->assertSame('2026-03', $reabertura->dados_novos['ano_mes']);
    }

    public function test_item_exportacoes_csv_e_xlsx_sao_auditadas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->actingAs($pastor)->get('/api/v1/relatorios/entradas/exportar/csv?ano_mes=' . AnoMes::corrente())->assertOk();
        $this->actingAs($pastor)->get('/api/v1/relatorios/entradas/exportar/xlsx?ano_mes=' . AnoMes::corrente())->assertOk();

        $this->assertAuditado('exportacoes', 'exported', $pastor);
        $this->assertSame(2, AuditLog::where('modulo', 'exportacoes')->where('acao', 'exported')->count());
    }

    public function test_todo_registro_carrega_responsavel_congelado_ip_e_user_agent(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->actingAs($pastor)->withHeaders(['User-Agent' => 'Teste-Cobertura/1.0'])
            ->postJson('/api/v1/categorias', ['nome' => 'Congelada', 'tipo' => 'entrada'])->assertCreated();

        $log = AuditLog::where('modulo', 'categorias')->firstOrFail();

        $this->assertSame($pastor->id, $log->user_id);
        $this->assertSame($pastor->name, $log->user_nome_congelado);
        $this->assertSame('pastor', $log->user_perfil_congelado);
        $this->assertNotNull($log->ip);
        $this->assertSame('Teste-Cobertura/1.0', $log->user_agent);
    }

    /**
     * Fecha o checklist nos dois sentidos: (1) tudo que o catálogo promete foi exercitado acima e gerou log;
     * (2) nada do que os testes acima geraram fica fora do catálogo (que alimenta os filtros da tela).
     */
    public function test_catalogo_e_exatamente_o_conjunto_de_pares_gerado_pelo_sistema(): void
    {
        $this->test_item_autenticacao_login_sucesso_falha_e_logout();
        $this->test_item_usuarios_criacao_edicao_desativacao_e_permissoes();
        $this->test_item_categorias_e_contas_crud_e_inativacao();
        $this->test_item_entradas_criacao_e_estorno();
        $this->test_item_despesas_criacao_edicao_pagamento_cancelamento_exclusao_e_estorno();
        $this->test_item_transferencias_e_estornos();
        $this->test_item_ajustes_de_saldo();
        $this->test_item_fechamento_e_reabertura_de_periodo_com_justificativa_e_periodo_afetado();
        $this->test_item_exportacoes_csv_e_xlsx_sao_auditadas();

        $esperado = array_map(fn (array $par) => $par[0] . '/' . $par[1], CatalogoAuditoria::pares());
        sort($esperado);

        $this->assertSame($esperado, $this->paresRegistrados());
    }

    /**
     * Rede estática: qualquer chamada nova a AuditoriaService::registrar() com literais em app/ precisa usar um
     * par (módulo, ação) já presente no catálogo — senão a tela de auditoria não conseguiria filtrá-lo.
     */
    public function test_toda_chamada_de_registro_no_codigo_usa_par_do_catalogo(): void
    {
        $pares = array_map(fn (array $par) => $par[0] . '/' . $par[1], CatalogoAuditoria::pares());
        $encontrados = 0;

        $arquivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($arquivos as $arquivo) {
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/registrar\(\s*acao:\s*'([a-z_]+)',\s*modulo:\s*'([a-z_]+)'/s", file_get_contents($arquivo->getPathname()), $achados, PREG_SET_ORDER);
            foreach ($achados as [, $acao, $modulo]) {
                $encontrados++;
                $this->assertContains("{$modulo}/{$acao}", $pares, "{$arquivo->getFilename()}: {$modulo}/{$acao} fora do catálogo");
            }
        }

        $this->assertGreaterThanOrEqual(20, $encontrados, 'A varredura estática deveria achar as chamadas de registro existentes.');
    }
}
