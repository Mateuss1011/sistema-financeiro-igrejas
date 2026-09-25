<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 13 — cabeçalhos de segurança, CORS restrito e HTTPS em produção.
 */
class CabecalhosCorsEHttpsTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    private const ORIGEM_OK = 'http://localhost:5173';

    private function assertCabecalhosDeSeguranca($resposta, string $onde): void
    {
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'), $onde);
        $this->assertSame('DENY', $resposta->headers->get('X-Frame-Options'), $onde);
        $this->assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'), $onde);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $resposta->headers->get('Content-Security-Policy'), $onde);
        $cache = (string) $resposta->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache, $onde);
        $this->assertStringContainsString('private', $cache, $onde);
    }

    public function test_toda_resposta_da_api_leva_os_cabecalhos_de_seguranca(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->assertCabecalhosDeSeguranca($this->getJson('/api/v1/health'), 'health 200');
        $this->assertCabecalhosDeSeguranca($this->getJson('/api/v1/dashboard'), 'dashboard 401');
        $this->assertCabecalhosDeSeguranca($this->getJson('/api/v1/nao-existe'), '404');
        $this->assertCabecalhosDeSeguranca($this->deleteJson('/api/v1/entradas'), '405 anonimo');
        $this->novaRequisicao();
        $this->assertCabecalhosDeSeguranca($this->actingAs($pastor)->postJson('/api/v1/entradas', []), '422');
        $this->novaRequisicao();
        $this->assertCabecalhosDeSeguranca($this->actingAs($pastor)->getJson('/api/v1/dashboard'), 'dashboard 200');
        $this->novaRequisicao();
        $this->assertCabecalhosDeSeguranca($this->exportarApi($pastor, 'entradas', 'csv')->assertOk(), 'exportacao csv');
        $this->novaRequisicao();
        $this->assertCabecalhosDeSeguranca($this->exportarApi($pastor, 'entradas', 'xlsx')->assertOk(), 'exportacao xlsx');
    }

    public function test_dados_financeiros_nunca_sao_cacheaveis(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['/api/v1/entradas', '/api/v1/despesas', '/api/v1/contas', '/api/v1/relatorios/saldos', '/api/v1/auth/me', '/api/v1/usuarios'] as $rota) {
            $this->novaRequisicao();
            $resposta = $this->actingAs($pastor)->getJson($rota)->assertOk();
            $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'), $rota);
            $this->assertNull($resposta->headers->get('ETag'), "{$rota} não deve oferecer revalidação por ETag");
        }
    }

    public function test_hsts_so_e_enviado_por_https_em_producao(): void
    {
        $this->assertNull($this->getJson('/api/v1/health')->headers->get('Strict-Transport-Security'), 'Fora de produção não há HSTS.');

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertStringContainsString('max-age=31536000', (string) $this->getJson('https://localhost/api/v1/health')->headers->get('Strict-Transport-Security'));
    }

    public function test_producao_recusa_http_leitura_e_redirecionada_escrita_e_barrada(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $leitura = $this->getJson('/api/v1/dashboard');
        $this->assertSame(308, $leitura->status());
        $this->assertSame('https://localhost/api/v1/dashboard', $leitura->headers->get('Location'));

        // Um corpo (senha, dados financeiros) enviado por http já trafegou em claro: em vez de redirecionar, recusa.
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'a@b.com', 'password' => 'senha']);
        $login->assertStatus(426)->assertJsonPath('code', 'HTTPS_OBRIGATORIO');
        $this->assertSemVazamento($login);

        // O health-check do balanceador (rede interna, sem TLS) continua respondendo.
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_fora_de_producao_http_funciona_normalmente(): void
    {
        $this->assertFalse(app()->isProduction());
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_cors_libera_apenas_a_origem_configurada(): void
    {
        $ok = $this->withHeaders(['Origin' => self::ORIGEM_OK])->getJson('/api/v1/health');
        $this->assertSame(self::ORIGEM_OK, $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $ok->headers->get('Access-Control-Allow-Credentials'));

        foreach (['https://evil.example', 'http://localhost:5174', 'http://localhost:5173.evil.example', 'http://evil.example/http://localhost:5173',
            'null', 'http://LOCALHOST:5173.evil', 'https://localhost:5173', 'http://127.0.0.1:5173', '*'] as $origem) {
            // Com uma única origem configurada o pacote de CORS sempre anuncia ELA (nunca a do requisitante): o navegador
            // compara com a origem da página e bloqueia o intruso. O que jamais pode ocorrer é ecoar a origem estranha ou '*'.
            $resposta = $this->withHeaders(['Origin' => $origem])->getJson('/api/v1/health');
            $liberada = $resposta->headers->get('Access-Control-Allow-Origin');
            $this->assertNotSame($origem, $liberada, "A origem '{$origem}' não pode ser ecoada");
            $this->assertNotSame('*', $liberada);
            $this->assertContains($liberada, [null, self::ORIGEM_OK]);
        }
    }

    public function test_preflight_de_origem_estranha_nao_recebe_permissoes(): void
    {
        $resposta = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'DELETE',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/v1/usuarios/1');

        $liberada = $resposta->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('https://evil.example', $liberada);
        $this->assertNotSame('*', $liberada);
    }

    public function test_preflight_da_origem_permitida_lista_apenas_metodos_e_cabecalhos_necessarios(): void
    {
        $resposta = $this->withHeaders([
            'Origin' => self::ORIGEM_OK,
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token,idempotency-key',
        ])->options('/api/v1/contas/1');

        $this->assertSame(self::ORIGEM_OK, $resposta->headers->get('Access-Control-Allow-Origin'));
        $metodos = array_map('trim', explode(',', (string) $resposta->headers->get('Access-Control-Allow-Methods')));
        $this->assertEqualsCanonicalizing(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'], $metodos);
        $this->assertNotContains('*', $metodos);
        $this->assertNotContains('PATCH', $metodos);

        $cabecalhos = strtolower((string) $resposta->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringNotContainsString('*', $cabecalhos);
        foreach (['content-type', 'x-xsrf-token', 'idempotency-key'] as $necessario) {
            $this->assertStringContainsString($necessario, $cabecalhos);
        }
        $this->assertStringNotContainsString('authorization', $cabecalhos, 'O SPA usa cookie de sessão, não bearer token.');
    }

    public function test_configuracao_de_cors_nunca_usa_curinga_com_credenciais(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
        $this->assertNotContains('*', config('cors.allowed_headers'));
        $this->assertSame(['api/*', 'sanctum/csrf-cookie'], config('cors.paths'));
    }
}
