<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Despesa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotenciaDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    private function enviar(User $ator, array $corpo, ?string $chave)
    {
        return $this->actingAs($ator)->postJson('/api/v1/despesas', $corpo, $chave === null ? [] : ['Idempotency-Key' => $chave]);
    }

    public function test_mesma_chave_e_mesmo_payload_devolve_a_despesa_sem_duplicar(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payloadDespesa($this->categoriaDespesa(), ['fornecedor_nome' => 'Copel']);

        $primeira = $this->enviar($ator, $corpo, 'chave-1')->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $segunda = $this->enviar($ator, $corpo, 'chave-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $terceira = $this->enviar($ator, $corpo, 'chave-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($primeira->json('data.id'), $segunda->json('data.id'));
        $this->assertSame($primeira->json('data'), $segunda->json('data'));
        $this->assertSame($primeira->json('data.id'), $terceira->json('data.id'));
        $this->assertDatabaseCount('despesas', 1);
        $this->assertSame(1, AuditLog::where('modulo', 'despesas')->count());

        $despesa = Despesa::sole();
        $this->assertSame('chave-1', $despesa->chave_idempotencia);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $despesa->hash_payload);
    }

    public function test_mesma_chave_com_payload_diferente_retorna_409(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa('Cat A');
        $base = $this->payloadDespesa($categoria, ['fornecedor_nome' => 'Copel', 'data_competencia' => '2026-05-01']);
        $this->enviar($ator, $base, 'k')->assertStatus(201);

        $variacoes = [
            ['valor' => '100.01'],
            ['categoria_id' => $this->categoriaDespesa('Cat B')->id],
            ['data_competencia' => '2026-05-02'],
            ['descricao' => 'Outra'],
            ['fornecedor_nome' => 'Sanepar'],
            ['fornecedor_nome' => null],
        ];
        foreach ($variacoes as $variacao) {
            $this->enviar($ator, array_merge($base, $variacao), 'k')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUTILIZADA');
        }
        $this->assertDatabaseCount('despesas', 1);
    }

    public function test_replay_compara_o_hash_normalizado(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();

        $this->enviar($ator, $this->payloadDespesa($categoria, ['valor' => '100.5', 'descricao' => '  Luz  ', 'fornecedor_nome' => '']), 'norm')->assertStatus(201);

        $this->enviar($ator, $this->payloadDespesa($categoria, ['valor' => '100.50', 'descricao' => 'Luz', 'fornecedor_nome' => null]), 'norm')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->enviar($ator, $this->payloadDespesa($categoria, ['valor' => 100.5, 'descricao' => 'Luz']), 'norm')->assertStatus(200);
        $this->enviar($ator, $this->payloadDespesa($categoria, ['valor' => '100.500', 'descricao' => 'Luz']), 'norm')->assertStatus(422);
        $this->assertDatabaseCount('despesas', 1);
    }

    public function test_replay_apos_editar_a_pendente_ainda_e_replay_e_devolve_o_estado_atual(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payloadDespesa($this->categoriaDespesa(), ['valor' => '100.00']);
        $id = $this->enviar($ator, $corpo, 'edit')->assertStatus(201)->json('data.id');

        $this->actingAs($ator)->putJson("/api/v1/despesas/{$id}", ['valor' => '250.00', 'descricao' => 'Editada'])->assertOk();

        // O hash é o da CRIAÇÃO (imutável): o mesmo payload original continua sendo replay,
        // e a resposta traz o estado ATUAL da despesa.
        $replay = $this->enviar($ator, $corpo, 'edit')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($id, $replay->json('data.id'));
        $this->assertSame('250.00', $replay->json('data.valor'));
        $this->assertSame('Editada', $replay->json('data.descricao'));

        // E o payload já editado NÃO casa com o hash original.
        $this->enviar($ator, array_merge($corpo, ['valor' => '250.00', 'descricao' => 'Editada']), 'edit')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUTILIZADA');
        $this->assertDatabaseCount('despesas', 1);
    }

    public function test_replay_apos_pagar_ou_cancelar_devolve_o_estado_atual(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Banco Id', 'banco', '1000.00');
        $corpo = $this->payloadDespesa($this->categoriaDespesa());
        $id = $this->enviar($ator, $corpo, 'pago')->assertStatus(201)->json('data.id');
        $this->pagar($ator, $id, $this->corpoPagamento($conta))->assertOk();

        $this->enviar($ator, $corpo, 'pago')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.status', 'paga');
        $this->assertDatabaseCount('despesas', 1);
    }

    public function test_chaves_sao_independentes_por_usuario_e_por_valor(): void
    {
        $a = $this->como(PerfilSlug::Tesoureiro);
        $b = $this->como(PerfilSlug::Pastor);
        $corpo = $this->payloadDespesa($this->categoriaDespesa());

        $this->enviar($a, $corpo, 'mesma')->assertStatus(201);
        $this->enviar($b, $corpo, 'mesma')->assertStatus(201);
        $this->enviar($a, $corpo, 'outra')->assertStatus(201);
        $this->assertDatabaseCount('despesas', 3);
    }

    public function test_sem_chave_cada_requisicao_cria_uma_despesa_e_sem_hash(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payloadDespesa($this->categoriaDespesa());

        $this->enviar($ator, $corpo, null)->assertStatus(201);
        $this->enviar($ator, $corpo, null)->assertStatus(201);

        $this->assertDatabaseCount('despesas', 2);
        $this->assertSame(2, Despesa::whereNull('chave_idempotencia')->whereNull('hash_payload')->count());
    }

    public function test_chave_invalida_retorna_422_e_o_header_e_a_unica_fonte(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payloadDespesa($this->categoriaDespesa());

        foreach ([str_repeat('a', 65), 'com espaço', 'acentuação'] as $chave) {
            $this->enviar($ator, $corpo, $chave)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        }
        $this->enviar($ator, $corpo, str_repeat('a', 64))->assertStatus(201);
        $this->enviar($ator, $corpo, '550e8400-e29b-41d4-a716-446655440000')->assertStatus(201);

        $this->enviar($ator, $corpo + ['idempotency_key' => 'no-corpo', 'chave_idempotencia' => 'no-corpo'], null)->assertStatus(201);
        $this->assertSame(1, Despesa::whereNull('chave_idempotencia')->count());
    }

    public function test_unique_do_banco_e_o_check_de_chave_e_hash_protegem_fora_da_aplicacao(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $categoria = $this->categoriaDespesa();
        $hash = str_repeat('a', 64);
        $this->despesaPendente($categoria, $ator, ['chave_idempotencia' => 'db', 'hash_payload' => $hash]);

        try {
            $this->despesaPendente($categoria, $ator, ['chave_idempotencia' => 'db', 'hash_payload' => $hash]);
            $this->fail('UNIQUE deveria barrar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('despesas_idempotencia_unica', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('chk_despesas_idempotencia');
        $this->despesaPendente($categoria, $ator, ['chave_idempotencia' => 'sem-hash']);
    }
}
