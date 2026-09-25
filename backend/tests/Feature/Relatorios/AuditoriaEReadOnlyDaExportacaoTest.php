<?php

namespace Tests\Feature\Relatorios;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Services\AuditoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Toda exportação é auditada; se a auditoria falha o arquivo NÃO sai; consultar/exportar nunca altera dados. */
class AuditoriaEReadOnlyDaExportacaoTest extends TestCase
{
    use RefreshDatabase, CenarioRelatorios;

    private object $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = (object) $this->massaDoMes($this->mesPassado(2));
    }

    private function logsDeExportacao()
    {
        return AuditLog::where('modulo', 'exportacoes')->where('acao', 'exported');
    }

    // ================================================================ auditoria

    public function test_toda_exportacao_gera_exatamente_um_log_com_usuario_relatorio_formato_e_mes(): void
    {
        foreach (['csv', 'xlsx'] as $formato) {
            $antes = $this->logsDeExportacao()->count();
            $this->exportarApi($this->m->pastor, 'entradas', $formato, ['ano_mes' => $this->m->mes])->assertOk();
            $this->assertSame($antes + 1, $this->logsDeExportacao()->count());

            $log = $this->logsDeExportacao()->latest('id')->first();
            $this->assertSame($this->m->pastor->id, $log->user_id);
            $this->assertSame($this->m->pastor->name, $log->user_nome_congelado);
            $this->assertSame('pastor', $log->user_perfil_congelado);
            $this->assertNull($log->registro_id);
            $this->assertNotNull($log->created_at, 'data/hora registrada');
            $this->assertNotNull($log->ip);
            $this->assertSame('entradas', $log->dados_novos['relatorio']);
            $this->assertSame($formato, $log->dados_novos['formato']);
            $this->assertSame($this->m->mes, $log->dados_novos['ano_mes']);
            $this->assertSame("sfg-relatorio-entradas-{$this->m->mes}.{$formato}", $log->dados_novos['arquivo']);
            $this->assertSame('gerado', $log->dados_novos['resultado']);
        }
    }

    public function test_log_registra_filtros_quantidade_de_linhas_e_totais(): void
    {
        $filtros = ['ano_mes' => $this->m->mes, 'conta_id' => $this->m->contaA->id, 'categoria_id' => $this->m->catD1->id, 'status' => 'paga', 'ordenar' => '-valor'];
        $csv = $this->lerCsv($this->exportarApi($this->m->pastor, 'despesas', 'csv', $filtros)->assertOk()->getContent());

        $log = $this->logsDeExportacao()->latest('id')->first();
        $this->assertSame(
            ['ano_mes' => $this->m->mes, 'conta_id' => $this->m->contaA->id, 'categoria_id' => $this->m->catD1->id, 'status' => 'paga'],
            $log->dados_novos['filtros'],
            'só filtros (ordenar não é filtro)'
        );
        $this->assertSame(count($csv['linhas']), $log->dados_novos['linhas'], 'quantidade de linhas exportadas');
        $this->assertSame(1, $log->dados_novos['linhas']);
        $this->assertSame('30.00', $log->dados_novos['totais']['pagas_total']);
        $this->assertSame(1, $log->dados_novos['totais']['pagas_quantidade']);
        $this->assertSame('0.00', $log->dados_novos['totais']['pendentes_total']);
    }

    public function test_log_conta_as_linhas_de_dados_para_todos_os_relatorios(): void
    {
        foreach (self::RELATORIOS as $relatorio) {
            $csv = $this->lerCsv($this->exportarApi($this->m->pastor, $relatorio, 'csv', ['ano_mes' => $this->m->mes])->assertOk()->getContent());
            $log = $this->logsDeExportacao()->latest('id')->first();

            $this->assertSame($relatorio, $log->dados_novos['relatorio']);
            $this->assertSame(count($csv['linhas']), $log->dados_novos['linhas'], $relatorio);
        }
    }

    public function test_log_nao_guarda_textos_dos_lancamentos_nem_nomes_de_pessoas(): void
    {
        $this->exportarApi($this->m->pastor, 'despesas', 'csv', ['ano_mes' => $this->m->mes])->assertOk();
        $this->exportarApi($this->m->pastor, 'entradas', 'xlsx', ['ano_mes' => $this->m->mes])->assertOk();

        foreach ($this->logsDeExportacao()->get() as $log) {
            $bruto = json_encode($log->dados_novos, JSON_UNESCAPED_UNICODE);
            foreach (['Dízimo de domingo', 'Companhia de Energia', 'Luz', $this->m->aux1->name, $this->m->tesoureiro->name, 'Oferta especial'] as $sensivel) {
                $this->assertStringNotContainsString($sensivel, $bruto);
            }
        }
    }

    public function test_administrador_e_tesoureiro_tambem_tem_a_exportacao_auditada_com_o_proprio_perfil(): void
    {
        foreach ([PerfilSlug::Administrador, PerfilSlug::Tesoureiro] as $perfil) {
            $ator = $this->como($perfil);
            $this->exportarApi($ator, 'saldos', 'csv', ['ano_mes' => $this->m->mes])->assertOk();

            $log = $this->logsDeExportacao()->where('user_id', $ator->id)->firstOrFail();
            $this->assertSame($perfil->value, $log->user_perfil_congelado);
        }
    }

    public function test_consultar_relatorios_e_catalogo_nao_gera_auditoria(): void
    {
        $antes = AuditLog::count();

        foreach (self::RELATORIOS as $relatorio) {
            $this->relatorioApi($this->m->pastor, $relatorio, ['ano_mes' => $this->m->mes])->assertOk();
        }
        $this->catalogoRelatoriosApi($this->m->pastor)->assertOk();
        $this->relatorioApi($this->m->aux1, 'entradas', ['ano_mes' => $this->m->mes])->assertOk();

        $this->assertSame($antes, AuditLog::count());
    }

    public function test_exportacao_negada_ou_invalida_nao_gera_auditoria(): void
    {
        $this->exportarApi($this->m->aux1, 'entradas', 'csv')->assertStatus(403);
        $this->exportarApi($this->como(PerfilSlug::Secretario), 'entradas', 'xlsx')->assertStatus(403);
        $this->exportarApi($this->m->pastor, 'entradas', 'csv', ['ano_mes' => '2999-01'])->assertStatus(422);
        $this->exportarApi($this->m->pastor, 'entradas', 'pdf')->assertStatus(404);
        $this->app['auth']->forgetGuards(); // o actingAs anterior continuaria valendo dentro do mesmo teste
        $this->getJson('/api/v1/relatorios/entradas/exportar/csv')->assertStatus(401);

        $this->assertSame(0, $this->logsDeExportacao()->count());
    }

    public function test_exportacao_aparece_na_tela_de_auditoria_com_rotulos_em_portugues(): void
    {
        $this->exportarApi($this->m->pastor, 'entradas', 'csv', ['ano_mes' => $this->m->mes])->assertOk();

        $r = $this->actingAs($this->m->pastor)->getJson('/api/v1/auditoria?modulo=exportacoes')->assertOk();

        $r->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.modulo_rotulo', 'Exportações')
            ->assertJsonPath('data.0.acao_rotulo', 'Exportação')
            ->assertJsonPath('data.0.dados_novos.relatorio', 'entradas');
    }

    // ================================================================ falha da auditoria

    public function test_se_a_auditoria_falhar_o_arquivo_nao_e_entregue_em_nenhum_formato(): void
    {
        $this->mock(AuditoriaService::class, fn ($mock) => $mock->shouldReceive('registrar')->andThrow(new \RuntimeException('banco de auditoria fora do ar: detalhe interno')));
        Log::spy();

        foreach (['csv', 'xlsx'] as $formato) {
            $r = $this->exportarApi($this->m->pastor, 'entradas', $formato, ['ano_mes' => $this->m->mes]);

            $r->assertStatus(500);
            $r->assertJsonStructure(['message', 'code', 'errors']);
            $r->assertJsonPath('code', 'EXPORTACAO_NAO_AUDITADA');
            $this->assertStringContainsString('application/json', $r->headers->get('Content-Type'));
            $this->assertNull($r->headers->get('Content-Disposition'), 'nenhum arquivo anexado');
            $this->assertStringNotContainsString("\xEF\xBB\xBF", $r->getContent(), 'sem conteúdo CSV');
            $this->assertStringNotContainsString('PK', substr($r->getContent(), 0, 2), 'sem conteúdo XLSX');
            $this->assertStringNotContainsString('Dízimos', $r->getContent(), 'nenhum dado do relatório vaza');
        }
        $this->assertSame(0, $this->logsDeExportacao()->count());
    }

    public function test_falha_da_auditoria_nao_expoe_detalhes_internos_e_o_erro_tecnico_vai_para_o_log(): void
    {
        $this->mock(AuditoriaService::class, fn ($mock) => $mock->shouldReceive('registrar')->andThrow(new \RuntimeException('detalhe interno secreto')));
        Log::spy();

        $r = $this->exportarApi($this->m->pastor, 'entradas', 'csv', ['ano_mes' => $this->m->mes])->assertStatus(500);

        $corpo = $r->getContent();
        foreach (['detalhe interno secreto', 'RuntimeException', 'trace', 'stack', '.php', 'exception', 'sql'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $corpo);
        }
        Log::shouldHaveReceived('error')->withArgs(fn ($mensagem) => str_contains((string) $mensagem, 'Falha ao auditar exportação'))->once();
    }

    // ================================================================ limite de linhas

    public function test_exportacao_acima_do_limite_de_linhas_retorna_422_e_nao_audita(): void
    {
        config(['relatorios.limite_exportacao' => 2]);

        foreach (['csv', 'xlsx'] as $formato) {
            $this->exportarApi($this->m->pastor, 'entradas', $formato, ['ano_mes' => $this->m->mes])
                ->assertStatus(422)
                ->assertJsonPath('code', 'EXPORTACAO_MUITO_GRANDE')
                ->assertJsonStructure(['message', 'code', 'errors']);
        }
        $this->assertSame(0, $this->logsDeExportacao()->count());
    }

    public function test_exportacao_exatamente_no_limite_e_permitida(): void
    {
        config(['relatorios.limite_exportacao' => 3]);

        $csv = $this->lerCsv($this->exportarApi($this->m->pastor, 'entradas', 'csv', ['ano_mes' => $this->m->mes])->assertOk()->getContent());

        $this->assertCount(3, $csv['linhas']);
        $this->assertSame(1, $this->logsDeExportacao()->count());
    }

    public function test_limite_padrao_configurado_e_de_dez_mil_linhas(): void
    {
        $this->assertSame(10000, config('relatorios.limite_exportacao'));
    }

    // ================================================================ somente leitura

    public function test_consultar_e_exportar_nao_alteram_lancamentos_saldos_nem_periodos_so_criam_auditoria_de_exportacao(): void
    {
        $this->fecharApi($this->m->pastor, $this->m->mes)->assertOk();
        $antes = $this->fotoDasTabelas();
        $auditoriaAntes = AuditLog::count();
        $saldoAntes = $this->relatorioApi($this->m->pastor, 'saldos', ['ano_mes' => $this->m->mes])->json('data');
        $exportacoes = 0;

        foreach ([$this->m->pastor, $this->m->tesoureiro] as $ator) {
            foreach (self::RELATORIOS as $relatorio) {
                $this->relatorioApi($ator, $relatorio, ['ano_mes' => $this->m->mes])->assertOk();
                foreach (['csv', 'xlsx'] as $formato) {
                    $this->exportarApi($ator, $relatorio, $formato, ['ano_mes' => $this->m->mes])->assertOk();
                    $exportacoes++;
                }
            }
        }
        foreach (['resumo', 'entradas', 'despesas', 'movimentacoes'] as $relatorio) {
            $this->relatorioApi($this->m->aux1, $relatorio, ['ano_mes' => $this->m->mes])->assertOk();
        }

        $this->assertSame($antes, $this->fotoDasTabelas(), 'nenhuma tabela financeira/estrutural mudou');
        $this->assertSame($saldoAntes, $this->relatorioApi($this->m->pastor, 'saldos', ['ano_mes' => $this->m->mes])->json('data'));
        $this->assertSame($auditoriaAntes + $exportacoes, AuditLog::count(), 'só as exportações criaram auditoria');
        $this->assertSame('fechado', $this->dashboardApi($this->m->pastor, ['ano_mes' => $this->m->mes])->json('data.periodo.status'));
    }

    public function test_as_rotas_de_relatorios_sao_apenas_get_e_nao_existe_rota_de_pdf(): void
    {
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($rota) => str_contains($rota->uri(), 'relatorios'))
            ->map(fn ($rota) => implode('|', array_diff($rota->methods(), ['HEAD'])) . ' ' . $rota->uri())
            ->sort()->values()->all();

        $this->assertSame([
            'GET api/v1/relatorios',
            'GET api/v1/relatorios/{relatorio}',
            'GET api/v1/relatorios/{relatorio}/exportar/{formato}',
        ], $rotas);
        foreach (collect(Route::getRoutes()->getRoutes()) as $rota) {
            $this->assertStringNotContainsString('pdf', strtolower($rota->uri()));
        }
    }

    public function test_metodos_de_escrita_nao_alcancam_os_relatorios(): void
    {
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $metodo) {
            foreach (['/api/v1/relatorios', '/api/v1/relatorios/entradas', '/api/v1/relatorios/entradas/exportar/csv'] as $url) {
                $status = $this->actingAs($this->m->pastor)->{$metodo}($url, ['x' => 1])->getStatusCode();
                $this->assertContains($status, [404, 405], "$metodo $url => $status");
            }
        }
        $this->assertSame(0, $this->logsDeExportacao()->count());
    }
}
