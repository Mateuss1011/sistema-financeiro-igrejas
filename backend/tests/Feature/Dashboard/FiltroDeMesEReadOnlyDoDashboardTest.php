<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** ?ano_mes=AAAA-MM: omitido = mês corrente (fuso da igreja); futuro rejeitado; passado permitido. Somente leitura. */
class FiltroDeMesEReadOnlyDoDashboardTest extends TestCase
{
    use RefreshDatabase, CenarioDashboard;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sem_ano_mes_usa_o_mes_corrente(): void
    {
        $this->dashboardApi($this->como(PerfilSlug::Pastor))->assertOk()->assertJsonPath('data.ano_mes', $this->mesAtual());
    }

    public function test_mes_corrente_e_meses_anteriores_sao_aceitos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach ([$this->mesAtual(), $this->mesAnterior($this->mesAtual()), $this->mesPassado(11), '2000-01'] as $mes) {
            $this->dashboardApi($pastor, ['ano_mes' => $mes])->assertOk()->assertJsonPath('data.ano_mes', $mes);
        }
    }

    public function test_meses_futuros_sao_rejeitados_com_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach ([$this->mesSeguinte($this->mesAtual()), '2999-12', Carbon::now('America/Sao_Paulo')->addYear()->format('Y-m')] as $futuro) {
            $this->dashboardApi($pastor, ['ano_mes' => $futuro])->assertStatus(422)->assertJsonValidationErrors('ano_mes');
        }
    }

    public function test_formatos_invalidos_sao_rejeitados_com_422(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['2026-13', '2026-00', '2026-1', '26-09', '2026/09', 'setembro', '2026-09-01', "2026-09' OR '1'='1", '202609'] as $lixo) {
            $this->dashboardApi($pastor, ['ano_mes' => $lixo])->assertStatus(422)->assertJsonValidationErrors('ano_mes');
        }
        $this->actingAs($pastor)->getJson('/api/v1/dashboard?ano_mes[]=2026-01')->assertStatus(422);
    }

    public function test_ano_mes_vazio_equivale_a_omitido(): void
    {
        $this->dashboardApi($this->como(PerfilSlug::Pastor), ['ano_mes' => ''])->assertOk()->assertJsonPath('data.ano_mes', $this->mesAtual());
    }

    public function test_mes_corrente_segue_o_fuso_de_sao_paulo_e_nao_o_utc(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        // 01/10/2026 01:30 UTC = 30/09/2026 22:30 em São Paulo: o mês corrente ainda é setembro.
        Carbon::setTestNow(Carbon::parse('2026-10-01 01:30:00', 'UTC'));
        $this->dashboardApi($pastor)->assertOk()->assertJsonPath('data.ano_mes', '2026-09');
        $this->dashboardApi($pastor, ['ano_mes' => '2026-10'])->assertStatus(422);

        // 01/10/2026 03:30 UTC = 01/10/2026 00:30 em São Paulo: já é outubro.
        Carbon::setTestNow(Carbon::parse('2026-10-01 03:30:00', 'UTC'));
        $this->dashboardApi($pastor)->assertOk()->assertJsonPath('data.ano_mes', '2026-10');
        $this->dashboardApi($pastor, ['ano_mes' => '2026-10'])->assertOk();
        $this->dashboardApi($pastor, ['ano_mes' => '2026-11'])->assertStatus(422);
    }

    public function test_virada_de_ano_e_mes_com_29_dias(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ano', 'banco', '0.00');
        $categoria = $this->categoria();
        $this->entrada($conta, $categoria, $pastor, '1.00', '2024-02-29');   // bissexto: último dia de fev/2024
        $this->entrada($conta, $categoria, $pastor, '2.00', '2024-03-01');
        $this->entrada($conta, $categoria, $pastor, '4.00', '2023-12-31');

        $this->dashboardApi($pastor, ['ano_mes' => '2024-02'])->assertJsonPath('data.entradas.total', '1.00')
            ->assertJsonPath('data.saldo.referencia', '2024-02-29');
        $this->dashboardApi($pastor, ['ano_mes' => '2023-12'])->assertJsonPath('data.entradas.total', '4.00')
            ->assertJsonPath('data.saldo.referencia', '2023-12-31');
    }

    public function test_nao_existe_filtro_de_intervalo_de_datas_e_os_parametros_sao_ignorados(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $mes = $this->mesPassado();
        $this->entrada($this->conta(), $this->categoria(), $pastor, '10.00', $mes . '-10');
        $this->entrada($this->conta('Outra'), $this->categoria('Outra cat'), $pastor, '5.00', $mes . '-20');

        $sem = $this->dashboardApi($pastor, ['ano_mes' => $mes])->json('data');
        $com = $this->dashboardApi($pastor, ['ano_mes' => $mes, 'data_de' => $mes . '-15', 'data_ate' => $mes . '-25', 'conta_id' => 1])->assertOk()->json('data');

        $this->assertSame($sem, $com);
        $this->assertSame('15.00', $com['entradas']['total']);
    }

    // ---------------------------------------------------------------- somente leitura

    public function test_a_unica_rota_do_dashboard_e_um_get(): void
    {
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($rota) => str_contains($rota->uri(), 'dashboard'))
            ->map(fn ($rota) => implode('|', array_diff($rota->methods(), ['HEAD'])) . ' ' . $rota->uri())
            ->values()->all();

        $this->assertSame(['GET api/v1/dashboard'], $rotas);
    }

    public function test_metodos_de_escrita_nao_alcancam_o_dashboard(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $metodo) {
            $this->assertContains($this->actingAs($pastor)->{$metodo}('/api/v1/dashboard', ['entradas' => ['total' => '1.00']])->getStatusCode(), [404, 405]);
        }
    }

    public function test_consultar_nao_grava_nada_nem_gera_auditoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $this->entrada($conta, $this->categoria(), $pastor, '10.00', $this->mesPassado() . '-10');
        $this->despesaPendente($this->categoriaDespesa(), $pastor, ['data_competencia' => $this->mesPassado() . '-10']);
        $this->fecharApi($pastor, $this->mesPassado())->assertOk();

        $tabelas = ['entradas', 'despesas', 'contas', 'categorias', 'transferencias', 'ajustes_saldo', 'periodos_financeiros', 'permissoes_excecao', 'users'];
        $foto = fn () => collect($tabelas)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count() . ':' . md5(json_encode(DB::table($t)->orderBy('id')->get()))])->all();
        $antes = $foto();
        $auditoriaAntes = AuditLog::count();

        foreach ([[], ['ano_mes' => $this->mesPassado()], ['ano_mes' => '2000-01']] as $query) {
            $this->dashboardApi($pastor, $query)->assertOk();
            $this->dashboardApi($this->como(PerfilSlug::AuxiliarFinanceiro), $query)->assertOk();
        }

        // Criar os auxiliares acima altera 'users'; o restante tem de ficar idêntico.
        $depois = $foto();
        unset($antes['users'], $depois['users']);
        $this->assertSame($antes, $depois);
        $this->assertSame($auditoriaAntes, AuditLog::count());
    }

    public function test_resposta_nao_expoe_dados_pessoais_nem_internos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->conta('Banco Seguro', 'banco', '1.00');

        $corpo = $this->dashboardApi($pastor)->assertOk()->getContent();

        foreach ([$pastor->email, 'password', 'token', 'criado_por', 'updated_at', 'created_at', 'SELECT', 'sql'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $corpo);
        }
    }
}
