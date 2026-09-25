<?php

namespace Tests\Feature\Auditoria;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Plano, seção 6 e cenários críticos (seção 12): tentativa de UPDATE/DELETE em audit_logs = rota inexistente. */
class ImutabilidadeDaAuditoriaTest extends TestCase
{
    use RefreshDatabase, CenarioAuditoria;

    public function test_as_unicas_rotas_de_auditoria_sao_dois_gets(): void
    {
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($rota) => str_contains($rota->uri(), 'auditoria'))
            ->map(fn ($rota) => implode('|', array_diff($rota->methods(), ['HEAD'])) . ' ' . $rota->uri())
            ->sort()->values()->all();

        $this->assertSame(['GET api/v1/auditoria', 'GET api/v1/auditoria/catalogo'], $rotas);
    }

    public function test_metodos_de_escrita_nao_alcancam_a_auditoria_nem_para_o_pastor(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $log = $this->log(['modulo' => 'contas', 'justificativa' => 'original']);

        foreach (['/api/v1/auditoria', "/api/v1/auditoria/{$log->id}", '/api/v1/auditoria/catalogo'] as $url) {
            foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $metodo) {
                $status = $this->actingAs($pastor)->{$metodo}($url, ['justificativa' => 'adulterado'])->getStatusCode();

                $this->assertContains($status, [404, 405], "{$metodo} {$url} respondeu {$status}");
            }
        }

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('original', $log->fresh()->justificativa);
    }

    public function test_nao_existe_get_individual(): void
    {
        $log = $this->log();

        $this->actingAs($this->como(PerfilSlug::Pastor))->getJson("/api/v1/auditoria/{$log->id}")->assertStatus(404);
    }

    public function test_consultar_nunca_altera_os_registros(): void
    {
        $this->log(['modulo' => 'contas', 'dados_novos' => ['nome' => 'A']], '2026-05-01 10:00:00');
        $this->log(['modulo' => 'entradas', 'justificativa' => 'j'], '2026-05-02 10:00:00');
        $antes = AuditLog::orderBy('id')->get()->toArray();

        $pastor = $this->como(PerfilSlug::Pastor);
        $this->consultarAuditoria($pastor)->assertOk();
        $this->consultarAuditoria($pastor, ['modulo' => 'contas', 'ordenar' => 'created_at'])->assertOk();
        $this->catalogoAuditoria($pastor)->assertOk();

        $this->assertSame($antes, AuditLog::orderBy('id')->get()->toArray());
    }

    public function test_o_model_nao_tem_updated_at_e_o_created_at_e_preenchido_no_registro(): void
    {
        $log = $this->log();

        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertNotNull($log->created_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }
}
