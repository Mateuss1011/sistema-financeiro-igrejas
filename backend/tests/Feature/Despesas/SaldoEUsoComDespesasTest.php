<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\Despesa;
use App\Services\CategoriaService;
use App\Services\ContaService;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaldoEUsoComDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    // ---------------- SaldoService ----------------

    public function test_formula_completa_entradas_estornos_despesas_e_estornos_de_despesas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco F', 'banco', '1000.00');
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();
        $saldos = app(SaldoService::class);

        $entrada = $this->entrada($conta, $catE, $pastor, '200.00');
        $this->assertSame('1200.00', $saldos->saldoAtual($conta));

        $this->entrada($conta, $catE, $pastor, '50.00', null, ['entrada_estornada_id' => $entrada->id, 'motivo_estorno' => 'x']);
        $this->assertSame('1150.00', $saldos->saldoAtual($conta));            // − estorno de entrada

        $despesa = $this->despesaPaga($conta, $catD, $pastor, ['valor' => '300.10']);
        $this->assertSame('849.90', $saldos->saldoAtual($conta));             // − despesa paga

        Despesa::create([
            'categoria_id' => $catD->id, 'conta_id' => $conta->id, 'valor' => '100.00', 'data_competencia' => $this->hoje(), 'data_pagamento' => $this->hoje(),
            'status' => 'paga', 'despesa_estornada_id' => $despesa->id, 'motivo_estorno' => 'x', 'criado_por' => $pastor->id,
        ]);
        $this->assertSame('949.90', $saldos->saldoAtual($conta));             // + estorno de despesa
        $this->assertIsString($saldos->saldoAtual($conta));
    }

    public function test_saldo_de_despesas_nao_depende_da_coluna_status_e_ignora_pendente_e_cancelada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco S', 'banco', '100.00');
        $categoria = $this->categoriaDespesa();
        $original = $this->despesaPaga($conta, $categoria, $pastor, ['valor' => '40.00']);
        Despesa::create([
            'categoria_id' => $categoria->id, 'conta_id' => $conta->id, 'valor' => '40.00', 'data_competencia' => $this->hoje(), 'data_pagamento' => $this->hoje(),
            'status' => 'paga', 'despesa_estornada_id' => $original->id, 'motivo_estorno' => 'x', 'criado_por' => $pastor->id,
        ]);
        $this->despesaPendente($categoria, $pastor, ['valor' => '999.00']);
        $this->despesaCancelada($categoria, $pastor, ['valor' => '888.00']);

        foreach (['paga', 'estornada'] as $status) {
            DB::table('despesas')->where('id', $original->id)->update(['status' => $status]);
            $this->assertSame('100.00', app(SaldoService::class)->saldoAtual($conta), "status $status");
        }
    }

    public function test_saldo_em_lote_com_entradas_e_despesas_e_igual_ao_individual(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $catE = $this->categoria();
        $catD = $this->categoriaDespesa();
        $contas = [$this->conta('L1', 'banco', '10.00'), $this->conta('L2', 'caixa', '500.00'), $this->conta('L3', 'banco', '-5.50')];
        $this->entrada($contas[0], $catE, $pastor, '1.11');
        $this->despesaPaga($contas[0], $catD, $pastor, ['valor' => '0.11']);
        $this->despesaPaga($contas[1], $catD, $pastor, ['valor' => '499.99']);

        $service = app(SaldoService::class);
        $lote = $service->saldosAtuais($contas);

        $this->assertSame('11.00', $lote[$contas[0]->id]);
        $this->assertSame('0.01', $lote[$contas[1]->id]);
        $this->assertSame('-5.50', $lote[$contas[2]->id]);
        foreach ($contas as $conta) {
            $this->assertSame($service->saldoAtual($conta), $lote[$conta->id]);
        }
    }

    public function test_listagem_de_contas_faz_uma_unica_consulta_de_despesas_e_uma_de_entradas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $catD = $this->categoriaDespesa();
        $catE = $this->categoria();
        foreach (range(1, 6) as $i) {
            $conta = $this->conta("Conta $i", 'banco', '10.00');
            $this->entrada($conta, $catE, $pastor, '5.00');
            $this->despesaPaga($conta, $catD, $pastor, ['valor' => '2.00']);
        }
        $this->actingAs($pastor)->getJson('/api/v1/contas')->assertOk();

        DB::enableQueryLog();
        $resposta = $this->actingAs($pastor)->getJson('/api/v1/contas')->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'from `despesas`'))->count());
        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'from `entradas`'))->count());
        foreach ($resposta->json('data') as $linha) {
            $this->assertSame('13.00', $linha['saldo_atual']);
        }
    }

    public function test_saldo_atual_nunca_e_persistido_e_a_api_de_contas_reflete_pagamento_e_estorno(): void
    {
        $this->assertNotContains('saldo_atual', collect(DB::select('SHOW COLUMNS FROM despesas'))->pluck('Field')->all());

        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Banco Api', 'banco', '500.00');
        $despesa = $this->despesaPendente($this->categoriaDespesa(), $pastor, ['valor' => '120.40']);
        $this->assertSame('500.00', $this->saldoNaApi($pastor, $conta));

        $this->pagar($pastor, $despesa, $this->corpoPagamento($conta))->assertOk();
        $this->assertSame('379.60', $this->saldoNaApi($pastor, $conta));
        $this->assertSame('500.00', $conta->fresh()->saldo_inicial);

        $this->estornarDespesa($pastor, $despesa)->assertStatus(201);
        $this->assertSame('500.00', $this->saldoNaApi($pastor, $conta));
    }

    // ---------------- estaEmUso ----------------

    public function test_estaemuso_da_conta_so_considera_despesas_movimentadas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $service = app(ContaService::class);

        $vazia = $this->conta('Vazia');
        $this->assertFalse($service->estaEmUso($vazia));

        // Pendente e Cancelada não têm conta: não prendem nenhuma conta.
        $this->despesaPendente($categoria, $pastor);
        $this->despesaCancelada($categoria, $pastor);
        $this->assertFalse($service->estaEmUso($vazia));

        $comPaga = $this->conta('Com paga');
        $original = $this->despesaPaga($comPaga, $categoria, $pastor);
        $this->assertTrue($service->estaEmUso($comPaga));

        // Só a linha de estorno como vínculo da conta (original em outra): também é uso.
        $soEstorno = $this->conta('So estorno');
        Despesa::create([
            'categoria_id' => $categoria->id, 'conta_id' => $soEstorno->id, 'valor' => '1.00', 'data_competencia' => $this->hoje(), 'data_pagamento' => $this->hoje(),
            'status' => 'paga', 'despesa_estornada_id' => $original->id, 'motivo_estorno' => 'x', 'criado_por' => $pastor->id,
        ]);
        $this->assertTrue($service->estaEmUso($soEstorno));
    }

    public function test_excluir_conta_com_despesa_paga_retorna_409_e_com_apenas_pendente_exclui(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa();
        $emUso = $this->conta('Em uso');
        $livre = $this->conta('Livre');
        $this->despesaPaga($emUso, $categoria, $pastor);
        $this->despesaPendente($categoria, $pastor);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$emUso->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$livre->id}")->assertOk();
        $this->assertSoftDeleted('contas', ['id' => $livre->id]);
    }

    public function test_conta_com_despesa_estornada_nao_e_excluida_mas_pode_ser_inativada(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Estornada D');
        $this->despesaEstornada($conta, $this->categoriaDespesa(), $pastor);

        $this->actingAs($pastor)->deleteJson("/api/v1/contas/{$conta->id}")->assertStatus(409)->assertJsonPath('code', 'CONTA_EM_USO');
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['ativa' => false])->assertOk();
    }

    public function test_estaemuso_da_categoria_considera_qualquer_despesa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $service = app(CategoriaService::class);

        $livre = $this->categoriaDespesa('Livre C');
        $this->assertFalse($service->estaEmUso($livre));

        foreach ([
            'Pendente' => fn ($c) => $this->despesaPendente($c, $pastor),
            'Cancelada' => fn ($c) => $this->despesaCancelada($c, $pastor),
            'Paga' => fn ($c) => $this->despesaPaga($conta, $c, $pastor),
            'Estornada' => fn ($c) => $this->despesaEstornada($conta, $c, $pastor),
        ] as $nome => $criar) {
            $categoria = $this->categoriaDespesa("Uso $nome");
            $criar($categoria);
            $this->assertTrue($service->estaEmUso($categoria), $nome);
            $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$categoria->id}")->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_EM_USO');
        }

        $this->actingAs($pastor)->deleteJson("/api/v1/categorias/{$livre->id}")->assertOk();
    }

    public function test_categoria_com_despesas_pode_ser_inativada_e_deixa_de_aceitar_novas(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $categoria = $this->categoriaDespesa('Inativar D');
        $this->despesaPendente($categoria, $pastor);

        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['ativa' => false])->assertOk();
        $this->actingAs($pastor)->postJson('/api/v1/despesas', $this->payloadDespesa($categoria))->assertStatus(409)->assertJsonPath('code', 'CATEGORIA_INATIVA');
    }

    public function test_despesas_existentes_permanecem_apos_inativar_conta_e_categoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('Vai inativar', 'banco', '100.00');
        $categoria = $this->categoriaDespesa('Vai inativar D');
        $this->despesaPaga($conta, $categoria, $pastor, ['valor' => '30.00']);
        $conta->update(['ativa' => false]);
        $categoria->update(['ativa' => false]);

        $this->actingAs($pastor)->getJson('/api/v1/despesas')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('70.00', $this->saldoNaApi($pastor, $conta));
    }
}
