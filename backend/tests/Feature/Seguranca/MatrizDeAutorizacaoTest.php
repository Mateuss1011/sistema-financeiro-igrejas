<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\Transferencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Autorização real, pela API, para TODOS os perfis e TODAS as rotas autenticadas. Cada rota é chamada com payload vazio:
 * `authorize()` roda antes das regras de validação, então 403 = perfil barrado; qualquer outra resposta (422, 200, 404,
 * 409) = o perfil passou pela autorização. A matriz abaixo é o contrato aprovado nas Fases 2–12 (pastor P, administrador
 * A, tesoureiro T, auxiliar X, secretário S); qualquer mudança de permissão precisa mudar este teste de propósito.
 */
class MatrizDeAutorizacaoTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    /** método + rota => perfis que PASSAM pela autorização. */
    private const MATRIZ = [
        'GET contas' => 'PATX',
        'POST contas' => 'PA',
        'PUT contas/{conta}' => 'PA',
        'DELETE contas/{conta}' => 'P',
        'GET categorias' => 'PATXS',
        'POST categorias' => 'PA',
        'PUT categorias/{cat}' => 'PA',
        'DELETE categorias/{cat}' => 'PA',
        'GET entradas' => 'PATX',
        'POST entradas' => 'PTX',
        'POST entradas/{entrada}/estornar' => 'PT',
        'GET despesas' => 'PATX',
        'POST despesas' => 'PTX',
        'PUT despesas/{despesa}' => 'PT',
        'DELETE despesas/{despesa}' => 'P',
        'POST despesas/{despesa}/pagar' => 'PT',
        'POST despesas/{despesa}/cancelar' => 'PT',
        'POST despesas/{despesaP}/estornar' => 'P',
        'GET transferencias' => 'PAT',
        'POST transferencias' => 'PT',
        'POST transferencias/{transf}/estornar' => 'PT',
        'GET ajustes' => 'PAT',
        'POST ajustes' => 'PT',
        'GET periodos-financeiros' => 'PAT',
        'POST periodos-financeiros/2026-01/fechar' => 'PT',
        'POST periodos-financeiros/2026-01/reabrir' => 'P',
        'GET relatorios' => 'PATX',
        'GET relatorios/resumo' => 'PATX',
        'GET relatorios/entradas' => 'PATX',
        'GET relatorios/saldos' => 'PAT',
        'GET relatorios/entradas/exportar/csv' => 'PAT',
        'GET relatorios/entradas/exportar/xlsx' => 'PAT',
        'GET dashboard' => 'PATX',
        'GET auditoria' => 'PA',
        'GET auditoria/catalogo' => 'PA',
        'GET perfis' => 'PAS',
        'GET permissoes-excecao' => 'P',
        'GET usuarios' => 'PAS',
        'POST usuarios' => 'PA',
        'PUT usuarios/{u}' => 'PA',
        'DELETE usuarios/{u}' => 'PA',
        'POST usuarios/{u}/permissoes-excecao' => 'P',
        'DELETE usuarios/{u}/permissoes-excecao/x' => 'P',
        'GET auth/me' => 'PATXS',
    ];

    private const LETRAS = [
        'P' => PerfilSlug::Pastor,
        'A' => PerfilSlug::Administrador,
        'T' => PerfilSlug::Tesoureiro,
        'X' => PerfilSlug::AuxiliarFinanceiro,
        'S' => PerfilSlug::Secretario,
    ];

    public function test_toda_rota_autenticada_da_api_exige_autenticacao(): void
    {
        $publicas = ['api/v1/health', 'api/v1/auth/login'];
        $vistas = 0;

        foreach (Route::getRoutes() as $rota) {
            if (! str_starts_with($rota->uri(), 'api/v1') || in_array($rota->uri(), $publicas, true)) {
                continue;
            }
            $vistas++;
            $this->assertContains('auth:sanctum', $rota->gatherMiddleware(), "Rota sem autenticação: {$rota->uri()}");
        }

        $this->assertGreaterThan(30, $vistas);
    }

    public function test_matriz_de_autorizacao_por_perfil_e_por_rota(): void
    {
        $pastorBase = $this->como(PerfilSlug::Pastor);
        $contaA = $this->conta('A', 'banco', '1000.00');
        $contaB = $this->conta('B', 'caixa', '10.00');
        $catE = $this->categoria('Dízimos');
        $catD = $this->categoriaDespesa('Energia');
        $alvo = $this->como(PerfilSlug::Tesoureiro);
        $atores = array_map(fn (PerfilSlug $p) => $this->como($p), self::LETRAS);

        $divergencias = [];
        foreach (array_merge(['-' => null], $atores) as $letra => $ator) {
            foreach (self::MATRIZ as $chave => $permitidos) {
                [$metodo, $rota] = explode(' ', $chave, 2);

                // Cada chamada roda num savepoint: os alvos nascem novos e nada vaza para a chamada seguinte.
                DB::beginTransaction();
                $entrada = $this->entrada($contaA, $catE, $pastorBase, '10.00');
                $despesa = $this->despesaPendente($catD, $pastorBase);
                $despesaPaga = $this->despesaPaga($contaA, $catD, $pastorBase);
                $transf = Transferencia::create(['conta_origem_id' => $contaA->id, 'conta_destino_id' => $contaB->id, 'valor' => '5.00',
                    'data_transferencia' => $this->hoje(), 'status' => 'confirmada', 'criado_por' => $pastorBase->id]);
                $uri = '/api/v1/' . strtr($rota, ['{conta}' => $contaB->id, '{cat}' => $catE->id, '{entrada}' => $entrada->id,
                    '{despesa}' => $despesa->id, '{despesaP}' => $despesaPaga->id, '{transf}' => $transf->id, '{u}' => $alvo->id]);

                $this->novaRequisicao();
                $resposta = ($ator ? $this->actingAs($ator) : $this)->json($metodo, $uri, []);
                DB::rollBack();

                if ($ator === null) {
                    if ($resposta->status() !== 401) {
                        $divergencias[] = "{$chave} anônimo => {$resposta->status()} (esperado 401)";
                    }
                    continue;
                }

                $deveria = str_contains($permitidos, $letra);
                $barrado = $resposta->status() === 403;
                if ($deveria === $barrado) {
                    $divergencias[] = "{$chave} como {$letra} => {$resposta->status()} (" . ($deveria ? 'deveria passar' : 'deveria ser 403') . ')';
                }
            }
        }

        $this->assertSame([], $divergencias, implode("\n", $divergencias));
    }

    public function test_parametros_do_cliente_nao_mudam_visao_escopo_nem_perfil(): void
    {
        $mes = $this->mesPassado(1);
        $m = (object) $this->massaDoMes($mes);
        $forcados = ['visao' => 'completo', 'escopo' => 'todos', 'perfil' => 'pastor', 'perfil_id' => 1, 'user_id' => $m->pastor->id,
            'criado_por' => $m->aux2->id, 'role' => 'admin', 'is_admin' => 1, 'ano_mes' => $mes];

        $this->novaRequisicao();
        $limpo = $this->actingAs($m->aux1)->getJson('/api/v1/dashboard?ano_mes=' . $mes)->assertOk()->json();
        $this->novaRequisicao();
        $forcado = $this->actingAs($m->aux1)->getJson('/api/v1/dashboard?' . http_build_query($forcados))->assertOk()->json();
        $this->assertSame($limpo, $forcado);
        $this->assertSame('parcial', $forcado['data']['visao']);

        foreach (['entradas', 'despesas', 'movimentacoes', 'resumo'] as $relatorio) {
            $this->novaRequisicao();
            $a = $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/{$relatorio}?ano_mes={$mes}")->assertOk()->json();
            $this->novaRequisicao();
            $b = $this->actingAs($m->aux1)->getJson("/api/v1/relatorios/{$relatorio}?" . http_build_query($forcados))->assertOk()->json();
            $this->assertSame($a['data'], $b['data'], "O Auxiliar burlou o escopo em {$relatorio}");
            $this->assertSame($a['meta']['totais'], $b['meta']['totais']);
        }

        foreach (['entradas', 'despesas'] as $lista) {
            $this->novaRequisicao();
            $a = $this->actingAs($m->aux1)->getJson("/api/v1/{$lista}?por_pagina=100")->assertOk()->json('data');
            $this->novaRequisicao();
            $b = $this->actingAs($m->aux1)->getJson("/api/v1/{$lista}?" . http_build_query($forcados + ['por_pagina' => 100]))->assertOk()->json('data');
            $this->assertNotEmpty($a);
            $this->assertSame(array_column($a, 'id'), array_column($b, 'id'), "O Auxiliar enxergou registros alheios em {$lista}");
        }
    }

    public function test_autorizacao_muda_na_proxima_requisicao_quando_o_perfil_muda_no_banco(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $this->novaRequisicao();
        $this->actingAs($tesoureiro)->getJson('/api/v1/relatorios/saldos')->assertOk();

        $tesoureiro->update(['perfil_id' => Perfil::where('slug', PerfilSlug::Secretario->value)->value('id')]);
        $this->novaRequisicao();
        $this->actingAs($this->recarregado($tesoureiro))->getJson('/api/v1/relatorios/saldos')->assertForbidden();
    }
}
