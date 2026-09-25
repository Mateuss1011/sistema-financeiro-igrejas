<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 13 — rate limiting por operação (o de login está em AutenticacaoESessaoTest).
 *
 * Decisão (ver AppServiceProvider::definirLimitesDeRequisicao): só as operações caras ou abusáveis são limitadas —
 * exportações (10/min por usuário; montam o arquivo inteiro e auditam) e consultas agregadas (60/min por usuário,
 * compartilhado entre dashboard, relatórios e auditoria). As escritas comuns NÃO têm throttle: são protegidas por
 * idempotência, locks e regras de negócio, e um teto artificial só atrapalharia o uso legítimo.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    public function test_exportacao_dentro_do_limite_funciona_e_a_11a_e_bloqueada_sem_executar_nem_auditar(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        for ($i = 1; $i <= 10; $i++) {
            $this->novaRequisicao();
            $this->exportarApi($pastor, 'entradas', 'csv')->assertOk();
        }
        $this->assertSame(10, AuditLog::query()->where('modulo', 'exportacoes')->count());

        $this->novaRequisicao();
        $resposta = $this->exportarApi($pastor, 'entradas', 'csv');

        $resposta->assertStatus(429)->assertJsonPath('code', 'MUITAS_REQUISICOES');
        $this->assertGreaterThan(0, (int) $resposta->headers->get('Retry-After'));
        $this->assertStringNotContainsString('attachment', (string) $resposta->headers->get('Content-Disposition'), 'Nenhum arquivo pode ser entregue acima do limite.');
        $this->assertSame(10, AuditLog::query()->where('modulo', 'exportacoes')->count(), 'A 11ª exportação não pode ser executada nem auditada.');
    }

    public function test_o_limite_de_exportacao_vale_por_usuario_e_para_todos_os_formatos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);

        for ($i = 1; $i <= 10; $i++) {
            $this->novaRequisicao();
            $this->exportarApi($pastor, 'entradas', $i % 2 ? 'csv' : 'xlsx')->assertOk();
        }
        $this->novaRequisicao();
        $this->exportarApi($pastor, 'despesas', 'xlsx')->assertStatus(429);

        // Outro usuário tem a sua própria cota.
        $this->novaRequisicao();
        $this->exportarApi($tesoureiro, 'entradas', 'csv')->assertOk();
    }

    public function test_perfil_sem_direito_de_exportar_continua_recebendo_403_e_nao_gera_auditoria(): void
    {
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);

        $this->exportarApi($auxiliar, 'entradas', 'csv')->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('modulo', 'exportacoes')->count());
    }

    public function test_consultas_agregadas_dentro_do_limite_funcionam_e_a_61a_e_bloqueada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        // A cota é compartilhada entre dashboard, relatórios e auditoria.
        $rotas = ['/api/v1/dashboard', '/api/v1/relatorios/entradas', '/api/v1/auditoria'];
        for ($i = 0; $i < 60; $i++) {
            $this->novaRequisicao();
            $this->actingAs($pastor)->getJson($rotas[$i % 3])->assertOk();
        }

        foreach ($rotas as $rota) {
            $this->novaRequisicao();
            $resposta = $this->actingAs($pastor)->getJson($rota);
            $resposta->assertStatus(429)->assertJsonPath('code', 'MUITAS_REQUISICOES');
            $this->assertGreaterThan(0, (int) $resposta->headers->get('Retry-After'));
        }
    }

    public function test_esgotar_a_cota_de_consultas_nao_bloqueia_as_demais_operacoes_do_usuario(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        for ($i = 0; $i < 60; $i++) {
            $this->novaRequisicao();
            $this->actingAs($pastor)->getJson('/api/v1/dashboard')->assertOk();
        }
        $this->novaRequisicao();
        $this->actingAs($pastor)->getJson('/api/v1/dashboard')->assertStatus(429);

        // Listagens comuns, catálogo e /me seguem funcionando: o limite é só das operações pesadas.
        foreach (['/api/v1/entradas', '/api/v1/despesas', '/api/v1/contas', '/api/v1/relatorios', '/api/v1/auth/me'] as $rota) {
            $this->novaRequisicao();
            $this->actingAs($pastor)->getJson($rota)->assertOk();
        }
    }

    public function test_a_cota_de_consultas_e_de_cada_usuario(): void
    {
        $a = $this->como(PerfilSlug::Pastor);
        $b = $this->como(PerfilSlug::Tesoureiro);

        for ($i = 0; $i < 60; $i++) {
            $this->novaRequisicao();
            $this->actingAs($a)->getJson('/api/v1/dashboard')->assertOk();
        }
        $this->novaRequisicao();
        $this->actingAs($a)->getJson('/api/v1/dashboard')->assertStatus(429);
        $this->novaRequisicao();
        $this->actingAs($b)->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_requisicao_sem_autenticacao_nunca_chega_ao_limitador_de_usuario(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/dashboard')->assertUnauthorized();
            $this->getJson('/api/v1/relatorios/entradas/exportar/csv')->assertUnauthorized();
        }
    }
}
