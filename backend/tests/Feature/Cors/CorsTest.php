<?php

namespace Tests\Feature\Cors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_options_da_origem_permitida_e_aceito(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login');

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_resposta_real_inclui_headers_de_credenciais_para_origem_permitida(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->getJson('/api/v1/health');

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_allowed_origins_nao_usa_wildcard(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame(['http://localhost:5173'], config('cors.allowed_origins'));
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_header_de_replay_idempotente_e_exposto_ao_navegador(): void
    {
        // Fase 12: `Content-Disposition` também é exposto, para o navegador ler o nome do arquivo exportado (CSV/XLSX).
        // A lista continua EXATA: nenhum outro cabeçalho é exposto.
        $this->assertSame(['Idempotent-Replayed', 'Content-Disposition'], config('cors.exposed_headers'));

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])->getJson('/api/v1/health');
        $expostos = array_map('trim', explode(',', $response->headers->get('Access-Control-Expose-Headers')));
        $this->assertEqualsCanonicalizing(['Idempotent-Replayed', 'Content-Disposition'], $expostos);
    }
}
