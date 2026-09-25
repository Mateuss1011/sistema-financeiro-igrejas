<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Despesa;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelamentoEEstornoDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    // ================= cancelamento =================

    public function test_cancelar_pendente_e_terminal_e_nao_altera_saldo(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco C', 'banco', '100.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);

        $this->cancelar($tesoureiro, $despesa, ['justificativa' => 'Fornecedor cancelou'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelada')
            ->assertJsonPath('data.motivo_cancelamento', 'Fornecedor cancelou')
            ->assertJsonPath('data.conta_id', null)
            ->assertJsonPath('data.editavel', false);

        $atual = $despesa->fresh();
        $this->assertSame($tesoureiro->id, $atual->atualizado_por);
        $this->assertNull($atual->conta_id);
        $this->assertSame('100.00', app(SaldoService::class)->saldoAtual($conta));

        // Terminal: não volta a nada.
        $this->pagar($tesoureiro, $despesa, $this->corpoPagamento($conta))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->cancelar($tesoureiro, $despesa)->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->actingAs($tesoureiro)->putJson("/api/v1/despesas/{$despesa->id}", ['valor' => '1.00'])->assertStatus(409);
        $this->estornarDespesa($this->como(PerfilSlug::Pastor), $despesa)->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PAGA');
        $this->assertSame('cancelada', $this->statusDe($despesa));
    }

    public function test_justificativa_do_cancelamento_entre_3_e_500_caracteres(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $tesoureiro);

        foreach ([[], ['justificativa' => ''], ['justificativa' => '   '], ['justificativa' => 'ab'], ['justificativa' => str_repeat('x', 501)]] as $corpo) {
            $this->cancelar($tesoureiro, $despesa, $corpo)->assertStatus(422)->assertJsonValidationErrors('justificativa');
        }
        $this->assertSame('pendente', $this->statusDe($despesa));
        $this->cancelar($tesoureiro, $despesa, ['justificativa' => str_repeat('x', 500)])->assertOk();

        $outra = $this->despesaPendente($this->categoriaDespesa('Outra C'), $tesoureiro);
        $this->cancelar($tesoureiro, $outra, ['justificativa' => 'abc'])->assertOk();
    }

    public function test_permissoes_do_cancelamento(): void
    {
        $categoria = $this->categoriaDespesa();
        $criador = $this->como(PerfilSlug::AuxiliarFinanceiro);

        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $despesa = $this->despesaPendente($categoria, $criador);
            $this->cancelar($this->como($perfil), $despesa)->assertStatus(403);
            $this->assertSame('pendente', $this->statusDe($despesa));
        }
        // O auxiliar não cancela nem a própria.
        $propria = $this->despesaPendente($categoria, $criador);
        $this->cancelar($criador, $propria)->assertStatus(403);

        foreach ([$this->como(PerfilSlug::Pastor), $this->como(PerfilSlug::Tesoureiro), $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar'])] as $ator) {
            $this->cancelar($ator, $this->despesaPendente($categoria, $criador))->assertOk();
        }
    }

    public function test_cancelar_paga_ou_estornada_retorna_409_e_periodo_fechado_bloqueia(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoriaDespesa();

        $this->cancelar($tesoureiro, $this->despesaPaga($conta, $categoria, $tesoureiro))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->cancelar($tesoureiro, $this->despesaEstornada($conta, $categoria, $tesoureiro))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');

        $pendente = $this->despesaPendente($categoria, $tesoureiro, ['data_competencia' => '2026-03-10']);
        $this->fecharPeriodo('2026-03', $pastor);
        $this->cancelar($tesoureiro, $pendente)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->cancelar($pastor, $pendente)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertSame('pendente', $this->statusDe($pendente));
    }

    public function test_cancelamento_e_auditado_com_justificativa_sem_dados_pessoais(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $tesoureiro, ['descricao' => 'Descrição secreta', 'fornecedor_nome' => 'Fornecedor Sigiloso']);

        $this->cancelar($tesoureiro, $despesa, ['justificativa' => 'Pedido duplicado'])->assertOk();

        $log = AuditLog::where('modulo', 'despesas')->where('acao', 'canceled')->sole();
        $this->assertSame($despesa->id, $log->registro_id);
        $this->assertSame('Pedido duplicado', $log->justificativa);
        $this->assertSame(['status' => 'pendente'], $log->dados_anteriores);
        $this->assertSame('cancelada', $log->dados_novos['status']);
        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
        $this->assertStringNotContainsString('Fornecedor Sigiloso', $bruto);
    }

    // ================= estorno =================

    public function test_estorno_cria_linha_vinculada_devolve_o_saldo_e_marca_a_original(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco E', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $original = $this->despesaPendente($categoria, $tesoureiro, ['valor' => '150.25', 'data_competencia' => '2026-04-10', 'descricao' => 'Secreta', 'fornecedor_nome' => 'Sigiloso']);
        $this->pagar($tesoureiro, $original, $this->corpoPagamento($conta, ['data_pagamento' => '2026-04-12']))->assertOk();
        $this->assertSame('849.75', app(SaldoService::class)->saldoAtual($conta->fresh()));

        $resposta = $this->estornarDespesa($pastor, $original, ['justificativa' => 'Pago em duplicidade'])
            ->assertStatus(201)
            ->assertJsonPath('data.eh_estorno', true)
            ->assertJsonPath('data.despesa_estornada_id', $original->id)
            ->assertJsonPath('data.status', 'paga')
            ->assertJsonPath('data.valor', '150.25')
            ->assertJsonPath('data.motivo_estorno', 'Pago em duplicidade')
            ->assertJsonPath('data.data_competencia', '2026-04-10')
            ->assertJsonPath('data.data_pagamento', '2026-04-12')
            ->assertJsonPath('data.conta_id', $conta->id)
            ->assertJsonPath('data.categoria.id', $categoria->id)
            ->assertJsonPath('data.descricao', null)
            ->assertJsonPath('data.fornecedor_nome', null)
            ->assertJsonPath('data.criado_por.id', $pastor->id)
            ->assertJsonPath('data.estornavel', false);

        $estorno = Despesa::findOrFail($resposta->json('data.id'));
        $this->assertSame($original->id, $estorno->despesa_estornada_id);
        $this->assertNull($estorno->pago_por);
        $this->assertNull($estorno->pago_em);

        $original->refresh();
        $this->assertSame('estornada', $original->status->value);
        $this->assertNull($original->despesa_estornada_id); // a relação nunca é invertida
        $this->assertSame($conta->id, $original->conta_id); // mantém os dados financeiros
        $this->assertSame('150.25', $original->valor);
        $this->assertSame($pastor->id, $original->atualizado_por);
        $this->assertDatabaseCount('despesas', 2);

        $this->assertSame('1000.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
        $this->assertSame('1000.00', $this->saldoNaApi($pastor, $conta));

        $linha = collect($this->actingAs($pastor)->getJson('/api/v1/despesas')->json('data'))->firstWhere('id', $original->id);
        $this->assertSame($estorno->id, $linha['estorno_id']);
        $this->assertFalse($linha['estornavel']);
    }

    public function test_estorno_bloqueado_para_estados_nao_pagos_duplicado_e_estorno_de_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Eb', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();

        $this->estornarDespesa($pastor, $this->despesaPendente($categoria, $pastor))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PAGA');
        $this->estornarDespesa($pastor, $this->despesaCancelada($categoria, $pastor))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PAGA');

        $paga = $this->despesaPaga($conta, $categoria, $pastor);
        $estornoId = $this->estornarDespesa($pastor, $paga)->assertStatus(201)->json('data.id');
        $this->estornarDespesa($pastor, $paga)->assertStatus(409)->assertJsonPath('code', 'DESPESA_JA_ESTORNADA');
        $this->estornarDespesa($pastor, $estornoId)->assertStatus(409)->assertJsonPath('code', 'ESTORNO_NAO_ESTORNAVEL');
        $this->estornarDespesa($pastor, 99999)->assertStatus(404);

        $this->assertSame(1, Despesa::where('despesa_estornada_id', $paga->id)->count());
        $this->assertSame(1, AuditLog::where('acao', 'reversed')->count());
    }

    public function test_estornada_nunca_e_paga_de_novo_nem_editada_nem_cancelada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Er', 'banco', '1000.00');
        $estornada = $this->despesaEstornada($conta, $this->categoriaDespesa(), $pastor);

        $this->pagar($pastor, $estornada, $this->corpoPagamento($conta))->assertStatus(409)->assertJsonPath('code', 'DESPESA_NAO_PENDENTE');
        $this->cancelar($pastor, $estornada)->assertStatus(409);
        $this->actingAs($pastor)->putJson("/api/v1/despesas/{$estornada->id}", ['valor' => '1.00'])->assertStatus(409);
        $this->actingAs($pastor)->deleteJson("/api/v1/despesas/{$estornada->id}")->assertStatus(409);
    }

    public function test_justificativa_do_estorno_entre_3_e_500_caracteres(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $paga = $this->despesaPaga($this->conta(), $this->categoriaDespesa(), $pastor);

        foreach ([[], ['justificativa' => ''], ['justificativa' => 'ab'], ['justificativa' => str_repeat('x', 501)]] as $corpo) {
            $this->estornarDespesa($pastor, $paga, $corpo)->assertStatus(422)->assertJsonValidationErrors('justificativa');
        }
        $this->assertSame('paga', $this->statusDe($paga));
        $this->estornarDespesa($pastor, $paga, ['justificativa' => str_repeat('x', 500)])->assertStatus(201);
    }

    public function test_permissoes_do_estorno_pastor_sempre_e_demais_so_com_a_excecao(): void
    {
        $conta = $this->conta('Banco Ep', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $criador = $this->como(PerfilSlug::AuxiliarFinanceiro);
        $nova = fn () => $this->despesaPaga($conta, $categoria, $criador);

        // Sem permissão: Tesoureiro sem exceção, Auxiliar, Secretário, Administrador (sem exceção, só operar, só estornar_paga).
        $bloqueados = [
            $this->como(PerfilSlug::Tesoureiro),
            $this->comExcecoes(PerfilSlug::Tesoureiro, ['despesas.operar']), // operar não vale para Tesoureiro estornar
            $criador,
            $this->como(PerfilSlug::Secretario),
            $this->como(PerfilSlug::Administrador),
            $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar']),
            $this->comExcecoes(PerfilSlug::Administrador, ['despesas.estornar_paga']),
        ];
        foreach ($bloqueados as $ator) {
            $despesa = $nova();
            $this->estornarDespesa($ator, $despesa)->assertStatus(403);
            $this->assertSame('paga', $this->statusDe($despesa));
        }

        $permitidos = [
            $this->como(PerfilSlug::Pastor),
            $this->comExcecoes(PerfilSlug::Tesoureiro, ['despesas.estornar_paga']),
            $this->comExcecoes(PerfilSlug::Administrador, ['despesas.operar', 'despesas.estornar_paga']),
        ];
        foreach ($permitidos as $ator) {
            $this->estornarDespesa($ator, $nova())->assertStatus(201);
        }
    }

    public function test_concessao_e_revogacao_das_excecoes_de_despesas_tem_efeito_imediato(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Ex', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();

        $this->estornarDespesa($tesoureiro, $this->despesaPaga($conta, $categoria, $tesoureiro))->assertStatus(403);
        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$tesoureiro->id}/permissoes-excecao", ['permissao' => 'despesas.estornar_paga'])->assertStatus(201);
        $this->estornarDespesa($tesoureiro->refresh(), $this->despesaPaga($conta, $categoria, $tesoureiro))->assertStatus(201);
        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$tesoureiro->id}/permissoes-excecao/despesas.estornar_paga")->assertOk();
        $this->estornarDespesa($tesoureiro->refresh(), $this->despesaPaga($conta, $categoria, $tesoureiro))->assertStatus(403);

        $admin = $this->como(PerfilSlug::Administrador);
        $this->actingAs($admin)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(403);
        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao", ['permissao' => 'despesas.operar'])->assertStatus(201);
        $this->actingAs($admin->refresh())->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(201);
        $this->actingAs($pastor)->deleteJson("/api/v1/usuarios/{$admin->id}/permissoes-excecao/despesas.operar")->assertOk();
        $this->actingAs($admin->refresh())->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(403);
    }

    public function test_conta_inativa_bloqueia_o_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ei', 'banco', '1000.00');
        $paga = $this->despesaPaga($conta, $this->categoriaDespesa(), $pastor);
        $conta->update(['ativa' => false]);

        $this->estornarDespesa($pastor, $paga)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
        $this->assertSame('paga', $this->statusDe($paga));
        $this->assertDatabaseCount('despesas', 1);
    }

    public function test_periodo_fechado_da_competencia_bloqueia_o_estorno_e_o_pastor_nao_ignora(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ef', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $paga = $this->despesaPaga($conta, $categoria, $pastor, ['data_competencia' => '2026-03-10', 'data_pagamento' => '2026-04-10']);
        $this->fecharPeriodo('2026-03', $pastor);

        $this->estornarDespesa($pastor, $paga)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertSame('paga', $this->statusDe($paga));

        // Só a competência conta no estorno (decisão D4): pagamento em mês fechado não bloqueia.
        $outra = $this->despesaPaga($conta, $categoria, $pastor, ['data_competencia' => '2026-05-10', 'data_pagamento' => '2026-03-15']);
        $this->estornarDespesa($pastor, $outra)->assertStatus(201);
    }

    public function test_estorno_nao_exige_saldo_e_devolve_valor_ao_caixa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $caixa = $this->conta('Caixa Es', 'caixa', '100.00');
        $categoria = $this->categoriaDespesa();
        $despesa = $this->despesaPendente($categoria, $pastor, ['valor' => '100.00']);
        $this->pagar($pastor, $despesa, $this->corpoPagamento($caixa))->assertOk();
        $this->assertSame('0.00', app(SaldoService::class)->saldoAtual($caixa->fresh()));

        $this->estornarDespesa($pastor, $despesa)->assertStatus(201);
        $this->assertSame('100.00', app(SaldoService::class)->saldoAtual($caixa->fresh()));
    }

    public function test_estorno_de_entrada_agora_considera_as_despesas_pagas_no_saldo_do_caixa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $caixa = $this->conta('Caixa Mix', 'caixa', '0.00');
        $entrada = $this->entrada($caixa, $this->categoria(), $pastor, '100.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $pastor, ['valor' => '60.00']);
        $this->pagar($pastor, $despesa, $this->corpoPagamento($caixa))->assertOk();
        $this->assertSame('40.00', app(SaldoService::class)->saldoAtual($caixa->fresh()));

        // Sem simulação: o estorno de 100 deixaria o caixa em −60 por causa da despesa paga.
        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$entrada->id}/estornar", ['justificativa' => 'teste'])
            ->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');

        // Estornando a despesa, o estorno da entrada passa a caber.
        $this->estornarDespesa($pastor, $despesa)->assertStatus(201);
        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$entrada->id}/estornar", ['justificativa' => 'teste'])->assertStatus(201);
        $this->assertSame('0.00', app(SaldoService::class)->saldoAtual($caixa->fresh()));
    }

    public function test_estorno_e_auditado_com_vinculo_conta_valor_competencia_e_justificativa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ea', 'banco', '1000.00');
        $categoria = $this->categoriaDespesa();
        $original = $this->despesaPaga($conta, $categoria, $pastor, [
            'valor' => '77.70', 'data_competencia' => '2026-02-02', 'data_pagamento' => '2026-02-03',
            'descricao' => 'Descrição secreta', 'fornecedor_nome' => 'Fornecedor Sigiloso',
        ]);

        $estornoId = $this->estornarDespesa($pastor, $original, ['justificativa' => 'Valor errado'])->json('data.id');

        $log = AuditLog::where('modulo', 'despesas')->where('acao', 'reversed')->sole();
        $this->assertSame($original->id, $log->registro_id);
        $this->assertSame($pastor->id, $log->user_id);
        $this->assertSame('Valor errado', $log->justificativa);
        $this->assertSame(['status' => 'paga'], $log->dados_anteriores);
        $this->assertSame([
            'categoria_id' => $categoria->id, 'conta_id' => $conta->id, 'valor' => '77.70', 'data_competencia' => '2026-02-02',
            'data_pagamento' => '2026-02-03', 'status' => 'estornada', 'estorno_id' => $estornoId,
        ], $log->dados_novos);
        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
        $this->assertStringNotContainsString('Fornecedor Sigiloso', $bruto);
    }

    public function test_rejeicoes_de_estorno_nao_geram_auditoria_nem_efeito_parcial(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Ej', 'banco', '1000.00', false);
        $paga = $this->despesaPaga($conta, $this->categoriaDespesa(), $pastor);
        $logs = AuditLog::count();

        $this->estornarDespesa($pastor, $paga)->assertStatus(409);
        $this->estornarDespesa($pastor, $paga, [])->assertStatus(422);
        $this->estornarDespesa($this->como(PerfilSlug::Secretario), $paga)->assertStatus(403);

        $this->assertSame($logs, AuditLog::count());
        $this->assertSame('paga', $this->statusDe($paga));
        $this->assertDatabaseCount('despesas', 1);
    }
}
