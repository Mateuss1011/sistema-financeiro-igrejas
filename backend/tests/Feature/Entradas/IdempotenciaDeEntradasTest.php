<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Entrada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotenciaDeEntradasTest extends TestCase
{
    use RefreshDatabase, CenarioEntradas;

    private function enviar($ator, array $corpo, ?string $chave)
    {
        return $this->actingAs($ator)->postJson('/api/v1/entradas', $corpo, $chave === null ? [] : ['Idempotency-Key' => $chave]);
    }

    public function test_mesma_chave_e_mesmo_payload_devolve_a_original_sem_duplicar(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payload($this->conta(), $this->categoria(), ['descricao' => 'Culto']);

        $primeira = $this->enviar($ator, $corpo, 'chave-1')->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $segunda = $this->enviar($ator, $corpo, 'chave-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $terceira = $this->enviar($ator, $corpo, 'chave-1')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($primeira->json('data.id'), $segunda->json('data.id'));
        $this->assertSame($primeira->json('data'), $segunda->json('data'));
        $this->assertSame($primeira->json('data.id'), $terceira->json('data.id'));
        $this->assertDatabaseCount('entradas', 1);
        $this->assertSame(1, AuditLog::where('modulo', 'entradas')->count());
        $this->assertSame('chave-1', Entrada::sole()->chave_idempotencia);
    }

    public function test_mesma_chave_com_payload_diferente_retorna_409(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta('Conta A');
        $categoria = $this->categoria('Cat A');
        $base = $this->payload($conta, $categoria, ['descricao' => 'Culto', 'contribuinte_nome' => 'Maria', 'data_competencia' => '2026-05-01']);
        $this->enviar($ator, $base, 'k')->assertStatus(201);

        $variacoes = [
            ['valor' => '100.01'],
            ['conta_id' => $this->conta('Conta B')->id],
            ['categoria_id' => $this->categoria('Cat B')->id],
            ['data_competencia' => '2026-05-02'],
            ['descricao' => 'Outro'],
            ['descricao' => null],
            ['contribuinte_nome' => 'Joana'],
            ['contribuinte_nome' => null],
        ];

        foreach ($variacoes as $variacao) {
            $this->enviar($ator, array_merge($base, $variacao), 'k')
                ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUTILIZADA');
        }

        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_replay_compara_valores_normalizados(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();

        $this->enviar($ator, $this->payload($conta, $categoria, ['valor' => '100.5', 'descricao' => '  Culto  ', 'contribuinte_nome' => '']), 'norm')->assertStatus(201);

        foreach ([
            ['valor' => '100.50', 'descricao' => 'Culto', 'contribuinte_nome' => null],
            ['valor' => 100.5, 'descricao' => 'Culto'],
            ['valor' => '100.500'] , // 3 casas é inválido (422), nunca chega ao replay
        ] as $i => $variacao) {
            $resposta = $this->enviar($ator, $this->payload($conta, $categoria, $variacao), 'norm');
            $i < 2 ? $resposta->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true') : $resposta->assertStatus(422);
        }

        $this->assertDatabaseCount('entradas', 1);
    }

    public function test_chaves_sao_independentes_por_usuario_e_por_valor(): void
    {
        $a = $this->como(PerfilSlug::Tesoureiro);
        $b = $this->como(PerfilSlug::Pastor);
        $corpo = $this->payload($this->conta(), $this->categoria());

        $this->enviar($a, $corpo, 'mesma')->assertStatus(201);
        $this->enviar($b, $corpo, 'mesma')->assertStatus(201);
        $this->enviar($a, $corpo, 'outra')->assertStatus(201);

        $this->assertDatabaseCount('entradas', 3);
    }

    public function test_sem_chave_cada_requisicao_cria_uma_entrada(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payload($this->conta(), $this->categoria());

        $this->enviar($ator, $corpo, null)->assertStatus(201);
        $this->enviar($ator, $corpo, null)->assertStatus(201);

        $this->assertDatabaseCount('entradas', 2);
        $this->assertSame(2, Entrada::whereNull('chave_idempotencia')->count());
    }

    public function test_chave_invalida_retorna_422(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payload($this->conta(), $this->categoria());

        foreach ([str_repeat('a', 65), 'com espaço', 'acentuação'] as $chave) {
            $this->enviar($ator, $corpo, $chave)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        }

        $this->enviar($ator, $corpo, str_repeat('a', 64))->assertStatus(201);
        $this->enviar($ator, $corpo, '550e8400-e29b-41d4-a716-446655440000')->assertStatus(201);
    }

    public function test_o_header_e_a_unica_fonte_da_chave(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $corpo = $this->payload($this->conta(), $this->categoria());

        $this->enviar($ator, $corpo + ['idempotency_key' => 'no-corpo', 'chave_idempotencia' => 'no-corpo'], null)->assertStatus(201);
        $this->assertNull(Entrada::sole()->chave_idempotencia);
    }

    public function test_replay_devolve_a_original_mesmo_se_o_estado_mudou_depois(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta();
        $corpo = $this->payload($conta, $this->categoria());

        $id = $this->enviar($pastor, $corpo, 'estado')->assertStatus(201)->json('data.id');

        $this->actingAs($pastor)->postJson("/api/v1/entradas/{$id}/estornar", ['justificativa' => 'teste'])->assertStatus(201);
        $conta->update(['ativa' => false]);

        $replay = $this->enviar($pastor, $corpo, 'estado')->assertStatus(200)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($id, $replay->json('data.id'));
        $this->assertSame('estornada', $replay->json('data.status'));
        $this->assertDatabaseCount('entradas', 2);
    }

    public function test_unique_do_banco_protege_a_chave_mesmo_fora_da_aplicacao(): void
    {
        $ator = $this->como(PerfilSlug::Tesoureiro);
        $conta = $this->conta();
        $categoria = $this->categoria();
        $this->entrada($conta, $categoria, $ator, '10.00', null, ['chave_idempotencia' => 'db']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('entradas_idempotencia_unica');
        $this->entrada($conta, $categoria, $ator, '10.00', null, ['chave_idempotencia' => 'db']);
    }
}
