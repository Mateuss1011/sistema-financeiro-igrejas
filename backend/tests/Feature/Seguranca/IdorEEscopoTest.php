<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\Despesa;
use App\Models\Entrada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 13 — IDOR e acesso por ID. O servidor decide o que cada usuário pode alcançar; conhecer (ou adivinhar) o id de um
 * recurso nunca basta. Cobre: isolamento do Auxiliar (só o que ele criou), ids malformados na rota, hierarquia entre
 * perfis na gestão de usuários e a inacessibilidade de logs e períodos por quem não tem o direito.
 */
class IdorEEscopoTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    public function test_auxiliar_nunca_enxerga_registros_de_outro_auxiliar_em_nenhuma_tela_da_api(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(1));
        $mes = $m->mes;
        $entradaDoOutro = $this->entrada($m->contaA, $m->catE1, $m->aux2, '77.00');
        $despesaDoOutro = $this->despesaPendente($m->catD1, $m->aux2);
        $idsAlheios = ['entradas' => $entradaDoOutro->id, 'despesas' => $despesaDoOutro->id];

        foreach (['entradas', 'despesas'] as $lista) {
            $this->novaRequisicao();
            $ids = array_column($this->actingAs($m->aux1)->getJson("/api/v1/{$lista}?por_pagina=100")->assertOk()->json('data'), 'id');
            $this->assertNotContains($idsAlheios[$lista], $ids, "aux1 viu registro de aux2 em /{$lista}");
            $proprios = ($lista === 'entradas' ? Entrada::class : Despesa::class)::query()->where('criado_por', $m->aux1->id)->pluck('id')->all();
            foreach ($ids as $id) {
                $this->assertContains($id, $proprios, "/{$lista} do Auxiliar devolveu um id que ele não criou");
            }
        }

        foreach (['entradas', 'despesas', 'movimentacoes'] as $relatorio) {
            $this->novaRequisicao();
            $resposta = $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/{$relatorio}?ano_mes={$mes}&por_pagina=100")->assertOk();
            if ($relatorio !== 'movimentacoes') {
                $this->assertNotContains($idsAlheios[$relatorio], array_column($resposta->json('data'), 'id'), "aux1 viu registro de aux2 no relatório {$relatorio}");
            }
        }

        // Os registros de aux2 criados acima são do MÊS ATUAL: é nele que o escopo precisa esconder o que não é do Auxiliar.
        $atual = $this->mesAtual();
        foreach (['entradas', 'despesas'] as $relatorio) {
            $this->novaRequisicao();
            $resposta = $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/{$relatorio}?ano_mes={$atual}&por_pagina=100")->assertOk();
            $this->assertNotContains($idsAlheios[$relatorio], array_column($resposta->json('data'), 'id'), "aux1 viu registro de aux2 no relatório {$relatorio} do mês atual");
        }
        $this->novaRequisicao();
        $propriosPendentes = Despesa::query()->where('criado_por', $m->aux1->id)->where('status', 'pendente')->whereBetween('data_competencia', \App\Support\AnoMes::limites($atual))->count();
        $dashAtual = $this->actingAs($m->aux1)->getJson("/api/v1/dashboard?ano_mes={$atual}")->assertOk();
        $this->assertSame($propriosPendentes, $dashAtual->json('data.despesas_pendentes.quantidade'), 'Dashboard do Auxiliar contou despesas de outro usuário');

        $this->novaRequisicao();
        $dash = $this->actingAs($m->aux1)->getJson("/api/v1/dashboard?ano_mes={$mes}")->assertOk();
        $this->assertSame('proprios', $dash->json('data.escopo'));
        $this->assertArrayNotHasKey('saldo', $dash->json('data'));
    }

    public function test_auxiliar_nao_age_sobre_recursos_alheios_nem_sobre_os_proprios_alem_do_que_o_perfil_permite(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $aux = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $conta = $this->conta('A', 'banco', '500.00');
        $cat = $this->categoria('Dz');
        $catD = $this->categoriaDespesa('En');
        $deTerceiro = $this->entrada($conta, $cat, $tesoureiro);
        $despesaDeTerceiro = $this->despesaPendente($catD, $tesoureiro);
        $propria = $this->despesaPendente($catD, $aux);

        $this->actingAs($aux)->postJson("/api/v1/entradas/{$deTerceiro->id}/estornar", ['justificativa' => 'quero estornar'])->assertForbidden();
        foreach ([$despesaDeTerceiro, $propria] as $despesa) {
            $this->novaRequisicao();
            $this->actingAs($aux)->putJson("/api/v1/despesas/{$despesa->id}", ['descricao' => 'alterada'])->assertForbidden();
            $this->novaRequisicao();
            $this->actingAs($aux)->deleteJson("/api/v1/despesas/{$despesa->id}")->assertForbidden();
            $this->novaRequisicao();
            $this->actingAs($aux)->postJson("/api/v1/despesas/{$despesa->id}/pagar", ['conta_id' => $conta->id, 'data_pagamento' => $this->hoje()])->assertForbidden();
            $this->novaRequisicao();
            $this->actingAs($aux)->postJson("/api/v1/despesas/{$despesa->id}/cancelar", ['justificativa' => 'cancelar'])->assertForbidden();
        }

        $this->assertSame('confirmada', $deTerceiro->fresh()->status->value ?? $deTerceiro->fresh()->status);
        $this->assertNotSame('alterada', $despesaDeTerceiro->fresh()->descricao);
        $this->assertNotSame('alterada', $propria->fresh()->descricao);
    }

    public function test_ids_malformados_ou_fora_de_faixa_na_rota_dao_404_e_nunca_alcancam_outro_registro(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $primeiro = $this->como(PerfilSlug::Tesoureiro); // id conhecido para o truque "1abc" => 1
        $conta = $this->conta('A', 'banco', '50.00');
        $cat = $this->categoria('Dz');
        $entrada = $this->entrada($conta, $cat, $pastor);
        $despesa = $this->despesaPendente($this->categoriaDespesa('En'), $pastor);

        $maus = fn (int $id) => [$id . 'abc', $id . '.5', $id . ' OR 1=1', '-' . $id, '0', '00' . $id . 'x', '1e3', '%00', '99999999999999999999999', '', 'null', 'true'];

        $rotas = [
            ['putJson', 'usuarios', $primeiro->id], ['deleteJson', 'usuarios', $primeiro->id],
            ['putJson', 'contas', $conta->id], ['deleteJson', 'contas', $conta->id],
            ['putJson', 'categorias', $cat->id], ['deleteJson', 'categorias', $cat->id],
            ['putJson', 'despesas', $despesa->id], ['deleteJson', 'despesas', $despesa->id],
            ['postJson', 'despesas/{id}/pagar', $despesa->id], ['postJson', 'despesas/{id}/cancelar', $despesa->id],
            ['postJson', 'despesas/{id}/estornar', $despesa->id], ['postJson', 'entradas/{id}/estornar', $entrada->id],
            ['postJson', 'transferencias/{id}/estornar', 1],
        ];

        foreach ($rotas as [$metodo, $modelo, $idReal]) {
            foreach ($maus($idReal) as $ruim) {
                $uri = str_contains($modelo, '{id}') ? str_replace('{id}', rawurlencode($ruim), $modelo) : "{$modelo}/" . rawurlencode($ruim);
                $this->novaRequisicao();
                $resposta = $this->actingAs($pastor)->$metodo("/api/v1/{$uri}", []);
                $this->assertContains($resposta->status(), [404, 405, 422], "{$metodo} /{$uri} => {$resposta->status()} (id malformado não pode resolver um registro; 422 = a validação do corpo vem antes da busca)");
                $this->assertSemVazamento($resposta);
            }
        }

        $this->assertNotNull($primeiro->fresh(), 'Nenhum usuário pode ter sido afetado pelos ids malformados.');
        $this->assertTrue((bool) $primeiro->fresh()->ativo);
    }

    public function test_hierarquia_de_usuarios_nenhum_perfil_age_acima_do_proprio_nivel(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $admin = $this->como(PerfilSlug::Administrador);
        $outroAdmin = $this->como(PerfilSlug::Administrador);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $secretario = $this->como(PerfilSlug::Secretario);

        // Administrador não toca em Pastor nem em outro Administrador (sem a exceção do Pastor).
        foreach ([$pastor, $outroAdmin, $admin] as $alvo) {
            $this->novaRequisicao();
            $this->actingAs($admin)->putJson("/api/v1/usuarios/{$alvo->id}", ['name' => 'X'])->assertForbidden();
            $this->novaRequisicao();
            $this->actingAs($admin)->deleteJson("/api/v1/usuarios/{$alvo->id}")->assertForbidden();
        }
        // Tesoureiro e Secretário: nada de escrita em usuários; o Secretário só LÊ a lista.
        foreach ([$tesoureiro, $secretario] as $ator) {
            foreach ([$pastor, $admin, $tesoureiro, $secretario] as $alvo) {
                $this->novaRequisicao();
                $this->actingAs($ator)->putJson("/api/v1/usuarios/{$alvo->id}", ['name' => 'X'])->assertForbidden();
                $this->novaRequisicao();
                $this->actingAs($ator)->deleteJson("/api/v1/usuarios/{$alvo->id}")->assertForbidden();
                $this->novaRequisicao();
                $this->actingAs($ator)->postJson("/api/v1/usuarios/{$alvo->id}/permissoes-excecao", ['permissao' => 'entradas.operar'])->assertForbidden();
                $this->novaRequisicao();
                $this->actingAs($ator)->deleteJson("/api/v1/usuarios/{$alvo->id}/permissoes-excecao/entradas.operar")->assertForbidden();
            }
        }
        // Só o Pastor concede/revoga exceções: nem o Administrador.
        $this->novaRequisicao();
        $this->actingAs($admin)->postJson("/api/v1/usuarios/{$tesoureiro->id}/permissoes-excecao", ['permissao' => 'entradas.operar'])->assertForbidden();

        $this->assertTrue((bool) $this->recarregado($pastor)->ativo);
    }

    public function test_auditoria_e_gestao_de_periodos_nao_vazam_por_id_nem_por_filtro_para_quem_nao_pode(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $aux = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $secretario = $this->como(PerfilSlug::Secretario);
        $this->conta('A', 'banco', '10.00');

        foreach ([$tesoureiro, $aux, $secretario] as $ator) {
            foreach (['/api/v1/auditoria', '/api/v1/auditoria/catalogo', "/api/v1/auditoria?user_id={$pastor->id}", "/api/v1/auditoria?registro_id=1&modulo=usuarios"] as $uri) {
                $this->novaRequisicao();
                $this->actingAs($ator)->getJson($uri)->assertForbidden();
            }
        }
        foreach ([$aux, $secretario] as $ator) {
            $this->novaRequisicao();
            $this->actingAs($ator)->getJson('/api/v1/periodos-financeiros')->assertForbidden();
            $this->novaRequisicao();
            $this->actingAs($ator)->postJson('/api/v1/periodos-financeiros/2026-01/fechar')->assertForbidden();
        }
        // Reabrir é só do Pastor.
        $this->novaRequisicao();
        $this->actingAs($tesoureiro)->postJson('/api/v1/periodos-financeiros/2026-01/reabrir', ['justificativa' => 'quero reabrir'])->assertForbidden();
    }

    public function test_relatorios_e_exportacao_respeitam_o_perfil_e_o_escopo_por_id_de_filtro(): void
    {
        $m = (object) $this->massaDoMes($this->mesPassado(1));
        $mes = $m->mes;

        // Auxiliar filtrando por conta/categoria de outros: continua só vendo o que criou (o filtro apenas restringe mais).
        $this->novaRequisicao();
        $a = $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/entradas?ano_mes={$mes}&por_pagina=100")->json('meta.totais');
        $this->novaRequisicao();
        $b = $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/entradas?ano_mes={$mes}&conta_id={$m->contaA->id}&por_pagina=100")->assertOk()->json('meta.totais');
        $this->assertLessThanOrEqual(3, (int) $b[0]['valor']);
        $this->assertNotNull($a);

        // Saldos e exportação: 403 para o Auxiliar, mesmo com o id/slug certo e qualquer parâmetro.
        $this->novaRequisicao();
        $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/saldos?ano_mes={$mes}&visao=completo")->assertForbidden();
        foreach (['csv', 'xlsx'] as $formato) {
            $this->novaRequisicao();
            $this->actingAs($m->aux1)->get("/api/v1/relatorios/entradas/exportar/{$formato}?ano_mes={$mes}&escopo=todos")->assertForbidden();
        }
    }
}
