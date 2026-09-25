<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 13 — injeção de SQL e XSS.
 *
 * SQL: todos os filtros/ordenações/ids chegam ao banco por bindings do Eloquent/Query Builder (o único SQL "cru" do
 * projeto interpola apenas constantes do próprio código). Aqui provamos pelo comportamento: cargas de injeção clássicas
 * não alteram resultados, não executam (nenhum SLEEP atrasa a resposta), não derrubam tabelas e, quando o campo aceita
 * texto livre, o texto volta EXATAMENTE como foi enviado (prova de que virou dado, nunca SQL).
 *
 * XSS: a API devolve texto puro em JSON (Content-Type application/json + nosniff + CSP restritiva) — nunca HTML. O
 * navegador não executa JSON e o React escapa tudo que renderiza (conferido no E2E com cargas reais na tela).
 */
class InjecaoSqlEXssTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    private const CARGAS_SQL = [
        "' OR '1'='1",
        "' OR 1=1 --",
        "1; DROP TABLE users; --",
        "1 UNION SELECT id, password, 3, 4 FROM users --",
        "'; SELECT SLEEP(3); --",
        "1 AND SLEEP(3)",
        "%' OR '%'='",
        "\\' OR 1=1 #",
        "1) OR (1=1",
        "admin'--",
        "' UNION SELECT NULL,NULL,NULL --",
        "1' AND (SELECT 1 FROM (SELECT SLEEP(3))x) AND '1'='1",
    ];

    private const CARGAS_XSS = [
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '"><svg/onload=alert(1)>',
        "javascript:alert(document.cookie)",
        '<iframe src="javascript:alert(1)"></iframe>',
        '{{constructor.constructor("alert(1)")()}}',
        '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
        "'\"><body onload=alert(1)>",
    ];

    private function contagemDasTabelas(): array
    {
        $contagem = [];
        foreach (['users', 'contas', 'categorias', 'entradas', 'despesas', 'transferencias', 'ajustes_saldo', 'periodos_financeiros', 'audit_logs', 'perfis'] as $tabela) {
            $contagem[$tabela] = DB::table($tabela)->count();
        }

        return $contagem;
    }

    public function test_cargas_de_sql_em_filtros_ordenacao_e_paginacao_nao_executam_nem_alteram_resultados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->massaDoMes($this->mesPassado(1));
        $antes = $this->contagemDasTabelas();

        $parametros = ['data_de', 'data_ate', 'categoria_id', 'conta_id', 'status', 'tipo', 'estorno', 'ordenar', 'por_pagina', 'page', 'ano_mes',
            'modulo', 'acao', 'user_id', 'registro_id', 'sem_usuario', 'ativa', 'busca', 'q'];
        $listagens = ['contas', 'categorias', 'entradas', 'despesas', 'transferencias', 'ajustes', 'periodos-financeiros', 'usuarios', 'auditoria',
            'dashboard', 'relatorios/resumo', 'relatorios/entradas', 'relatorios/despesas', 'relatorios/movimentacoes', 'relatorios/saldos'];

        $inicio = microtime(true);
        foreach ($listagens as $listagem) {
            $basal = $this->actingAs($pastor)->getJson("/api/v1/{$listagem}");
            foreach (self::CARGAS_SQL as $carga) {
                foreach ($parametros as $parametro) {
                    $this->novaRequisicao();
                    Cache::flush();
                    $resposta = $this->actingAs($pastor)->getJson("/api/v1/{$listagem}?" . http_build_query([$parametro => $carga]));
                    $this->assertLessThan(500, $resposta->status(), "GET {$listagem} [{$parametro}={$carga}]");
                    $this->assertSemVazamento($resposta);

                    // Se a carga foi ACEITA (200) num filtro que não a compreende, o resultado não pode ser "mais" que o basal.
                    if ($resposta->status() === 200 && isset($basal['data']) && is_array($basal['data']) && is_array($resposta->json('data'))) {
                        $this->assertLessThanOrEqual(count($basal['data']), count($resposta->json('data')), "A carga em {$parametro} ampliou o resultado de {$listagem}");
                    }
                }
            }
        }

        $this->assertLessThan(60, microtime(true) - $inicio, 'Alguma carga SLEEP parece ter sido executada pelo banco.');
        $this->assertSame($antes, $this->contagemDasTabelas(), 'Nenhuma tabela pode ter sido alterada por consultas.');
    }

    public function test_cargas_de_sql_nos_ids_da_rota_e_no_login_nao_tem_efeito(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $alvo = $this->como(PerfilSlug::Tesoureiro);
        $antes = $this->contagemDasTabelas();

        foreach (self::CARGAS_SQL as $carga) {
            foreach (['usuarios', 'contas', 'categorias', 'despesas'] as $recurso) {
                $this->novaRequisicao();
                $resposta = $this->actingAs($pastor)->deleteJson("/api/v1/{$recurso}/" . rawurlencode($carga));
                $this->assertContains($resposta->status(), [404, 405], "DELETE /{$recurso}/{$carga}");
            }

            // Login com a carga no e-mail/senha: recusa comum, sem autenticar ninguém e sem 500.
            $this->novaRequisicao();
            $resposta = $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/v1/auth/login', ['email' => $carga, 'password' => $carga]);
            $this->assertSame(422, $resposta->status());
            $this->assertGuest('web');
            $this->novaRequisicao();
            $resposta = $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/v1/auth/login', ['email' => "{$carga}@x.com", 'password' => $carga]);
            $this->assertLessThan(500, $resposta->status());
            $this->assertGuest('web');
            Cache::flush();
        }

        // Só a auditoria cresce (tentativas de login falhas são auditadas por desenho); nada mais muda.
        $depois = $this->contagemDasTabelas();
        unset($antes['audit_logs'], $depois['audit_logs']);
        $this->assertSame($antes, $depois);
        $this->assertSame([], DB::table('audit_logs')->where('acao', '!=', 'login_failed')->pluck('acao')->all());
        $this->assertTrue((bool) $alvo->fresh()->ativo);
    }

    public function test_texto_livre_com_sql_e_gravado_e_devolvido_literalmente_como_dado(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('A', 'banco', '100.00');
        $cat = $this->categoria('Dz');
        $catD = $this->categoriaDespesa('En');
        $carga = "Robert'); DROP TABLE entradas;-- \\ \" %_ ";

        $this->actingAs($tesoureiro)->postJson('/api/v1/entradas', $this->payload($conta, $cat, ['descricao' => $carga, 'contribuinte_nome' => $carga]))->assertCreated();
        $this->novaRequisicao();
        $this->actingAs($tesoureiro)->postJson('/api/v1/despesas', $this->payloadDespesa($catD, ['descricao' => $carga, 'fornecedor_nome' => $carga]))->assertCreated();

        $entrada = Entrada::query()->latest('id')->firstOrFail();
        $despesa = Despesa::query()->latest('id')->firstOrFail();
        $this->assertSame(trim($carga), $entrada->descricao);
        $this->assertSame(trim($carga), $entrada->contribuinte_nome);
        $this->assertSame(trim($carga), $despesa->descricao);
        $this->assertSame(trim($carga), $despesa->fornecedor_nome);
        $this->assertSame(1, Entrada::query()->count(), 'A tabela de entradas segue íntegra.');

        $this->novaRequisicao();
        $lista = $this->actingAs($tesoureiro)->getJson('/api/v1/entradas')->assertOk();
        $this->assertSame(trim($carga), $lista->json('data.0.descricao'));
    }

    public function test_cargas_xss_sao_devolvidas_como_texto_json_e_nunca_como_html(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Conta', 'banco', '500.00');
        $cat = $this->categoria('Dz');
        $catD = $this->categoriaDespesa('En');

        foreach (self::CARGAS_XSS as $i => $xss) {
            $this->novaRequisicao();
            $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $cat, ['descricao' => $xss, 'contribuinte_nome' => $xss]))->assertCreated();
            $this->novaRequisicao();
            $this->actingAs($pastor)->postJson('/api/v1/despesas', $this->payloadDespesa($catD, ['descricao' => $xss, 'fornecedor_nome' => $xss]))->assertCreated();
            $this->novaRequisicao();
            $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => "C{$i} {$xss}", 'tipo' => 'caixa', 'saldo_inicial' => '1.00'])->assertCreated();
            $this->novaRequisicao();
            $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => "K{$i} {$xss}", 'tipo' => 'entrada'])->assertCreated();
            $this->novaRequisicao();
            $this->actingAs($pastor)->postJson('/api/v1/usuarios', ['name' => "U{$i} {$xss}", 'email' => "xss{$i}@exemplo.com", 'password' => 'Senh4-Forte!2026', 'perfil_id' => $this->como(PerfilSlug::Secretario)->perfil_id])->assertCreated();
        }

        // O que entrou é o que sai: texto literal, sem sanitização "criativa" que alteraria dados legítimos…
        $this->assertSame(self::CARGAS_XSS[0], Entrada::query()->orderBy('id')->first()->descricao);
        $this->assertTrue(Conta::query()->where('nome', 'like', '%<script>%')->exists());
        $this->assertTrue(Categoria::query()->where('nome', 'like', '%<script>%')->exists());
        $this->assertTrue(User::query()->where('name', 'like', '%<script>%')->exists());

        // …e toda leitura pela API é JSON com nosniff e CSP restritiva: o navegador jamais interpreta como HTML.
        $mes = $this->mesAtual();
        foreach (['entradas', 'despesas', 'contas', 'categorias', 'usuarios', 'auditoria', "relatorios/entradas?ano_mes={$mes}", "relatorios/despesas?ano_mes={$mes}", 'relatorios/saldos'] as $rota) {
            $this->novaRequisicao();
            Cache::flush();
            $resposta = $this->actingAs($pastor)->getJson("/api/v1/{$rota}" . (str_contains($rota, '?') ? '&' : '?') . 'por_pagina=100');
            $resposta->assertOk();
            $this->assertStringContainsString('application/json', (string) $resposta->headers->get('Content-Type'), $rota);
            $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'), $rota);
            $this->assertStringContainsString("default-src 'none'", (string) $resposta->headers->get('Content-Security-Policy'), $rota);
        }
    }

    public function test_erros_com_carga_xss_no_caminho_tambem_sao_json(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $resposta = $this->actingAs($pastor)->get('/api/v1/' . rawurlencode('<script>alert(1)</script>'));

        $this->assertSame(404, $resposta->status());
        $this->assertStringContainsString('application/json', (string) $resposta->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<script>', $resposta->getContent(), 'O 404 não deve refletir o caminho pedido.');
    }

    public function test_exportacao_trata_carga_xss_como_texto_puro_no_csv_e_no_xlsx(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Conta', 'banco', '100.00');
        $cat = $this->categoria('Dz');
        $xss = '<img src=x onerror=alert(1)>';
        $this->entrada($conta, $cat, $pastor, '10.00', null, ['descricao' => $xss, 'contribuinte_nome' => $xss]);

        $csv = $this->exportarApi($pastor, 'entradas', 'csv')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString($xss, $csv->getContent());
        $this->assertSame('nosniff', $csv->headers->get('X-Content-Type-Options'));

        $this->novaRequisicao();
        $xlsx = $this->exportarApi($pastor, 'entradas', 'xlsx')->assertOk();
        $this->assertStringNotContainsString('text/html', (string) $xlsx->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $xlsx->headers->get('Content-Disposition'));
    }
}
