<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Services\DashboardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 13 — tratamento de erros. Toda resposta de erro da API é JSON no formato padrão {message, code, errors}, com
 * mensagem apropriada ao cliente e NADA do interior do servidor: sem stack trace, caminho de arquivo, SQL, nome de
 * classe/model, credencial ou token. O detalhe técnico vai para o log técnico (separado da auditoria de negócio).
 */
class ErrosSemVazamentoTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    private function assertFormatoPadrao($resposta, int $status, ?string $code = null): void
    {
        $resposta->assertStatus($status);
        $this->assertStringContainsString('application/json', (string) $resposta->headers->get('Content-Type'));
        $json = $resposta->json();
        $this->assertIsString($json['message'] ?? null);
        $this->assertNotSame('', $json['message']);
        if ($code !== null) {
            $this->assertSame($code, $json['code'] ?? null);
            $this->assertSame([], $json['errors'] ?? null);
        }
        $this->assertSemVazamento($resposta);
    }

    public function test_404_de_rota_e_de_registro_sao_genericos_e_nao_citam_models(): void
    {
        $this->emProducao();
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->assertFormatoPadrao($this->actingAs($pastor)->getJson('/api/v1/rota-que-nao-existe'), 404, 'NAO_ENCONTRADO');
        $this->assertFormatoPadrao($this->actingAs($pastor)->getJson('/api/v1/relatorios/inexistente'), 404, 'NAO_ENCONTRADO');
        $this->assertFormatoPadrao($this->actingAs($pastor)->getJson('/api/v1/relatorios/entradas/exportar/pdf'), 404, 'NAO_ENCONTRADO');

        foreach ([['putJson', 'usuarios'], ['deleteJson', 'usuarios'], ['putJson', 'contas'], ['putJson', 'categorias'], ['deleteJson', 'despesas'], ['putJson', 'despesas']] as [$metodo, $recurso]) {
            $resposta = $this->actingAs($pastor)->$metodo("/api/v1/{$recurso}/999999", []);
            $this->assertFormatoPadrao($resposta, 404, 'NAO_ENCONTRADO');
            $this->assertStringNotContainsString('999999', $resposta->getContent(), 'O 404 não deve ecoar o identificador consultado.');
        }
    }

    public function test_405_e_generico_e_informa_apenas_os_metodos_permitidos(): void
    {
        $this->emProducao();
        $pastor = $this->como(PerfilSlug::Pastor);

        $resposta = $this->actingAs($pastor)->deleteJson('/api/v1/entradas');
        $this->assertFormatoPadrao($resposta, 405, 'METODO_NAO_PERMITIDO');
        $this->assertStringNotContainsString('api/v1', $resposta->getContent());
        $this->assertNotEmpty($resposta->headers->get('Allow'));

        // Entradas, transferências e ajustes são imutáveis: não existe rota de alteração/exclusão.
        foreach (['/api/v1/entradas/1', '/api/v1/transferencias/1', '/api/v1/ajustes/1', '/api/v1/auditoria/1', '/api/v1/periodos-financeiros/2026-01'] as $uri) {
            foreach (['putJson', 'patchJson', 'deleteJson'] as $metodo) {
                $this->assertContains($this->actingAs($pastor)->$metodo($uri, [])->status(), [404, 405], "{$metodo} {$uri} não deveria existir");
            }
        }
    }

    public function test_401_403_409_422_seguem_o_formato_sem_vazamento(): void
    {
        $this->emProducao();
        $pastor = $this->como(PerfilSlug::Pastor);
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);

        $this->assertFormatoPadrao($this->getJson('/api/v1/dashboard'), 401);
        $this->novaRequisicao();
        $this->assertFormatoPadrao($this->actingAs($auxiliar)->getJson('/api/v1/auditoria'), 403);
        $this->novaRequisicao();
        $this->assertFormatoPadrao($this->actingAs($pastor)->postJson('/api/v1/entradas', []), 422);

        // 409 de regra de negócio: conta com movimentação não pode ser excluída.
        $conta = $this->conta('Com uso', 'banco', '10.00');
        $this->entrada($conta, $this->categoria('Dz'), $pastor);
        $this->novaRequisicao();
        $this->assertFormatoPadrao($this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}"), 409, 'CONTA_EM_USO');
    }

    public function test_500_nunca_expoe_stack_trace_caminho_sql_nem_a_mensagem_interna(): void
    {
        $this->emProducao();
        $pastor = $this->como(PerfilSlug::Pastor);
        $segredo = 'SEGREDO-INTERNO senha=hunter2 C:\\xampp\\htdocs\\sfg SQLSTATE[HY000] app/Models/User.php';

        $arquivoDeLog = tempnam(sys_get_temp_dir(), 'sfg-log-');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $arquivoDeLog]);
        $this->app->bind(DashboardService::class, fn () => throw new \RuntimeException($segredo));

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/dashboard');

        $this->assertFormatoPadrao($resposta, 500, 'ERRO_INTERNO');
        $this->assertStringNotContainsString('SEGREDO', $resposta->getContent());
        $this->assertStringNotContainsString('hunter2', $resposta->getContent());

        // O detalhe técnico fica no LOG TÉCNICO (e só lá), nunca na tabela de auditoria de negócio.
        $this->assertStringContainsString('SEGREDO-INTERNO', (string) file_get_contents($arquivoDeLog));
        $this->assertSame(0, DB::table('audit_logs')->where('dados_novos', 'like', '%SEGREDO%')->orWhere('justificativa', 'like', '%SEGREDO%')->count());
        @unlink($arquivoDeLog);
    }

    public function test_500_e_generico_mesmo_com_debug_ligado_por_engano_em_producao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->app->bind(DashboardService::class, fn () => throw new \RuntimeException('detalhe interno'));

        // Em produção só se fala HTTPS (http seria redirecionado): a requisição chega já segura.
        $resposta = $this->actingAs($pastor)->getJson('https://localhost/api/v1/dashboard');

        $this->assertFormatoPadrao($resposta, 500, 'ERRO_INTERNO');
    }

    public function test_500_de_banco_de_dados_nao_vaza_sql(): void
    {
        $this->emProducao();
        $pastor = $this->como(PerfilSlug::Pastor);

        // Falha de banco como o driver a reporta (mensagem com SQL, tabela e código SQLSTATE): nada disso sai na resposta.
        $this->app->bind(DashboardService::class, fn () => throw new QueryException(
            'mysql',
            'select * from `ajustes_saldo` where `senha_secreta` = ?',
            ['hunter2'],
            new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'sfg.ajustes_saldo' doesn't exist"),
        ));

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/dashboard');

        $this->assertFormatoPadrao($resposta, 500, 'ERRO_INTERNO');
        $this->assertStringNotContainsString('ajustes_saldo', $resposta->getContent());
        $this->assertStringNotContainsString('hunter2', $resposta->getContent());
    }

    public function test_erro_em_rota_de_api_e_json_mesmo_com_accept_de_navegador(): void
    {
        $this->emProducao();

        $resposta = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])->get('/api/v1/rota-que-nao-existe');

        $this->assertFormatoPadrao($resposta, 404, 'NAO_ENCONTRADO');
        $this->assertStringNotContainsString('<html', strtolower($resposta->getContent()));
    }

    public function test_rate_limit_429_segue_o_formato_padrao_com_retry_after(): void
    {
        $this->emProducao();
        $pastor = $this->como(PerfilSlug::Pastor);

        for ($i = 0; $i < 60; $i++) {
            $this->novaRequisicao();
            $this->actingAs($pastor)->getJson('/api/v1/dashboard')->assertOk();
        }
        $this->novaRequisicao();
        $resposta = $this->actingAs($pastor)->getJson('/api/v1/dashboard');

        $this->assertFormatoPadrao($resposta, 429, 'MUITAS_REQUISICOES');
        $this->assertGreaterThan(0, (int) $resposta->headers->get('Retry-After'));
    }
}
