<?php

namespace Tests\Feature\Seguranca;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Fase 13 — segurança de configuração: modelo `.env.example` seguro por padrão, `.env` fora do versionamento, nenhum
 * segredo hardcoded (backend e frontend), configuração REAL de produção (lida de um processo PHP iniciado com
 * APP_ENV=production) e higiene do bundle do frontend.
 */
class ConfiguracaoDeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function lerEnv(string $arquivo): array
    {
        $valores = [];
        foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || ! str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            $valores[trim($chave)] = trim($valor);
        }

        return $valores;
    }

    /** @return list<string> */
    private function arquivos(string $pasta, array $extensoes): array
    {
        $achados = [];
        if (! is_dir($pasta)) {
            return $achados;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->isFile() && in_array(strtolower($arquivo->getExtension()), $extensoes, true)) {
                $achados[] = $arquivo->getPathname();
            }
        }

        return $achados;
    }

    public function test_env_nao_e_versionado_e_o_modelo_nao_traz_segredos(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));
        $this->assertMatchesRegularExpression('/^\.env$/m', $gitignore, '.env precisa estar no .gitignore');

        $modelo = $this->lerEnv(base_path('.env.example'));

        foreach (['APP_KEY', 'DB_USERNAME', 'DB_PASSWORD'] as $vazia) {
            $this->assertSame('', $modelo[$vazia] ?? null, "{$vazia} no modelo precisa ficar vazia (preenchida só no .env real)");
        }
        foreach ($modelo as $chave => $valor) {
            if (preg_match('/PASSWORD|SECRET|TOKEN|KEY|CREDENTIAL/i', $chave)) {
                $this->assertSame('', $valor, "{$chave} não pode ter valor no modelo");
            }
        }
        $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|base64:[A-Za-z0-9+\/=]{30,}/', file_get_contents(base_path('.env.example')));
    }

    public function test_modelo_de_env_e_seguro_por_padrao_para_producao(): void
    {
        $modelo = $this->lerEnv(base_path('.env.example'));

        $this->assertSame('production', $modelo['APP_ENV']);
        $this->assertSame('false', $modelo['APP_DEBUG']);
        $this->assertStringStartsWith('https://', $modelo['APP_URL']);
        $this->assertGreaterThanOrEqual(12, (int) $modelo['BCRYPT_ROUNDS']);
        $this->assertSame('true', $modelo['SESSION_SECURE_COOKIE']);
        $this->assertSame('true', $modelo['SESSION_ENCRYPT']);
        $this->assertContains($modelo['SESSION_SAME_SITE'], ['lax', 'strict']);
        $this->assertLessThanOrEqual(120, (int) $modelo['SESSION_LIFETIME']);
        $this->assertNotContains($modelo['LOG_LEVEL'], ['debug'], 'Produção não deve logar em nível debug.');
        $this->assertNotSame('root', $modelo['DB_USERNAME']);
        // Fase 14: frontend (app.) e API (api.) são subdomínios — o cookie precisa do domínio-pai (ponto inicial) para o
        // frontend ler o XSRF-TOKEN; e o log gira por dia em vez de crescer sem limite.
        $this->assertStringStartsWith('.', $modelo['SESSION_DOMAIN']);
        $this->assertSame('daily', $modelo['LOG_STACK']);
        $this->assertGreaterThan(0, (int) $modelo['LOG_DAILY_DAYS']);
        $this->assertArrayHasKey('TRUSTED_PROXIES', $modelo);
        $this->assertSame('', $modelo['TRUSTED_PROXIES'], 'Sem proxy definido pela infraestrutura, nenhum é confiável.');

        foreach (explode(',', $modelo['CORS_ALLOWED_ORIGINS']) as $origem) {
            $this->assertStringStartsWith('https://', trim($origem), 'Em produção o CORS só libera origens https.');
            $this->assertStringNotContainsString('*', $origem);
        }
        foreach (['SANCTUM_STATEFUL_DOMAINS'] as $chave) {
            $this->assertStringNotContainsString('*', $modelo[$chave]);
            $this->assertStringNotContainsString('localhost', $modelo[$chave], 'O modelo de produção não libera localhost.');
        }
    }

    public function test_configuracao_real_de_producao_e_segura(): void
    {
        $codigo = 'echo json_encode(["debug"=>config("app.debug"),"env"=>config("app.env"),"secure"=>config("session.secure"),'
            . '"http_only"=>config("session.http_only"),"same_site"=>config("session.same_site"),"lifetime"=>config("session.lifetime"),'
            . '"origens"=>config("cors.allowed_origins"),"credenciais"=>config("cors.supports_credentials"),"proxies"=>config("app.trusted_proxies")]);';

        $ambiente = array_merge(getenv(), [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'CORS_ALLOWED_ORIGINS' => 'https://app.exemplo.org, https://painel.exemplo.org',
            'TRUSTED_PROXIES' => '10.0.0.1, 10.0.0.2',
        ]);
        unset($ambiente['SESSION_SECURE_COOKIE']);

        $processo = proc_open([PHP_BINARY, 'artisan', 'tinker', '--execute=' . $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);
        $saida = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($processo);

        $config = json_decode(trim($saida), true);
        $this->assertIsArray($config, "Saída inesperada do processo de produção: {$saida}");
        $this->assertSame('production', $config['env']);
        $this->assertFalse($config['debug']);
        $this->assertTrue($config['secure'], 'Em produção o cookie de sessão é Secure por padrão (sem precisar de variável).');
        $this->assertTrue($config['http_only']);
        $this->assertContains($config['same_site'], ['lax', 'strict']);
        $this->assertLessThanOrEqual(120, $config['lifetime']);
        $this->assertSame(['https://app.exemplo.org', 'https://painel.exemplo.org'], $config['origens']);
        $this->assertTrue($config['credenciais']);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $config['proxies']);
    }

    public function test_padroes_do_codigo_sao_seguros_mesmo_sem_nenhuma_variavel_de_ambiente(): void
    {
        $app = file_get_contents(base_path('config/app.php'));
        $this->assertStringContainsString("'debug' => (bool) env('APP_DEBUG', false)", $app, 'Sem APP_DEBUG definido o padrão é debug DESLIGADO.');
        $this->assertStringContainsString("'env' => env('APP_ENV', 'production')", $app, 'Sem APP_ENV definido o padrão é produção.');

        $this->assertTrue(config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
        $this->assertNull(config('sanctum.expiration') ?: null, 'Sanctum é usado só por sessão de cookie: não há tokens de API.');
    }

    public function test_nenhum_segredo_esta_hardcoded_no_backend(): void
    {
        $padroes = [
            '/AKIA[0-9A-Z]{16}/' => 'chave AWS',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/' => 'chave privada',
            '/sk_(live|test)_[0-9a-zA-Z]{16,}/' => 'chave Stripe',
            '/xox[baprs]-[0-9a-zA-Z-]{10,}/' => 'token Slack',
            '/ghp_[0-9A-Za-z]{30,}/' => 'token GitHub',
            '/base64:[A-Za-z0-9+\/=]{40,}/' => 'APP_KEY literal',
            '/[\'"](password|senha|secret|api_key|token)[\'"]\s*=>\s*[\'"](?!hashed[\'"])[^\'"\s]{4,}[\'"]/i' => 'credencial literal em array (o cast "hashed" do Laravel não é segredo)',
            '/\$(password|senha|secret|apiKey|token)\s*=\s*[\'"][^\'"]{4,}[\'"]/i' => 'credencial literal em variável',
        ];

        $verificados = 0;
        foreach (['app', 'config', 'routes', 'database', 'bootstrap'] as $pasta) {
            foreach ($this->arquivos(base_path($pasta), ['php']) as $arquivo) {
                if (str_contains(str_replace('\\', '/', $arquivo), 'bootstrap/cache/')) {
                    continue;
                }
                $verificados++;
                $conteudo = file_get_contents($arquivo);
                foreach ($padroes as $regex => $descricao) {
                    $this->assertSame(0, preg_match($regex, $conteudo, $achado), "Possível {$descricao} hardcoded em {$arquivo}: " . ($achado[0] ?? ''));
                }
            }
        }
        $this->assertGreaterThan(80, $verificados);

        // Nenhum seeder cria usuário/senha padrão: as credenciais reais nunca moram no código.
        foreach ($this->arquivos(base_path('database/seeders'), ['php']) as $seeder) {
            $this->assertStringNotContainsString('User::', file_get_contents($seeder), "{$seeder} não pode semear usuários");
            $this->assertStringNotContainsString('Hash::make', file_get_contents($seeder));
        }
    }

    public function test_a_api_nao_emite_tokens_pessoais(): void
    {
        foreach ($this->arquivos(base_path('app'), ['php']) as $arquivo) {
            $this->assertStringNotContainsString('createToken', file_get_contents($arquivo), "{$arquivo} emite token de API");
        }
        foreach (app('router')->getRoutes() as $rota) {
            $this->assertStringNotContainsString('token', strtolower($rota->uri()), "Rota de token inesperada: {$rota->uri()}");
        }
    }

    public function test_frontend_nao_embute_segredos_nem_usa_apis_perigosas(): void
    {
        $frontend = base_path('../frontend');
        if (! is_dir($frontend . '/src')) {
            $this->markTestSkipped('Frontend não encontrado ao lado do backend.');
        }

        // Variáveis expostas ao bundle: só a URL pública da API.
        $variaveis = [];
        foreach ($this->arquivos($frontend . '/src', ['js', 'jsx']) as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            preg_match_all('/import\.meta\.env\.([A-Z0-9_]+)/', $conteudo, $m);
            $variaveis = array_merge($variaveis, $m[1]);

            foreach (['dangerouslySetInnerHTML', 'innerHTML', 'outerHTML', 'document.write', 'eval(', 'new Function(', 'localStorage', 'sessionStorage', 'insertAdjacentHTML'] as $perigoso) {
                $this->assertStringNotContainsString($perigoso, $conteudo, "{$arquivo} usa {$perigoso}");
            }
        }
        $this->assertSame(['VITE_API_URL'], array_values(array_unique($variaveis)));

        foreach (glob($frontend . '/.env*') ?: [] as $envFront) {
            foreach (array_keys($this->lerEnv($envFront)) as $chave) {
                $this->assertStringStartsWith('VITE_', $chave);
                $this->assertDoesNotMatchRegularExpression('/PASSWORD|SECRET|TOKEN|KEY/i', $chave, "{$envFront} expõe {$chave} ao bundle");
            }
        }

        // Bundle já compilado (quando existe): sem chaves/segredos conhecidos.
        foreach ($this->arquivos($frontend . '/dist', ['js', 'html', 'map']) as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            $this->assertDoesNotMatchRegularExpression('/AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|sk_live_|base64:[A-Za-z0-9+\/=]{40,}/', $conteudo, "Segredo no bundle {$arquivo}");
        }
    }
}
