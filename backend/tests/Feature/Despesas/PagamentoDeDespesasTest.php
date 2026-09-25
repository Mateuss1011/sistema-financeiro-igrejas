<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Despesa;
use App\Services\SaldoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagamentoDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pastor_tesoureiro_e_administrador_com_excecao_pagam_e_o_saldo_e_reduzido(): void
    {
        $categoria = $this->categoriaDespesa();
        $conta = $this->conta('Banco P', 'banco', '1000.00');
        $criador = $this->como(PerfilSlug::AuxiliarFinanceiro);

        foreach ([$this->como(PerfilSlug::Pastor), $this->como(PerfilSlug::Tesoureiro), $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar'])] as $i => $pagador) {
            $despesa = $this->despesaPendente($categoria, $criador, ['valor' => '100.00']);

            $this->pagar($pagador, $despesa, $this->corpoPagamento($conta))
                ->assertOk()
                ->assertJsonPath('data.status', 'paga')
                ->assertJsonPath('data.conta_id', $conta->id)
                ->assertJsonPath('data.conta.tipo', 'banco')
                ->assertJsonPath('data.data_pagamento', $this->hoje())
                ->assertJsonPath('data.pago_por.id', $pagador->id)
                ->assertJsonPath('data.pagavel', false);

            $atual = $despesa->fresh();
            $this->assertSame($pagador->id, $atual->pago_por);
            $this->assertSame($pagador->id, $atual->atualizado_por);
            $this->assertNotNull($atual->pago_em);
            $this->assertSame($criador->id, $atual->criado_por);
            $this->assertSame(bcsub('1000.00', bcmul('100.00', (string) ($i + 1), 2), 2), app(SaldoService::class)->saldoAtual($conta->fresh()));
        }
    }

    public function test_auxiliar_secretario_e_administrador_sem_excecao_nao_pagam(): void
    {
        $categoria = $this->categoriaDespesa();
        $conta = $this->conta();
        $despesa = $this->despesaPendente($categoria, $this->como(PerfilSlug::AuxiliarFinanceiro));

        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $this->pagar($this->como($perfil), $despesa, $this->corpoPagamento($conta))->assertStatus(403);
        }
        // Administrador com a chave de ESTORNO mas sem `operar` também não paga.
        $this->pagar($this->comExcecoes(PerfilSlug::Administrador, ['despesas.estornar_paga']), $despesa, $this->corpoPagamento($conta))->assertStatus(403);
        $this->assertSame('pendente', $this->statusDe($despesa));
    }

    public function test_pagamento_exige_conta_e_data_validas(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);
        $excluida = $this->conta('Excluída');
        $excluida->delete();
        $amanha = Carbon::now('America/Sao_Paulo')->addDay()->toDateString();

        $this->pagar($tesoureiro, $despesa, ['data_pagamento' => $this->hoje()])->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->pagar($tesoureiro, $despesa, ['conta_id' => $conta->id])->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        $this->pagar($tesoureiro, $despesa, [])->assertStatus(422)->assertJsonValidationErrors(['conta_id', 'data_pagamento']);
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['conta_id' => 99999]))->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($excluida))->assertStatus(422)->assertJsonValidationErrors('conta_id');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['data_pagamento' => $amanha]))->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        foreach (['2026-02-30', '20/09/2026', 'hoje', ''] as $data) {
            $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['data_pagamento' => $data]))->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        }
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['confirmar_saldo_negativo' => 'talvez']))->assertStatus(422)->assertJsonValidationErrors('confirmar_saldo_negativo');
        $this->pagar($tesoureiro, 99999, $this->corpoPagamento($conta))->assertStatus(404);

        $this->assertSame('pendente', $this->statusDe($despesa));
        $this->assertNull($despesa->fresh()->conta_id);
    }

    public function test_conta_inativa_bloqueia_o_pagamento(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $inativa = $this->conta('Inativa P', 'banco', '500.00', false);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($inativa))->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
        $this->assertSame('pendente', $this->statusDe($despesa));
    }

    public function test_data_de_pagamento_retroativa_e_hoje_sao_aceitas_e_o_futuro_e_avaliado_em_sao_paulo(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Dt', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();

        $this->pagar($tesoureiro, $this->despesaPendente($categoria, $tesoureiro), $this->corpoPagamento($conta, ['data_pagamento' => '2020-01-15']))
            ->assertOk()->assertJsonPath('data.data_pagamento', '2020-01-15');

        Carbon::setTestNow(Carbon::parse('2026-09-21 01:30:00', 'UTC')); // 20/09 22:30 em São Paulo
        $this->pagar($tesoureiro, $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-09-01']), $this->corpoPagamento($conta, ['data_pagamento' => '2026-09-21']))->assertStatus(422);
        $this->pagar($tesoureiro, $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-09-01']), $this->corpoPagamento($conta, ['data_pagamento' => '2026-09-20']))->assertOk();
    }

    public function test_pagamento_nao_exige_data_maior_ou_igual_a_competencia_e_pode_cruzar_meses(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Cr', 'banco', '1000.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['data_competencia' => '2026-08-20']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['data_pagamento' => '2026-07-01']))->assertOk();
        $this->pagar($tesoureiro, $this->despesaPendente($this->categoriaDespesa('Outra P'), $tesoureiro, ['data_competencia' => '2026-08-20']), $this->corpoPagamento($conta, ['data_pagamento' => '2026-09-10']))->assertOk();
    }

    public function test_periodo_fechado_da_competencia_ou_da_data_de_pagamento_bloqueia(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Pf', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $daCompetenciaFechada = $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-03-10']);
        $daDataFechada = $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-05-10']);
        $this->fecharPeriodo('2026-03', $pastor);

        // competência fechada, pagamento em mês aberto
        $this->pagar($tesoureiro, $daCompetenciaFechada, $this->corpoPagamento($conta))->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        // competência aberta, data de pagamento em mês fechado
        $this->pagar($tesoureiro, $daDataFechada, $this->corpoPagamento($conta, ['data_pagamento' => '2026-03-15']))->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        // O Pastor NÃO ignora período fechado.
        $this->pagar($pastor, $daDataFechada, $this->corpoPagamento($conta, ['data_pagamento' => '2026-03-15']))->assertStatus(409);
        // Ambos abertos
        $this->pagar($tesoureiro, $daDataFechada, $this->corpoPagamento($conta, ['data_pagamento' => '2026-04-15']))->assertOk();

        $this->assertSame('pendente', $this->statusDe($daCompetenciaFechada));
        $this->assertSame('900.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
    }

    public function test_categoria_inativa_nao_bloqueia_o_pagamento(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Ci', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $tesoureiro);
        $categoria->update(['ativa' => false]);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta))->assertOk();
    }

    // ---------------- caixa ----------------

    public function test_caixa_com_saldo_insuficiente_bloqueia_mesmo_com_confirmacao(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa P', 'caixa', '50.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '50.01']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($caixa))->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($caixa, ['confirmar_saldo_negativo' => true]))->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');

        $this->assertSame('pendente', $this->statusDe($despesa));
        $this->assertNull($despesa->fresh()->conta_id);
        $this->assertSame('50.00', app(SaldoService::class)->saldoAtual($caixa->fresh()));
        $this->assertSame(0, AuditLog::where('acao', 'paid')->count());
    }

    public function test_caixa_com_saldo_exatamente_igual_ao_valor_paga_e_fica_em_zero(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Z', 'caixa', '50.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '50.00']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($caixa))->assertOk();
        $this->assertSame('0.00', app(SaldoService::class)->saldoAtual($caixa->fresh()));
        $this->assertFalse(AuditLog::where('acao', 'paid')->sole()->dados_novos['saldo_negativo_confirmado']);

        // Nada mais cabe: qualquer centavo a mais é insuficiente.
        $outra = $this->despesaPendente($this->categoriaDespesa('Outra Z'), $tesoureiro, ['valor' => '0.01']);
        $this->pagar($tesoureiro, $outra, $this->corpoPagamento($caixa))->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
    }

    // ---------------- banco ----------------

    public function test_banco_que_ficaria_negativo_exige_confirmacao_e_depois_paga(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $banco = $this->conta('Banco N', 'banco', '30.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '100.00']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($banco))->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($banco, ['confirmar_saldo_negativo' => false]))->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->assertSame('pendente', $this->statusDe($despesa));
        $this->assertSame('30.00', app(SaldoService::class)->saldoAtual($banco->fresh()));
        $this->assertSame(0, AuditLog::where('acao', 'paid')->count());

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($banco, ['confirmar_saldo_negativo' => true]))->assertOk()->assertJsonPath('data.status', 'paga');
        $this->assertSame('-70.00', app(SaldoService::class)->saldoAtual($banco->fresh()));
        $this->assertSame('-70.00', $this->saldoNaApi($tesoureiro, $banco));
        $this->assertTrue(AuditLog::where('acao', 'paid')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_banco_ja_negativo_exige_confirmacao_para_piorar(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $banco = $this->conta('Banco Neg', 'banco', '-100.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '0.01']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($banco))->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($banco, ['confirmar_saldo_negativo' => true]))->assertOk();
        $this->assertSame('-100.01', app(SaldoService::class)->saldoAtual($banco->fresh()));
    }

    public function test_banco_que_termina_em_zero_ou_positivo_nao_exige_confirmacao(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $banco = $this->conta('Banco Ok', 'banco', '100.00');

        $this->pagar($tesoureiro, $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '100.00']), $this->corpoPagamento($banco))->assertOk();
        $this->assertSame('0.00', app(SaldoService::class)->saldoAtual($banco->fresh()));
        $this->assertFalse(AuditLog::where('acao', 'paid')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_confirmar_saldo_negativo_desnecessario_e_inofensivo(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $banco = $this->conta('Banco Fol', 'banco', '500.00');

        $this->pagar($tesoureiro, $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '10.00']), $this->corpoPagamento($banco, ['confirmar_saldo_negativo' => true]))->assertOk();
        $this->assertFalse(AuditLog::where('acao', 'paid')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    // ---------------- saldo ----------------

    public function test_saldo_exato_com_bcmath_e_entradas_e_despesas_juntas(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Ex', 'banco', '1000.00');
        $this->entrada($conta, $this->categoria(), $tesoureiro, '0.30');

        $this->pagar($tesoureiro, $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '0.10']), $this->corpoPagamento($conta))->assertOk();
        $this->pagar($tesoureiro, $this->despesaPendente($this->categoriaDespesa('Ex2'), $tesoureiro, ['valor' => '0.20']), $this->corpoPagamento($conta))->assertOk();

        $this->assertSame('1000.00', app(SaldoService::class)->saldoAtual($conta->fresh())); // 1000 + 0,30 − 0,10 − 0,20
        $this->assertSame('1000.00', $this->saldoNaApi($tesoureiro, $conta));
    }

    public function test_saldo_enviado_no_payload_e_ignorado(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Sd', 'banco', '100.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '10.00']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['saldo_atual' => '999999.00', 'valor' => '1.00', 'status' => 'estornada']))->assertOk();
        $this->assertSame('90.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
        $this->assertSame('paga', $this->statusDe($despesa));
        $this->assertSame('10.00', $despesa->fresh()->valor);
    }

    public function test_pendente_e_cancelada_nao_afetam_o_saldo(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Pc', 'banco', '100.00');
        $categoria = $this->categoriaDespesa();
        $this->despesaPendente($categoria, $tesoureiro, ['valor' => '999.00']);
        $this->despesaCancelada($categoria, $tesoureiro, ['valor' => '888.00']);

        $this->assertSame('100.00', app(SaldoService::class)->saldoAtual($conta));
        $cancelada = $this->despesaPendente($categoria, $tesoureiro, ['valor' => '5.00']);
        $this->cancelar($tesoureiro, $cancelada)->assertOk();
        $this->assertSame('100.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
    }

    // ---------------- estados e replay ----------------

    public function test_nao_paga_despesa_em_estado_terminal_ou_linha_de_estorno(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Est', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $estornada = $this->despesaEstornada($conta, $categoria, $tesoureiro);
        $estorno = Despesa::whereNotNull('despesa_estornada_id')->first();

        foreach ([$this->despesaCancelada($categoria, $tesoureiro), $estornada, $estorno] as $terminal) {
            $this->pagar($tesoureiro, $terminal, $this->corpoPagamento($conta))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        }
    }

    public function test_pagar_de_novo_com_mesmo_usuario_conta_e_data_e_replay_sem_novo_debito(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Rp', 'banco', '1000.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '100.00']);
        $corpo = $this->corpoPagamento($conta);

        $this->pagar($tesoureiro, $despesa, $corpo)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->pagar($tesoureiro, $despesa, $corpo)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.status', 'paga');
        $this->pagar($tesoureiro, $despesa, $corpo)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame('900.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
        $this->assertSame(1, AuditLog::where('acao', 'paid')->count());
    }

    public function test_pagar_de_novo_com_outro_usuario_outra_conta_ou_outra_data_retorna_409(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco A', 'banco', '1000.00');
        $outraConta = $this->conta('Banco B', 'banco', '1000.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta))->assertOk();

        $this->pagar($this->como(PerfilSlug::Pastor), $despesa, $this->corpoPagamento($conta))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($outraConta))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['data_pagamento' => '2020-01-01']))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->assertSame('900.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
        $this->assertSame('1000.00', app(SaldoService::class)->saldoAtual($outraConta->fresh()));
    }

    // ---------------- auditoria ----------------

    public function test_pagamento_e_auditado_com_conta_data_valor_e_sem_dados_pessoais(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Au', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $tesoureiro, ['valor' => '33.30', 'data_competencia' => '2026-04-05', 'descricao' => 'Descrição secreta', 'fornecedor_nome' => 'Fornecedor Sigiloso']);

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta, ['data_pagamento' => '2026-04-20']))->assertOk();

        $log = AuditLog::where('modulo', 'despesas')->where('acao', 'paid')->sole();
        $this->assertSame($despesa->id, $log->registro_id);
        $this->assertSame($tesoureiro->id, $log->user_id);
        $this->assertSame(['status' => 'pendente'], $log->dados_anteriores);
        $this->assertSame([
            'categoria_id' => $categoria->id, 'conta_id' => $conta->id, 'valor' => '33.30', 'data_competencia' => '2026-04-05',
            'data_pagamento' => '2026-04-20', 'status' => 'paga', 'saldo_negativo_confirmado' => false,
        ], $log->dados_novos);
        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
        $this->assertStringNotContainsString('Fornecedor Sigiloso', $bruto);
    }

    public function test_rejeicoes_de_pagamento_nao_geram_auditoria_nem_efeito_parcial(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $caixa = $this->conta('Caixa Rj', 'caixa', '1.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro, ['valor' => '50.00']);
        $logs = AuditLog::count();

        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($caixa))->assertStatus(409);
        $this->pagar($tesoureiro, $despesa, [])->assertStatus(422);
        $this->pagar($this->como(PerfilSlug::Secretario), $despesa, $this->corpoPagamento($caixa))->assertStatus(403);

        $this->assertSame($logs, AuditLog::count());
        $this->assertSame('pendente', $this->statusDe($despesa));
        $this->assertNull($despesa->fresh()->pago_em);
    }
}
