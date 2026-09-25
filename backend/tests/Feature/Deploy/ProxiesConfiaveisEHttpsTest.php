<?php

namespace Tests\Feature\Deploy;

use Illuminate\Http\Middleware\TrustProxies;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Fase 14 — HTTPS atrás de proxy reverso. Em produção o TLS costuma terminar no proxy; o Laravel só reconhece o HTTPS
 * (`X-Forwarded-Proto`) de proxies EXPLICITAMENTE confiáveis (TRUSTED_PROXIES). Sem lista, nada é confiável.
 */
class ProxiesConfiaveisEHttpsTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function emProducao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    private function viaProxy(string $enderecoDoProxy)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $enderecoDoProxy])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->getJson('http://localhost/api/v1/dashboard');
    }

    public function test_proxy_confiavel_com_https_terminado_nele_nao_e_redirecionado_em_loop(): void
    {
        $this->emProducao();
        TrustProxies::at(['10.0.0.5']);

        // 401 = chegou até a autenticação (a requisição foi reconhecida como HTTPS); antes desta correção seria 308.
        $this->viaProxy('10.0.0.5')->assertStatus(401);
    }

    public function test_proxy_nao_listado_nao_e_confiado_e_o_http_continua_sendo_recusado(): void
    {
        $this->emProducao();
        TrustProxies::at(['10.0.0.5']);

        $this->viaProxy('10.0.0.99')->assertStatus(308);
    }

    public function test_sem_lista_de_proxies_nenhum_cabecalho_forwarded_e_aceito(): void
    {
        $this->emProducao();

        $this->viaProxy('10.0.0.5')->assertStatus(308);
    }

    public function test_respostas_de_recusa_por_http_tambem_levam_cabecalhos_de_seguranca(): void
    {
        $this->emProducao();

        $resposta = $this->getJson('http://localhost/api/v1/dashboard');

        $resposta->assertStatus(308);
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
    }

    public function test_trusted_proxies_do_ambiente_chega_ao_laravel_de_ponta_a_ponta(): void
    {
        // Processo separado em produção com TRUSTED_PROXIES=10.0.0.5: o que vem do .env/ambiente passa por config('app.trusted_proxies')
        // e é aplicado pelo AppServiceProvider. Sem esta ligação, "listar o proxy" não teria efeito algum.
        $php = <<<'PHP'
use Illuminate\Http\Request;
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$status = function (string $remoto) use ($kernel) {
    $r = Request::create('http://localhost/api/v1/dashboard', 'GET', [], [], [], ['REMOTE_ADDR' => $remoto, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_ACCEPT' => 'application/json']);
    return $kernel->handle($r)->getStatusCode();
};
echo 'STATUS=' . $status('10.0.0.5') . ',' . $status('10.0.0.99');
PHP;

        $ambiente = array_merge(getenv(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'TRUSTED_PROXIES' => '10.0.0.5', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
        $processo = proc_open([PHP_BINARY, 'artisan', 'tinker', '--execute=' . $php], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($processo);

        $this->assertStringContainsString('STATUS=401,308', $saida, $saida);
    }

    public function test_a_lista_de_proxies_vem_da_configuracao_e_nao_de_env_solto(): void
    {
        // `php artisan config:cache` (recomendado em produção) NÃO carrega o .env: qualquer env() fora de config/ devolve
        // null e o recurso falha em silêncio. Foi exatamente o que aconteceu com TRUSTED_PROXIES em bootstrap/app.php.
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        $violacoes = [];
        foreach (['app', 'bootstrap', 'routes', 'database'] as $pasta) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($pasta), RecursiveDirectoryIterator::SKIP_DOTS)) as $arquivo) {
                $caminho = str_replace('\\', '/', $arquivo->getPathname());
                if ($arquivo->getExtension() !== 'php' || str_contains($caminho, 'bootstrap/cache/')) {
                    continue;
                }
                if (preg_match('/(?<![A-Za-z_>:])env\(/', file_get_contents($caminho))) {
                    $violacoes[] = $caminho;
                }
            }
        }
        unset($it);

        $this->assertSame([], $violacoes, 'env() só é permitido dentro de config/: ' . implode(', ', $violacoes));
        $this->assertIsArray(config('app.trusted_proxies'));
    }
}
