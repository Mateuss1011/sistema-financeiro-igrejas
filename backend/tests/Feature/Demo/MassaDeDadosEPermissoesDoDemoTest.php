<?php

namespace Tests\Feature\Demo;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\PeriodoFinanceiro;
use App\Models\Transferencia;
use App\Models\User;
use App\Services\PeriodoFinanceiroService;
use App\Support\CatalogoAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ambiente de demonstração: a massa de dados é coerente e útil, os números fecham, e as Policies EXISTENTES continuam
 * mandando (Pastor/Administrador/Tesoureiro mantêm acesso; o Auxiliar continua restrito; o Secretário continua barrado).
 * Nada aqui cria Policy ou exceção: o teste apenas observa o comportamento atual do sistema sobre os dados demo.
 */
class MassaDeDadosEPermissoesDoDemoTest extends TestCase
{
    use RefreshDatabase, CenarioDemo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comCategoriasPadrao();
        [$codigo] = $this->rodarDemo();
        $this->assertSame(0, $codigo);
    }

    private function api(string $usuario, string $uri)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->usuarioDemo($usuario))->getJson('/api/v1' . $uri);
    }

    /** @return array<string, string> chave => valor dos totais de um relatório */
    private function totais(string $usuario, string $relatorio, string $mes): array
    {
        $resposta = $this->api($usuario, "/relatorios/{$relatorio}?ano_mes={$mes}")->assertOk();

        return collect($resposta->json('meta.totais'))->pluck('valor', 'chave')->all();
    }

    private function idsDemo(): array
    {
        return User::query()->where('email', 'like', '%@sfg.demo')->pluck('id')->all();
    }

    // ------------------------------------------------------------------ massa de dados

    public function test_contas_e_categorias_existem_e_a_conta_inativa_nao_atrapalha(): void
    {
        $contas = Conta::query()->where('nome', 'like', 'DEMO - %')->orderBy('id')->get();

        $this->assertCount(3, $contas);
        $this->assertSame(['banco', 'caixa', 'banco'], $contas->pluck('tipo')->map(fn ($t) => $t->value)->all());
        $this->assertSame([true, true, false], $contas->pluck('ativa')->map(fn ($a) => (bool) $a)->all());
        $this->assertSame(0, Categoria::query()->where('nome', 'like', 'DEMO - %')->count(), 'Com as categorias padrão do sistema, nenhuma categoria nova é criada.');
        $this->assertSame(13, Categoria::query()->count());
    }

    public function test_entradas_despesas_transferencia_ajuste_e_estornos_existem_nos_estados_esperados(): void
    {
        $ids = $this->idsDemo();

        $this->assertSame(11 + 1, Entrada::query()->whereIn('criado_por', $ids)->count(), '4 do mês anterior + 7 do atual + 1 linha de estorno');
        $this->assertSame(1, Entrada::query()->whereIn('criado_por', $ids)->whereNotNull('entrada_estornada_id')->count());

        $despesas = Despesa::query()->whereIn('criado_por', $ids);
        $this->assertSame(13, (clone $despesas)->count(), '3 do mês anterior + 9 do atual + 1 linha de estorno');
        foreach (['pendente' => 5, 'paga' => 5, 'cancelada' => 1, 'estornada' => 1] as $status => $quantidade) {
            $this->assertSame($quantidade, (clone $despesas)->whereNull('despesa_estornada_id')->where('status', $status)->count(), "despesas {$status}");
        }
        $this->assertSame(1, (clone $despesas)->whereNotNull('despesa_estornada_id')->count());

        $this->assertSame(1, Transferencia::query()->whereIn('criado_por', $ids)->count());
        $this->assertSame(1, DB::table('ajustes_saldo')->whereIn('criado_por', $ids)->count());
    }

    public function test_periodo_anterior_fechado_e_atual_aberto(): void
    {
        $periodos = app(PeriodoFinanceiroService::class);

        $this->assertTrue($periodos->estaFechado($this->mesAnterior()));
        $this->assertFalse($periodos->estaFechado($this->mesAtual()));
        $this->assertSame(1, PeriodoFinanceiro::query()->count());
        $this->assertSame($this->usuarioDemo('pastor')->id, PeriodoFinanceiro::query()->firstOrFail()->fechado_por);
    }

    public function test_relacoes_sao_validas_sem_registros_orfaos(): void
    {
        $this->assertSame(0, DB::table('entradas')->leftJoin('contas', 'contas.id', '=', 'entradas.conta_id')->whereNull('contas.id')->count());
        $this->assertSame(0, DB::table('entradas')->leftJoin('categorias', 'categorias.id', '=', 'entradas.categoria_id')->whereNull('categorias.id')->count());
        $this->assertSame(0, DB::table('despesas')->leftJoin('categorias', 'categorias.id', '=', 'despesas.categoria_id')->whereNull('categorias.id')->count());
        // Toda despesa paga/estorno tem conta e data de pagamento; toda pendente/cancelada não tem (CHECK do banco também garante).
        $this->assertSame(0, Despesa::query()->whereIn('status', ['paga', 'estornada'])->where(fn ($q) => $q->whereNull('conta_id')->orWhereNull('data_pagamento'))->count());
        $this->assertSame(0, Despesa::query()->whereIn('status', ['pendente', 'cancelada'])->whereNotNull('conta_id')->count());
        // Só perfis que a Policy atual autoriza a lançar aparecem como autores.
        $autores = User::query()->whereIn('id', Entrada::query()->pluck('criado_por')->merge(Despesa::query()->pluck('criado_por'))->unique())->get()->map(fn ($u) => $u->perfil->slug->value)->unique()->sort()->values()->all();
        $this->assertSame(['auxiliar_financeiro', 'pastor', 'tesoureiro'], $autores);
    }

    public function test_cada_operacao_demo_foi_feita_por_um_perfil_que_a_policy_atual_autoriza(): void
    {
        $autor = fn (int $id) => User::query()->findOrFail($id);

        foreach (Entrada::query()->whereNull('entrada_estornada_id')->pluck('criado_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('create', Entrada::class), 'Autor de entrada sem permissão de criar');
        }
        foreach (Entrada::query()->whereNotNull('entrada_estornada_id')->pluck('criado_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('reverse', Entrada::class), 'Estorno de entrada por perfil sem permissão');
        }
        foreach (Despesa::query()->whereNull('despesa_estornada_id')->pluck('criado_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('create', Despesa::class), 'Autor de despesa sem permissão de criar');
        }
        foreach (Despesa::query()->whereNotNull('pago_por')->pluck('pago_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('pay', Despesa::class), 'Pagamento por perfil sem permissão');
        }
        foreach (Despesa::query()->whereNotNull('despesa_estornada_id')->pluck('criado_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('reverse', Despesa::class), 'Estorno de despesa por perfil sem permissão');
        }
        foreach (Transferencia::query()->pluck('criado_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('create', Transferencia::class), 'Transferência por perfil sem permissão');
        }
        foreach (DB::table('ajustes_saldo')->pluck('criado_por')->unique() as $id) {
            $this->assertTrue($autor($id)->can('create', \App\Models\AjusteSaldo::class), 'Ajuste por perfil sem permissão');
        }
        // E o Auxiliar nunca aparece como autor de algo que a Policy lhe nega.
        $aux = $this->usuarioDemo('auxiliar');
        $this->assertSame(0, Transferencia::query()->where('criado_por', $aux->id)->count());
        $this->assertSame(0, DB::table('ajustes_saldo')->where('criado_por', $aux->id)->count());
        $this->assertSame(0, Despesa::query()->where('pago_por', $aux->id)->count());
    }

    public function test_os_valores_fecham_matematicamente(): void
    {
        $mes = $this->mesAtual();
        $dash = $this->api('pastor', "/dashboard?ano_mes={$mes}")->assertOk()->json('data');

        // Recalculado do zero, sem passar por nenhum Service de saldo.
        $this->assertSame('4616.00', $dash['entradas']['total']);
        $this->assertSame('1665.60', $dash['despesas_pagas']['total']);
        $this->assertSame(4, $dash['despesas_pendentes']['quantidade']);
        $this->assertSame('305.50', $dash['despesas_pendentes']['valor']);

        $saldos = collect($dash['saldo']['contas'])->pluck('saldo', 'nome');
        $this->assertSame('9454.75', $saldos['DEMO - Conta Bancária Principal']);
        $this->assertSame('1208.25', $saldos['DEMO - Caixa Geral']);
        $this->assertSame('0.00', $saldos['DEMO - Conta Reserva (inativa)']);
        $this->assertSame('10663.00', $dash['saldo']['total']);

        $inicial = (string) DB::table('contas')->sum('saldo_inicial');
        $entradas = (string) DB::table('entradas')->selectRaw("COALESCE(SUM(CASE WHEN entrada_estornada_id IS NULL THEN valor ELSE -valor END),0) v")->value('v');
        $despesas = (string) DB::table('despesas')->whereNotNull('conta_id')->selectRaw("COALESCE(SUM(CASE WHEN despesa_estornada_id IS NULL THEN valor ELSE -valor END),0) v")->value('v');
        $ajustes = (string) DB::table('ajustes_saldo')->selectRaw("COALESCE(SUM(CASE WHEN sentido='credito' THEN valor ELSE -valor END),0) v")->value('v');
        $this->assertSame('10663.00', bcadd(bcsub(bcadd(bcadd($inicial, $entradas, 2), $ajustes, 2), $despesas, 2), '0', 2), 'saldo inicial + entradas líquidas ± ajustes − despesas líquidas (a transferência soma zero)');
    }

    public function test_transferencia_nao_e_receita_nem_despesa(): void
    {
        $mes = $this->mesAtual();
        $mov = $this->totais('pastor', 'movimentacoes', $mes);

        $this->assertSame('800.00', $mov['transferencias_recebidas']);
        $this->assertSame('800.00', $mov['transferencias_enviadas']);
        $this->assertSame('4616.00', $mov['total_entradas'], 'A transferência recebida não entra em "entradas".');
        $this->assertSame('1665.60', $mov['total_despesas_pagas'], 'A transferência enviada não entra em "despesas".');
    }

    // ------------------------------------------------------------------ dashboard e relatórios (mês atual e anterior)

    public function test_dashboard_do_mes_anterior_mostra_periodo_fechado(): void
    {
        $dash = $this->api('pastor', '/dashboard?ano_mes=' . $this->mesAnterior())->assertOk()->json('data');

        $this->assertSame('fechado', $dash['periodo']['status']);
        $this->assertSame('3910.50', $dash['entradas']['total']);
        $this->assertSame('1510.40', $dash['despesas_pagas']['total']);
        $this->assertSame(1, $dash['despesas_pendentes']['quantidade']);
        $this->assertSame('89.90', $dash['despesas_pendentes']['valor']);

        $atual = $this->api('pastor', '/dashboard?ano_mes=' . $this->mesAtual())->assertOk()->json('data');
        $this->assertSame('aberto', $atual['periodo']['status']);
    }

    public function test_os_cinco_relatorios_consultam_os_dados_demo_com_totais_iguais_ao_dashboard(): void
    {
        foreach ([$this->mesAtual(), $this->mesAnterior()] as $mes) {
            $dash = $this->api('pastor', "/dashboard?ano_mes={$mes}")->json('data');

            foreach (['resumo', 'entradas', 'despesas', 'movimentacoes', 'saldos'] as $relatorio) {
                $resposta = $this->api('pastor', "/relatorios/{$relatorio}?ano_mes={$mes}")->assertOk();
                $this->assertNotEmpty($resposta->json('data'), "{$relatorio} de {$mes} veio vazio");
            }

            $this->assertSame($dash['entradas']['total'], $this->totais('pastor', 'entradas', $mes)['total']);
            $despesas = $this->totais('pastor', 'despesas', $mes);
            $this->assertSame($dash['despesas_pagas']['total'], $despesas['pagas_total']);
            $this->assertSame($dash['despesas_pendentes']['valor'], $despesas['pendentes_total']);
        }
    }

    public function test_tesoureiro_exporta_e_a_exportacao_e_auditada(): void
    {
        $antes = AuditLog::query()->where('modulo', 'exportacoes')->count();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->usuarioDemo('tesoureiro'))->get('/api/v1/relatorios/entradas/exportar/csv?ano_mes=' . $this->mesAtual())->assertOk();

        $this->assertSame($antes + 1, AuditLog::query()->where('modulo', 'exportacoes')->count());
    }

    // ------------------------------------------------------------------ permissões (Policies existentes)

    public function test_pastor_e_administrador_mantem_o_acesso_e_o_administrador_continua_sem_operar(): void
    {
        foreach (['pastor', 'administrador'] as $perfil) {
            foreach (['/dashboard', '/entradas', '/despesas', '/transferencias', '/ajustes', '/periodos-financeiros', '/relatorios/saldos', '/auditoria', '/usuarios', '/contas'] as $rota) {
                $this->api($perfil, $rota)->assertOk();
            }
        }

        // Regra atual (sem exceção do Pastor): o Administrador só visualiza.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->usuarioDemo('administrador'))->postJson('/api/v1/entradas', [])->assertForbidden();
    }

    public function test_tesoureiro_mantem_o_acesso_financeiro_e_continua_sem_auditoria(): void
    {
        foreach (['/dashboard', '/entradas', '/despesas', '/transferencias', '/ajustes', '/periodos-financeiros', '/relatorios/saldos', '/contas'] as $rota) {
            $this->api('tesoureiro', $rota)->assertOk();
        }
        $this->api('tesoureiro', '/auditoria')->assertForbidden();
        $this->api('tesoureiro', '/usuarios')->assertForbidden();
    }

    public function test_auxiliar_ve_somente_o_que_criou_e_continua_restrito(): void
    {
        $aux = $this->usuarioDemo('auxiliar');

        foreach (['entradas' => Entrada::class, 'despesas' => Despesa::class] as $lista => $modelo) {
            $ids = collect($this->api('auxiliar', "/{$lista}?por_pagina=100")->assertOk()->json('data'))->pluck('id')->all();
            $proprios = $modelo::query()->where('criado_por', $aux->id)->pluck('id')->all();

            $this->assertNotEmpty($proprios);
            $this->assertEqualsCanonicalizing($proprios, $ids, "O Auxiliar deve ver exatamente os próprios registros em /{$lista}");
            $this->assertGreaterThan(count($proprios), $modelo::query()->count(), 'Existem registros de outros usuários que ele NÃO vê.');
        }

        $dash = $this->api('auxiliar', '/dashboard?ano_mes=' . $this->mesAtual())->assertOk()->json('data');
        $this->assertSame('parcial', $dash['visao']);
        $this->assertSame('proprios', $dash['escopo']);
        $this->assertArrayNotHasKey('saldo', $dash);
        $this->assertArrayNotHasKey('periodo', $dash);
        $this->assertSame('435.75', $dash['entradas']['total']);
        $this->assertSame('0.00', $dash['despesas_pagas']['total']);
        $this->assertSame(2, $dash['despesas_pendentes']['quantidade']);
        $this->assertSame('101.30', $dash['despesas_pendentes']['valor']);

        $this->assertSame('435.75', $this->totais('auxiliar', 'entradas', $this->mesAtual())['total']);

        foreach (['/transferencias', '/ajustes', '/periodos-financeiros', '/auditoria', '/usuarios', '/relatorios/saldos'] as $proibida) {
            $this->api('auxiliar', $proibida)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($aux)->get('/api/v1/relatorios/entradas/exportar/csv')->assertForbidden();
        $this->actingAs($aux)->get('/api/v1/relatorios/despesas/exportar/xlsx')->assertForbidden();
    }

    public function test_secretario_continua_sem_acesso_aos_modulos_financeiros(): void
    {
        foreach (['/dashboard', '/entradas', '/despesas', '/transferencias', '/ajustes', '/periodos-financeiros', '/relatorios', '/relatorios/entradas', '/auditoria', '/contas'] as $rota) {
            $this->api('secretario', $rota)->assertForbidden();
        }
        $this->api('secretario', '/usuarios')->assertOk();
        $this->api('secretario', '/categorias')->assertOk();
    }

    // ------------------------------------------------------------------ auditoria

    public function test_a_auditoria_foi_gerada_pelos_fluxos_reais_e_continua_consultavel(): void
    {
        $ids = $this->idsDemo();
        $pares = collect(CatalogoAuditoria::pares())->map(fn ($p) => "{$p[0]}/{$p[1]}")->all();

        $logs = AuditLog::query()->get();
        $this->assertGreaterThan(40, $logs->count());
        foreach ($logs as $log) {
            $this->assertContains("{$log->modulo}/{$log->acao}", $pares, "Par fora do catálogo de auditoria: {$log->modulo}/{$log->acao}");
        }
        foreach (['usuarios', 'contas', 'entradas', 'despesas', 'transferencias', 'ajustes_saldo', 'periodos_financeiros'] as $modulo) {
            $this->assertTrue($logs->contains('modulo', $modulo), "Sem auditoria do módulo {$modulo}");
        }
        foreach (['created', 'paid', 'canceled', 'reversed', 'closed'] as $acao) {
            $this->assertTrue($logs->contains('acao', $acao), "Sem auditoria da ação {$acao}");
        }
        // Nenhum log inventado: todos foram produzidos pelos atores demo, com nome e perfil congelados.
        $this->assertSame(0, AuditLog::query()->whereNotIn('user_id', $ids)->orWhereNull('user_id')->count());
        $this->assertSame(0, AuditLog::query()->whereNull('user_nome_congelado')->count());

        $resposta = $this->api('pastor', '/auditoria?por_pagina=100')->assertOk();
        $this->assertNotEmpty($resposta->json('data'));
        $this->api('administrador', '/auditoria')->assertOk();
        $this->api('auxiliar', '/auditoria')->assertForbidden();
    }

    public function test_o_catalogo_de_auditoria_e_as_policies_nao_foram_alteradas_pela_demo(): void
    {
        // A demonstração não cria exceção de permissão e não mexe no catálogo: 10 módulos, incluindo exportações.
        $this->assertSame(0, DB::table('permissoes_excecao')->count());
        $this->assertContains('exportacoes', CatalogoAuditoria::modulos());
        $this->assertCount(10, CatalogoAuditoria::modulos());
    }
}
