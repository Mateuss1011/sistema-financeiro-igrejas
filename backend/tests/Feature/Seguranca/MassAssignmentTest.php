<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\Perfil;
use App\Models\PermissaoExcecao;
use App\Models\Transferencia;
use App\Models\AjusteSaldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 13 — mass assignment pela API. Campos que o servidor decide (dono, status, ids internos, valores calculados,
 * carimbos de tempo, hash/chave de idempotência, campos de estorno/pagamento/auditoria, perfil/ativo onde não cabem)
 * são enviados de propósito no corpo. O contrato: ou o campo é IGNORADO (o registro nasce com os valores do servidor)
 * ou a requisição é recusada (422) — nunca o valor do cliente chega ao banco.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    private function intrusos(User $outro): array
    {
        return [
            'id' => 987654,
            'criado_por' => $outro->id,
            'atualizado_por' => $outro->id,
            'pago_por' => $outro->id,
            'pago_em' => '2001-01-01 00:00:00',
            'user_id' => $outro->id,
            'status' => 'estornada',
            'saldo_atual' => '999999.99',
            'saldo' => '999999.99',
            'created_at' => '2001-01-01 00:00:00',
            'updated_at' => '2001-01-01 00:00:00',
            'deleted_at' => '2001-01-01 00:00:00',
            'hash_payload' => 'forjado',
            'chave_idempotencia' => 'forjada',
            'data_pagamento' => '2001-01-01',
            'despesa_estornada_id' => 1,
            'entrada_estornada_id' => 1,
            'transferencia_estornada_id' => 1,
            'motivo_estorno' => 'forjado',
            'motivo_cancelamento' => 'forjado',
            'perfil_id' => 999,
            'perfil' => 'pastor',
            'ativo' => false,
            'ativa' => false,
            'remember_token' => 'forjado',
            'email_verified_at' => '2001-01-01 00:00:00',
            'ultimo_login_em' => '2001-01-01 00:00:00',
            'concedida_por' => $outro->id,
            'fechado_por' => $outro->id,
            'is_admin' => true,
        ];
    }

    public function test_entrada_nasce_com_os_valores_do_servidor(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $outro = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('A', 'banco', '0.00');
        $cat = $this->categoria('Dízimos');

        $resposta = $this->actingAs($tesoureiro)->postJson('/api/v1/entradas', $this->payload($conta, $cat) + $this->intrusos($outro));
        $resposta->assertCreated();

        $entrada = Entrada::query()->latest('id')->firstOrFail();
        $this->assertNotSame(987654, $entrada->id);
        $this->assertSame($tesoureiro->id, $entrada->criado_por);
        $this->assertSame('confirmada', $entrada->status->value ?? $entrada->status);
        $this->assertNull($entrada->entrada_estornada_id);
        $this->assertNull($entrada->motivo_estorno);
        $this->assertNull($entrada->chave_idempotencia === 'forjada' ? 'forjada' : null);
        $this->assertGreaterThan('2020-01-01', $entrada->created_at->toDateString());
    }

    public function test_despesa_nasce_pendente_sem_pagamento_e_com_o_autor_correto(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $outro = $this->como(PerfilSlug::Pastor);
        $cat = $this->categoriaDespesa('Energia');

        $this->actingAs($tesoureiro)->postJson('/api/v1/despesas', $this->payloadDespesa($cat) + $this->intrusos($outro))->assertCreated();

        $despesa = Despesa::query()->latest('id')->firstOrFail();
        $this->assertNotSame(987654, $despesa->id);
        $this->assertSame($tesoureiro->id, $despesa->criado_por);
        $this->assertSame('pendente', $despesa->status->value ?? $despesa->status);
        $this->assertNull($despesa->data_pagamento);
        $this->assertNull($despesa->conta_id);
        $this->assertNull($despesa->pago_por);
        $this->assertNull($despesa->pago_em);
        $this->assertNull($despesa->despesa_estornada_id);
        $this->assertNull($despesa->motivo_estorno);
        $this->assertNull($despesa->motivo_cancelamento);
        $this->assertNull($despesa->atualizado_por);
    }

    public function test_edicao_de_despesa_recusa_campos_protegidos_e_nao_altera_nada(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $cat = $this->categoriaDespesa('Energia');
        $despesa = $this->despesaPendente($cat, $tesoureiro);
        $antes = $despesa->fresh()->toArray();

        foreach (['status' => 'paga', 'conta_id' => 1, 'data_pagamento' => '2020-01-01', 'pago_por' => 1, 'pago_em' => '2020-01-01 00:00:00',
            'criado_por' => 999, 'atualizado_por' => 999, 'despesa_estornada_id' => 1, 'motivo_estorno' => 'x', 'motivo_cancelamento' => 'x', 'saldo_atual' => '1.00'] as $campo => $valor) {
            $this->novaRequisicao();
            $this->actingAs($tesoureiro)->putJson("/api/v1/despesas/{$despesa->id}", ['descricao' => 'Novo texto', $campo => $valor])->assertStatus(422);
        }

        $this->assertSame($antes, $despesa->fresh()->toArray(), 'Nenhuma tentativa com campo protegido pode ter alterado a despesa.');
    }

    public function test_transferencia_e_ajuste_nascem_com_os_valores_do_servidor(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $outro = $this->como(PerfilSlug::Pastor);
        $a = $this->conta('A', 'banco', '500.00');
        $b = $this->conta('B', 'caixa', '0.00');

        $this->actingAs($tesoureiro)->postJson('/api/v1/transferencias', [
            'conta_origem_id' => $a->id, 'conta_destino_id' => $b->id, 'valor' => '10.00', 'data_transferencia' => $this->hoje(),
        ] + $this->intrusos($outro))->assertCreated();

        $transferencia = Transferencia::query()->latest('id')->firstOrFail();
        $this->assertNotSame(987654, $transferencia->id);
        $this->assertSame($tesoureiro->id, $transferencia->criado_por);
        $this->assertSame('confirmada', $transferencia->status->value ?? $transferencia->status);
        $this->assertNull($transferencia->transferencia_estornada_id);

        $this->novaRequisicao();
        $this->actingAs($tesoureiro)->postJson('/api/v1/ajustes', [
            'conta_id' => $a->id, 'valor' => '1.00', 'sentido' => 'credito', 'data_ajuste' => $this->hoje(), 'justificativa' => 'Conferência',
        ] + $this->intrusos($outro))->assertCreated();

        $ajuste = AjusteSaldo::query()->latest('id')->firstOrFail();
        $this->assertNotSame(987654, $ajuste->id);
        $this->assertSame($tesoureiro->id, $ajuste->criado_por);
        $this->assertNotSame('forjado', $ajuste->hash_payload);
        $this->assertNotSame('forjada', $ajuste->chave_idempotencia);
    }

    public function test_conta_e_categoria_nao_aceitam_id_saldo_nem_estado_do_cliente(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $outro = $this->como(PerfilSlug::Administrador);

        $this->actingAs($pastor)->postJson('/api/v1/contas', ['nome' => 'Nova', 'tipo' => 'banco', 'saldo_inicial' => '10.00'] + $this->intrusos($outro))->assertCreated();
        $conta = Conta::query()->latest('id')->firstOrFail();
        $this->assertNotSame(987654, $conta->id);
        $this->assertTrue((bool) $conta->ativa, 'Conta nova nasce ativa, não importa o "ativa" enviado.');
        $this->assertSame('10.00', $conta->saldo_inicial);

        $this->novaRequisicao();
        $this->actingAs($pastor)->postJson('/api/v1/categorias', ['nome' => 'Nova cat', 'tipo' => 'entrada'] + $this->intrusos($outro))->assertCreated();
        $categoria = Categoria::query()->latest('id')->firstOrFail();
        $this->assertNotSame(987654, $categoria->id);
        $this->assertTrue((bool) $categoria->ativa);

        // Saldo inicial e tipo são imutáveis depois de criados (regra de negócio): tentar mudar é 422 e não muda nada.
        foreach (['saldo_inicial' => '5.00', 'tipo' => 'caixa'] as $campo => $valor) {
            $this->novaRequisicao();
            $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", [$campo => $valor])->assertStatus(422);
        }
        // O saldo é calculado (não existe coluna para gravá-lo): o campo enviado é simplesmente ignorado.
        $this->novaRequisicao();
        $this->actingAs($pastor)->putJson("/api/v1/contas/{$conta->id}", ['nome' => 'Renomeada', 'saldo_atual' => '5.00', 'saldo' => '5.00'])->assertOk();
        $this->assertSame('10.00', $conta->fresh()->saldo_inicial);
        $this->assertSame('Renomeada', $conta->fresh()->nome);
        $this->novaRequisicao();
        $this->actingAs($pastor)->putJson("/api/v1/categorias/{$categoria->id}", ['tipo' => 'despesa'])->assertStatus(422);
    }

    public function test_usuario_criado_nao_aceita_estado_interno_do_cliente(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $perfilTesoureiro = Perfil::where('slug', PerfilSlug::Tesoureiro->value)->value('id');

        $this->actingAs($pastor)->postJson('/api/v1/usuarios', [
            'name' => 'Novo', 'email' => 'novo@exemplo.com', 'password' => 'Senh4-Forte!2026', 'perfil_id' => $perfilTesoureiro,
            'perfil' => 'pastor', 'role' => 'pastor', 'is_admin' => true, 'id' => 987654, 'remember_token' => 'forjado',
            'email_verified_at' => '2001-01-01 00:00:00', 'ultimo_login_em' => '2001-01-01 00:00:00', 'created_at' => '2001-01-01 00:00:00',
        ])->assertCreated();

        $criado = User::query()->where('email', 'novo@exemplo.com')->firstOrFail();
        $this->assertNotSame(987654, $criado->id);
        $this->assertSame($perfilTesoureiro, $criado->perfil_id);
        $this->assertNull($criado->remember_token);
        $this->assertNull($criado->email_verified_at);
        $this->assertNull($criado->ultimo_login_em);
        $this->assertTrue($criado->ativo);
        $this->assertGreaterThan('2020-01-01', $criado->created_at->toDateString());
    }

    public function test_administrador_nao_promove_ninguem_nem_a_si_mesmo_por_payload(): void
    {
        $admin = $this->como(PerfilSlug::Administrador);
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $perfilPastor = Perfil::where('slug', PerfilSlug::Pastor->value)->value('id');
        $perfilAdmin = Perfil::where('slug', PerfilSlug::Administrador->value)->value('id');

        // Promover um Tesoureiro a Pastor/Administrador: barrado pela Policy (perfil de destino privilegiado).
        foreach ([$perfilPastor, $perfilAdmin] as $destino) {
            $this->novaRequisicao();
            $this->actingAs($admin)->putJson("/api/v1/usuarios/{$tesoureiro->id}", ['perfil_id' => $destino])->assertForbidden();
        }
        // Auto-promoção: o Administrador não edita a si mesmo (nem por perfil_id, nem por payload disfarçado).
        $this->novaRequisicao();
        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$admin->id}", ['perfil_id' => $perfilPastor])->assertForbidden();
        $this->novaRequisicao();
        $this->actingAs($admin)->putJson("/api/v1/usuarios/{$admin->id}", ['name' => 'Eu mesmo', 'perfil' => 'pastor'])->assertForbidden();
        // Criar Pastor/Administrador: também barrado.
        foreach ([$perfilPastor, $perfilAdmin] as $destino) {
            $this->novaRequisicao();
            $this->actingAs($admin)->postJson('/api/v1/usuarios', ['name' => 'Chefe', 'email' => "chefe{$destino}@exemplo.com", 'password' => 'Senh4-Forte!2026', 'perfil_id' => $destino])->assertForbidden();
        }

        $this->assertSame(PerfilSlug::Tesoureiro, $this->recarregado($tesoureiro)->perfil->slug);
        $this->assertSame(PerfilSlug::Administrador, $this->recarregado($admin)->perfil->slug);
    }

    public function test_edicao_de_usuario_ignora_senha_e_campos_internos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $alvo = $this->como(PerfilSlug::Tesoureiro);
        $hashAntes = $alvo->password;

        $this->actingAs($pastor)->putJson("/api/v1/usuarios/{$alvo->id}", [
            'name' => 'Nome novo', 'password' => 'Trocada!Por-Payload1', 'remember_token' => 'forjado', 'email_verified_at' => null,
            'id' => 987654, 'ultimo_login_em' => '2001-01-01 00:00:00', 'created_at' => '2001-01-01 00:00:00',
        ])->assertOk();

        $depois = $this->recarregado($alvo);
        $this->assertSame('Nome novo', $depois->name);
        $this->assertSame($hashAntes, $depois->password, 'A senha nunca muda por este endpoint.');
        $this->assertSame($alvo->id, $depois->id);
        $this->assertNotSame('forjado', $depois->remember_token);
        $this->assertNotNull($depois->email_verified_at);
        $this->assertNull($depois->ultimo_login_em);
        $this->assertGreaterThan('2020-01-01', $depois->created_at->toDateString());
    }

    public function test_excecao_de_permissao_registra_o_pastor_e_o_usuario_da_rota_e_nao_os_do_payload(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $alvo = $this->como(PerfilSlug::Tesoureiro);
        $outro = $this->como(PerfilSlug::Administrador);

        $this->actingAs($pastor)->postJson("/api/v1/usuarios/{$alvo->id}/permissoes-excecao", [
            'permissao' => 'entradas.operar', 'user_id' => $outro->id, 'concedida_por' => $outro->id, 'id' => 987654,
        ])->assertStatus(201);

        $excecao = PermissaoExcecao::query()->latest('id')->firstOrFail();
        $this->assertSame($alvo->id, $excecao->user_id);
        $this->assertSame($pastor->id, $excecao->concedida_por);
        $this->assertNotSame(987654, $excecao->id);
    }

    public function test_reabertura_de_periodo_nao_aceita_estado_do_cliente(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $outro = $this->como(PerfilSlug::Tesoureiro);
        $mes = $this->mesPassado(3);
        $this->fecharApi($pastor, $mes);

        $this->novaRequisicao();
        $this->actingAs($pastor)->postJson("/api/v1/periodos-financeiros/{$mes}/reabrir", [
            'justificativa' => 'Correção necessária', 'reaberto_por' => $outro->id, 'fechado_por' => $outro->id, 'status' => 'fechado', 'ano_mes' => '2099-01',
        ])->assertOk();

        $periodo = \App\Models\PeriodoFinanceiro::query()->where('ano_mes', $mes)->firstOrFail();
        $this->assertSame($pastor->id, $periodo->reaberto_por);
        $this->assertSame('aberto', $periodo->status->value);
        $this->assertSame(0, \App\Models\PeriodoFinanceiro::query()->where('ano_mes', '2099-01')->count());
    }
}
