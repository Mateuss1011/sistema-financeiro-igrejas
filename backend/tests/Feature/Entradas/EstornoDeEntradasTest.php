<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Conta;
use App\Models\Entrada;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstornoDeEntradasTest extends TestCase
{
    use RefreshDatabase, CenarioEntradas;

    private function estornar($ator, int $id, array $corpo = ['justificativa' => 'Lançamento em duplicidade'])
    {
        return $this->actingAs($ator)->postJson("/api/v1/entradas/{$id}/estornar", $corpo);
    }

    public function test_estorno_cria_linha_vinculada_com_valor_positivo_e_marca_a_original(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $original = $this->entrada($conta, $categoria, $tesoureiro, '150.25', '2026-04-10', ['descricao' => 'Culto', 'contribuinte_nome' => 'Maria']);

        $resposta = $this->estornar($pastor, $original->id)
            ->assertStatus(201)
            ->assertJsonPath('data.eh_estorno', true)
            ->assertJsonPath('data.entrada_estornada_id', $original->id)
            ->assertJsonPath('data.valor', '150.25')
            ->assertJsonPath('data.status', 'confirmada')
            ->assertJsonPath('data.motivo_estorno', 'Lançamento em duplicidade')
            ->assertJsonPath('data.data_competencia', '2026-04-10')
            ->assertJsonPath('data.estornavel', false)
            ->assertJsonPath('data.criado_por.id', $pastor->id)
            ->assertJsonPath('data.conta.id', $conta->id)
            ->assertJsonPath('data.categoria.id', $categoria->id)
            ->assertJsonPath('data.descricao', null)
            ->assertJsonPath('data.contribuinte_nome', null);

        $estorno = Entrada::findOrFail($resposta->json('data.id'));
        $this->assertSame($original->id, $estorno->entrada_estornada_id);
        $this->assertSame('confirmada', $estorno->status->value);

        $original->refresh();
        $this->assertSame('estornada', $original->status->value);
        $this->assertNull($original->entrada_estornada_id); // a relação nunca é invertida
        $this->assertSame('150.25', $original->valor);
        $this->assertSame($tesoureiro->id, $original->criado_por);
        $this->assertDatabaseCount('entradas', 2);

        // A listagem mostra o vínculo e a original deixa de ser estornável.
        $linha = collect($this->actingAs($pastor)->getJson('/api/v1/entradas')->json('data'))->firstWhere('id', $original->id);
        $this->assertSame($estorno->id, $linha['estorno_id']);
        $this->assertFalse($linha['estornavel']);
        $this->assertSame('estornada', $linha['status']);
    }

    public function test_estorno_zera_o_efeito_no_saldo_e_a_conta_reflete_na_api(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco S', 'banco', '1000.00');
        $categoria = $this->categoria();

        $id = $this->actingAs($pastor)->postJson('/api/v1/entradas', $this->payload($conta, $categoria, ['valor' => '250.10']))->json('data.id');
        $this->assertSame('1250.10', $this->saldoNaApi($pastor, $conta));

        $this->estornar($pastor, $id)->assertStatus(201);
        $this->assertSame('1000.00', $this->saldoNaApi($pastor, $conta));
        $this->assertSame('1000.00', app(SaldoService::class)->saldoAtual($conta->fresh()));
    }

    public function test_justificativa_e_obrigatoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->entrada($this->conta(), $this->categoria(), $pastor);

        foreach ([[], ['justificativa' => ''], ['justificativa' => '  '], ['justificativa' => 'ab'], ['justificativa' => str_repeat('x', 501)]] as $corpo) {
            $this->estornar($pastor, $original->id, $corpo)->assertStatus(422)->assertJsonValidationErrors('justificativa');
        }

        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('entradas', 1);
        $this->estornar($pastor, $original->id, ['justificativa' => str_repeat('x', 500)])->assertStatus(201);
    }

    public function test_confirmar_saldo_negativo_deve_ser_booleano(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->entrada($this->conta(), $this->categoria(), $pastor);

        $this->estornar($pastor, $original->id, ['justificativa' => 'teste', 'confirmar_saldo_negativo' => 'talvez'])
            ->assertStatus(422)->assertJsonValidationErrors('confirmar_saldo_negativo');
    }

    public function test_segundo_estorno_da_mesma_entrada_e_bloqueado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->entrada($this->conta(), $this->categoria(), $pastor);

        $this->estornar($pastor, $original->id)->assertStatus(201);
        $this->estornar($pastor, $original->id)->assertStatus(409)->assertJsonPath('code', 'ENTRADA_JA_ESTORNADA');
        $this->assertDatabaseCount('entradas', 2);
        $this->assertSame(1, AuditLog::where('acao', 'reversed')->count());
    }

    public function test_estorno_de_estorno_e_bloqueado(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->entrada($this->conta(), $this->categoria(), $pastor);
        $estornoId = $this->estornar($pastor, $original->id)->json('data.id');

        $this->estornar($pastor, $estornoId)->assertStatus(409)->assertJsonPath('code', 'ESTORNO_NAO_ESTORNAVEL');
        $this->assertDatabaseCount('entradas', 2);
    }

    public function test_entrada_inexistente_retorna_404(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->estornar($pastor, 99999)->assertStatus(404);
        $this->actingAs($pastor)->postJson('/api/v1/entradas/abc/estornar', ['justificativa' => 'teste'])->assertStatus(404);
    }

    public function test_permissoes_do_estorno(): void
    {
        $conta = $this->conta();
        $categoria = $this->categoria();
        $criador = $this->como(PerfilSlug::AuxiliarFinanceiro);

        foreach ([PerfilSlug::AuxiliarFinanceiro, PerfilSlug::Secretario, PerfilSlug::Administrador] as $perfil) {
            $original = $this->entrada($conta, $categoria, $criador);
            $this->estornar($this->como($perfil), $original->id)->assertStatus(403);
            $this->assertSame('confirmada', $original->fresh()->status->value);
        }

        // O auxiliar não estorna nem a própria entrada.
        $propria = $this->entrada($conta, $categoria, $criador);
        $this->estornar($criador, $propria->id)->assertStatus(403);

        foreach ([$this->como(PerfilSlug::Pastor), $this->como(PerfilSlug::Tesoureiro), $this->administradorOperador()] as $ator) {
            $original = $this->entrada($conta, $categoria, $criador);
            $this->estornar($ator, $original->id)->assertStatus(201);
        }
    }

    public function test_conta_inativa_bloqueia_o_estorno(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $original = $this->entrada($conta, $this->categoria(), $pastor);
        $conta->update(['ativa' => false]);

        $this->estornar($pastor, $original->id)->assertStatus(409)->assertJsonPath('code', 'CONTA_INATIVA');
        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_periodo_fechado_bloqueia_o_estorno_e_o_estorno_herda_a_competencia_da_original(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();

        // Retroativa em período aberto: o estorno é permitido e mantém a competência original.
        $antiga = $this->entrada($conta, $categoria, $pastor, '10.00', '2024-02-15');
        $this->estornar($pastor, $antiga->id)->assertStatus(201)->assertJsonPath('data.data_competencia', '2024-02-15');

        $fechada = $this->entrada($conta, $categoria, $pastor, '10.00', '2025-06-10');
        $this->fecharPeriodo('2025-06', $pastor);
        $this->estornar($pastor, $fechada->id)->assertStatus(409)->assertJsonPath('code', 'PERIODO_FECHADO');
        $this->assertSame('confirmada', $fechada->fresh()->status->value);
    }

    public function test_estorno_trava_a_entrada_e_a_conta_com_for_update_dentro_de_transacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $original = $this->entrada($this->conta(), $this->categoria(), $pastor);
        $this->actingAs($pastor);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->postJson("/api/v1/entradas/{$original->id}/estornar", ['justificativa' => 'teste'])->assertStatus(201);
        $consultas = collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query');

        $trava = fn (string $tabela) => $consultas->filter(fn ($q) => str_contains($q, "from `$tabela`") && str_ends_with(trim($q), 'for update'))->count();
        $this->assertSame(1, $trava('entradas'));
        $this->assertSame(1, $trava('contas'));

        // A trava da conta vem ANTES da leitura do saldo (soma das entradas).
        $posTrava = $consultas->search(fn ($q) => str_contains($q, 'from `contas`') && str_ends_with(trim($q), 'for update'));
        $posSaldo = $consultas->search(fn ($q) => stripos($q, 'sum(case') !== false);
        $this->assertLessThan($posSaldo, $posTrava);
    }

    // ---------- saldo negativo ----------

    public function test_banco_que_ficaria_negativo_exige_confirmacao_explicita(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Neg', 'banco', '-100.00');
        $original = $this->entrada($conta, $this->categoria(), $pastor, '30.00'); // saldo -70; estorno -> -100

        $this->estornar($pastor, $original->id)->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->estornar($pastor, $original->id, ['justificativa' => 'teste', 'confirmar_saldo_negativo' => false])
            ->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertSame(0, AuditLog::where('acao', 'reversed')->count());

        $this->estornar($pastor, $original->id, ['justificativa' => 'Confirmado pelo tesoureiro', 'confirmar_saldo_negativo' => true])->assertStatus(201);
        $this->assertSame('-100.00', $this->saldoNaApi($pastor, $conta));

        $log = AuditLog::where('acao', 'reversed')->sole();
        $this->assertTrue($log->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_estorno_que_termina_em_saldo_zero_nao_exige_confirmacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $caixa = $this->conta('Caixa Zero', 'caixa', '0.00');
        $original = $this->entrada($caixa, $this->categoria(), $pastor, '80.00');

        $this->estornar($pastor, $original->id)->assertStatus(201);
        $this->assertSame('0.00', $this->saldoNaApi($pastor, $caixa));
        $this->assertFalse(AuditLog::where('acao', 'reversed')->sole()->dados_novos['saldo_negativo_confirmado']);
    }

    public function test_caixa_que_ficaria_negativo_e_bloqueado_mesmo_com_confirmacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $caixa = $this->conta('Caixa Baixo', 'caixa', '0.00');
        $original = $this->entrada($caixa, $this->categoria(), $pastor, '100.00');

        // Simula uma saída de 50,00 (as despesas só chegam na Fase 7) para forçar saldo insuficiente.
        $this->app->instance(SaldoService::class, new class extends SaldoService {
            public function saldosAtuais(iterable $contas): array
            {
                return array_map(fn ($saldo) => bcsub($saldo, '50.00', 2), parent::saldosAtuais($contas));
            }
        });

        $this->estornar($pastor, $original->id)->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
        $this->estornar($pastor, $original->id, ['justificativa' => 'teste', 'confirmar_saldo_negativo' => true])
            ->assertStatus(409)->assertJsonPath('code', 'SALDO_INSUFICIENTE');

        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_banco_com_saldo_simulado_insuficiente_pede_confirmacao_e_depois_estorna(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $banco = $this->conta('Banco Baixo', 'banco', '0.00');
        $original = $this->entrada($banco, $this->categoria(), $pastor, '100.00');

        $this->app->instance(SaldoService::class, new class extends SaldoService {
            public function saldosAtuais(iterable $contas): array
            {
                return array_map(fn ($saldo) => bcsub($saldo, '50.00', 2), parent::saldosAtuais($contas));
            }
        });

        $this->estornar($pastor, $original->id)->assertStatus(409)->assertJsonPath('code', 'SALDO_NEGATIVO_REQUER_CONFIRMACAO');
        $this->estornar($pastor, $original->id, ['justificativa' => 'teste', 'confirmar_saldo_negativo' => true])->assertStatus(201);
    }

    // ---------- auditoria ----------

    public function test_estorno_e_auditado_sem_dados_pessoais(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $original = $this->entrada($conta, $categoria, $pastor, '77.70', '2026-02-02', ['descricao' => 'Descrição secreta', 'contribuinte_nome' => 'João da Silva']);

        $estornoId = $this->estornar($pastor, $original->id, ['justificativa' => 'Valor digitado errado'])->json('data.id');

        $log = AuditLog::where('modulo', 'entradas')->where('acao', 'reversed')->sole();
        $this->assertSame($original->id, $log->registro_id);
        $this->assertSame($pastor->id, $log->user_id);
        $this->assertSame('Valor digitado errado', $log->justificativa);
        $this->assertSame(['status' => 'confirmada'], $log->dados_anteriores);
        $this->assertSame([
            'categoria_id' => $categoria->id,
            'conta_id' => $conta->id,
            'valor' => '77.70',
            'data_competencia' => '2026-02-02',
            'status' => 'estornada',
            'estorno_id' => $estornoId,
            'saldo_negativo_confirmado' => false,
        ], $log->dados_novos);

        $bruto = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('João da Silva', $bruto);
        $this->assertStringNotContainsString('Descrição secreta', $bruto);
    }

    public function test_falhas_de_estorno_nao_deixam_efeito_parcial(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Conta Parcial', 'banco', '-10.00');
        $original = $this->entrada($conta, $this->categoria(), $pastor, '5.00');

        $this->estornar($pastor, $original->id)->assertStatus(409);

        $this->assertSame('confirmada', $original->fresh()->status->value);
        $this->assertDatabaseCount('entradas', 1);
        $this->assertSame(0, AuditLog::where('modulo', 'entradas')->count());
    }
}
