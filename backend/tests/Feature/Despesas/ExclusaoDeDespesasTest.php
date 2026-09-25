<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Despesa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Exclusão física: só Pendente; dono + 48h + período aberto + permissão; exceção auditada do Pastor. */
class ExclusaoDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function excluir(User $ator, Despesa|int $despesa)
    {
        $id = $despesa instanceof Despesa ? $despesa->id : $despesa;

        return $this->actingAs($ator)->deleteJson("/api/v1/despesas/{$id}");
    }

    public function test_tesoureiro_exclui_a_propria_pendente_dentro_de_48h_e_a_exclusao_e_fisica_e_auditada(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $tesoureiro, ['valor' => '12.00', 'descricao' => 'Descrição secreta', 'fornecedor_nome' => 'Fornecedor Sigiloso']);

        $this->excluir($tesoureiro, $despesa)->assertOk();

        $this->assertDatabaseMissing('despesas', ['id' => $despesa->id]);
        $log = AuditLog::where('modulo', 'despesas')->where('acao', 'deleted')->sole();
        $this->assertSame($despesa->id, $log->registro_id);
        $this->assertSame($tesoureiro->id, $log->user_id);
        $this->assertSame('12.00', $log->dados_anteriores['valor']);
        $this->assertSame('pendente', $log->dados_anteriores['status']);
        $this->assertSame(['excecao_pastor' => false, 'condicoes_ignoradas' => []], $log->dados_novos);
        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
        $this->assertStringNotContainsString('Fornecedor Sigiloso', $bruto);
    }

    public function test_condicao_de_criador_tesoureiro_nao_exclui_a_de_outro(): void
    {
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $this->como(PerfilSlug::Tesoureiro));

        $this->excluir($this->como(PerfilSlug::Tesoureiro), $despesa)->assertStatus(403);
        $this->excluir($this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar']), $despesa)->assertStatus(403);
        $this->assertDatabaseHas('despesas', ['id' => $despesa->id]);
        $this->assertSame(0, AuditLog::where('acao', 'deleted')->count());
    }

    public function test_condicao_de_48h_limite_exato_permitido_e_depois_disso_409(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));
        $noLimite = $this->despesaPendente($categoria, $tesoureiro);
        $passou = $this->despesaPendente($categoria, $tesoureiro);

        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'UTC')); // exatamente 48h
        $this->excluir($tesoureiro, $noLimite)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-12 12:01:00', 'UTC')); // 48h + 1min
        $this->excluir($tesoureiro, $passou)->assertStatus(409)->assertJsonPath('code', 'JANELA_EXCLUSAO_EXPIRADA');
        $this->assertDatabaseHas('despesas', ['id' => $passou->id]);
    }

    public function test_condicao_de_permissao_auxiliar_secretario_e_administrador_sem_excecao_nao_excluem(): void
    {
        $categoria = $this->categoriaDespesa();

        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $ator = $this->como($perfil);
            $propria = $this->despesaPendente($categoria, $ator); // até a própria
            $this->excluir($ator, $propria)->assertStatus(403);
            $this->assertDatabaseHas('despesas', ['id' => $propria->id]);
        }
    }

    public function test_administrador_com_operar_exclui_a_propria(): void
    {
        $admin = $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar']);

        $this->excluir($admin, $this->despesaPendente($this->categoriaDespesa(), $admin))->assertOk();
    }

    public function test_condicao_de_status_so_pendente_ate_o_pastor_nao_exclui_paga_cancelada_ou_estornada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoriaDespesa();
        $estornada = $this->despesaEstornada($conta, $categoria, $tesoureiro);
        $estorno = Despesa::whereNotNull('despesa_estornada_id')->first();

        foreach ([$this->despesaPaga($conta, $categoria, $tesoureiro), $this->despesaCancelada($categoria, $tesoureiro), $estornada, $estorno] as $protegida) {
            $this->excluir($tesoureiro, $protegida)->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
            $this->excluir($pastor, $protegida)->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
            $this->assertDatabaseHas('despesas', ['id' => $protegida->id]);
        }
    }

    public function test_condicao_de_periodo_aberto_ate_o_pastor_nao_ignora_periodo_fechado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $doTesoureiro = $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-03-10']);
        $doPastor = $this->despesaPendente($categoria, $pastor, ['data_competencia' => '2026-03-11']);
        $this->fecharPeriodo('2026-03', $pastor);

        $this->excluir($tesoureiro, $doTesoureiro)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->excluir($pastor, $doTesoureiro)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->excluir($pastor, $doPastor)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertSame(2, Despesa::count());
    }

    public function test_pastor_exclui_de_outro_criador_e_apos_48h_com_excecao_auditada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $auxiliar = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $categoria = $this->categoriaDespesa();

        // outro criador, dentro da janela
        $deOutro = $this->despesaPendente($categoria, $auxiliar);
        $this->excluir($pastor, $deOutro)->assertOk();

        // própria, mas fora da janela
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', 'UTC'));
        $propriaAntiga = $this->despesaPendente($categoria, $pastor);
        // de outro criador e fora da janela
        $antigaDeOutro = $this->despesaPendente($categoria, $auxiliar);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        $this->excluir($pastor, $propriaAntiga)->assertOk();
        $this->excluir($pastor, $antigaDeOutro)->assertOk();

        $logs = AuditLog::where('modulo', 'despesas')->where('acao', 'deleted')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame(['excecao_pastor' => true, 'condicoes_ignoradas' => ['outro_criador']], $logs[0]->dados_novos);
        $this->assertSame(['excecao_pastor' => true, 'condicoes_ignoradas' => ['janela_48h_expirada']], $logs[1]->dados_novos);
        $this->assertSame(['excecao_pastor' => true, 'condicoes_ignoradas' => ['outro_criador', 'janela_48h_expirada']], $logs[2]->dados_novos);
        $this->assertSame(0, Despesa::count());
    }

    public function test_pastor_exclui_a_propria_dentro_da_janela_sem_excecao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->excluir($pastor, $this->despesaPendente($this->categoriaDespesa(), $pastor))->assertOk();

        $this->assertSame(['excecao_pastor' => false, 'condicoes_ignoradas' => []], AuditLog::where('acao', 'deleted')->sole()->dados_novos);
    }

    public function test_despesa_inexistente_retorna_404_e_rejeicoes_nao_geram_auditoria(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $this->excluir($tesoureiro, 99999)->assertStatus(404);

        $categoria = $this->categoriaDespesa();
        $paga = $this->despesaPaga($this->conta(), $categoria, $tesoureiro);
        $this->excluir($tesoureiro, $paga)->assertStatus(409);
        $this->excluir($this->como(PerfilSlug::Secretario), $this->despesaPendente($categoria, $tesoureiro))->assertStatus(403);

        $this->assertSame(0, AuditLog::where('acao', 'deleted')->count());
    }

    public function test_excluir_a_unica_despesa_libera_a_categoria_para_exclusao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('So D');
        $despesa = $this->despesaPendente($categoria, $pastor);

        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$categoria->id}")->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_EM_USO');
        $this->excluir($pastor, $despesa)->assertOk();
        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$categoria->id}")->assertOk();
    }
}
