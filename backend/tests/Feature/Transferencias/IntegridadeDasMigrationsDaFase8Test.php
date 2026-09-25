<?php

namespace Tests\Feature\Transferencias;

use App\Enums\PerfilSlug;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Reproduz no banco de testes os cenários críticos do DDL validado (migrations reais). */
class IntegridadeDasMigrationsDaFase8Test extends TestCase
{
    use RefreshDatabase, CenarioTransferencias;

    private ?array $base = null;

    private function base(): array
    {
        return $this->base ??= [
            'user' => $this->como(PerfilSlug::Pastor)->id,
            'a' => $this->conta('Integ A', 'banco')->id,
            'b' => $this->conta('Integ B', 'caixa')->id,
        ];
    }

    private function tr(array $extra = []): array
    {
        $b = $this->base();

        return array_merge([
            'conta_origem_id' => $b['a'], 'conta_destino_id' => $b['b'], 'valor' => '10.00', 'data_transferencia' => '2026-09-01',
            'criado_por' => $b['user'], 'created_at' => now(), 'updated_at' => now(),
        ], $extra);
    }

    private function estorno(int $original, array $extra = []): array
    {
        $b = $this->base();

        return $this->tr(array_merge(['conta_origem_id' => $b['b'], 'conta_destino_id' => $b['a'], 'transferencia_estornada_id' => $original, 'motivo_estorno' => 'erro'], $extra));
    }

    private function aj(array $extra = []): array
    {
        $b = $this->base();

        return array_merge([
            'conta_id' => $b['a'], 'valor' => '10.00', 'sentido' => 'credito', 'data_ajuste' => '2026-09-01',
            'justificativa' => 'Ajuste de conciliação', 'criado_por' => $b['user'], 'created_at' => now(), 'updated_at' => now(),
        ], $extra);
    }

    private function inserir(string $tabela, array $linha): int
    {
        return DB::table($tabela)->insertGetId($linha);
    }

    private function deveFalhar(string $tabela, array $linha, string $trecho): void
    {
        try {
            $this->inserir($tabela, $linha);
        } catch (QueryException $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());

            return;
        }

        $this->fail("Deveria falhar com '$trecho'.");
    }

    // ---------------- estrutura ----------------

    public function test_estrutura_de_transferencias(): void
    {
        $c = collect(DB::select('SHOW COLUMNS FROM transferencias'))->keyBy('Field');
        $esperado = [
            'id' => ['bigint(20) unsigned', 'NO'], 'conta_origem_id' => ['bigint(20) unsigned', 'NO'], 'conta_destino_id' => ['bigint(20) unsigned', 'NO'],
            'valor' => ['decimal(14,2)', 'NO'], 'data_transferencia' => ['date', 'NO'], 'descricao' => ['varchar(255)', 'YES'],
            'status' => ["enum('confirmada','estornada')", 'NO'], 'transferencia_estornada_id' => ['bigint(20) unsigned', 'YES'],
            'motivo_estorno' => ['varchar(500)', 'YES'], 'criado_por' => ['bigint(20) unsigned', 'NO'], 'chave_idempotencia' => ['varchar(64)', 'YES'],
            'hash_payload' => ['char(64)', 'YES'], 'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'],
        ];
        $this->assertEqualsCanonicalizing(array_keys($esperado), $c->keys()->all());
        foreach ($esperado as $campo => [$tipo, $nulo]) {
            $this->assertSame($tipo, $c[$campo]->Type, $campo);
            $this->assertSame($nulo, $c[$campo]->Null, $campo);
        }
        $this->assertSame('confirmada', $c['status']->Default);

        $idx = collect(DB::select('SHOW INDEX FROM transferencias'))->groupBy('Key_name');
        $this->assertSame('0', (string) $idx['transferencias_estorno_unico'][0]->Non_unique);
        $this->assertSame(['criado_por', 'chave_idempotencia'], $idx['transferencias_idempotencia_unica']->pluck('Column_name')->all());
        $this->assertSame(['conta_origem_id', 'data_transferencia'], $idx['transferencias_origem_data_idx']->pluck('Column_name')->all());
        $this->assertSame(['conta_destino_id', 'data_transferencia'], $idx['transferencias_destino_data_idx']->pluck('Column_name')->all());
        $this->assertSame(['data_transferencia'], $idx['transferencias_data_idx']->pluck('Column_name')->all());
    }

    public function test_estrutura_de_ajustes_saldo(): void
    {
        $c = collect(DB::select('SHOW COLUMNS FROM ajustes_saldo'))->keyBy('Field');
        $esperado = [
            'id' => ['bigint(20) unsigned', 'NO'], 'conta_id' => ['bigint(20) unsigned', 'NO'], 'valor' => ['decimal(14,2)', 'NO'],
            'sentido' => ["enum('credito','debito')", 'NO'], 'data_ajuste' => ['date', 'NO'], 'justificativa' => ['varchar(500)', 'NO'],
            'criado_por' => ['bigint(20) unsigned', 'NO'], 'chave_idempotencia' => ['varchar(64)', 'YES'], 'hash_payload' => ['char(64)', 'YES'],
            'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'],
        ];
        $this->assertEqualsCanonicalizing(array_keys($esperado), $c->keys()->all());
        foreach ($esperado as $campo => [$tipo, $nulo]) {
            $this->assertSame($tipo, $c[$campo]->Type, $campo);
            $this->assertSame($nulo, $c[$campo]->Null, $campo);
        }
        $idx = collect(DB::select('SHOW INDEX FROM ajustes_saldo'))->groupBy('Key_name');
        $this->assertSame(['criado_por', 'chave_idempotencia'], $idx['ajustes_idempotencia_unica']->pluck('Column_name')->all());
        $this->assertSame('0', (string) $idx['ajustes_idempotencia_unica'][0]->Non_unique);
        $this->assertSame(['conta_id', 'data_ajuste'], $idx['ajustes_conta_data_idx']->pluck('Column_name')->all());
        $this->assertSame(['data_ajuste'], $idx['ajustes_data_idx']->pluck('Column_name')->all());
    }

    public function test_todas_as_fks_sao_restrict_e_os_checks_existem(): void
    {
        $fks = collect(DB::select("SELECT TABLE_NAME t, CONSTRAINT_NAME n, DELETE_RULE d, UPDATE_RULE u FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('transferencias', 'ajustes_saldo')"));
        $this->assertCount(6, $fks); // 4 em transferencias (origem, destino, criado_por, self) + 2 em ajustes
        foreach ($fks as $fk) {
            $this->assertSame('RESTRICT', $fk->d, $fk->n);
            $this->assertSame('RESTRICT', $fk->u, $fk->n);
        }

        $checks = collect(DB::select("SELECT CONSTRAINT_NAME n FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('transferencias', 'ajustes_saldo')"))->pluck('n')->all();
        $this->assertEqualsCanonicalizing([
            'chk_transferencias_valor_positivo', 'chk_transferencias_contas_distintas', 'chk_transferencias_estorno_coerente', 'chk_transferencias_idempotencia',
            'chk_ajustes_valor_positivo', 'chk_ajustes_justificativa_minima', 'chk_ajustes_idempotencia',
        ], $checks);
    }

    // ---------------- transferencias: CHECKs, UNIQUEs, FKs ----------------

    public function test_checks_de_transferencias(): void
    {
        $this->inserir('transferencias', $this->tr());
        foreach (['0.00', '-0.01', '-10.00'] as $v) {
            $this->deveFalhar('transferencias', $this->tr(['valor' => $v]), 'chk_transferencias_valor_positivo');
        }
        $this->deveFalhar('transferencias', $this->tr(['valor' => '0.001']), 'chk_transferencias_valor_positivo');
        $this->deveFalhar('transferencias', $this->tr(['valor' => '1000000000000.00']), 'Out of range');
        $this->deveFalhar('transferencias', $this->tr(['conta_destino_id' => $this->base()['a']]), 'chk_transferencias_contas_distintas');

        $original = $this->inserir('transferencias', $this->tr());
        $this->inserir('transferencias', $this->estorno($original));
        $outra = $this->inserir('transferencias', $this->tr());
        $this->deveFalhar('transferencias', $this->estorno($outra, ['motivo_estorno' => null]), 'chk_transferencias_estorno_coerente');
        $this->deveFalhar('transferencias', $this->tr(['motivo_estorno' => 'x']), 'chk_transferencias_estorno_coerente');
        $this->deveFalhar('transferencias', $this->estorno($outra, ['status' => 'estornada']), 'chk_transferencias_estorno_coerente');
        $this->deveFalhar('transferencias', $this->tr(['status' => 'cancelada']), 'truncated');
        $this->deveFalhar('transferencias', $this->tr(['chave_idempotencia' => 'so-chave']), 'chk_transferencias_idempotencia');
        $this->deveFalhar('transferencias', $this->tr(['hash_payload' => str_repeat('a', 64)]), 'chk_transferencias_idempotencia');
    }

    public function test_uniques_de_transferencias_e_multiplos_nulls(): void
    {
        $original = $this->inserir('transferencias', $this->tr());
        $this->inserir('transferencias', $this->estorno($original));
        $this->deveFalhar('transferencias', $this->estorno($original, ['motivo_estorno' => 'de novo']), 'transferencias_estorno_unico');
        foreach (range(1, 3) as $_) {
            $this->inserir('transferencias', $this->tr());
        }
        $this->assertSame(4, DB::table('transferencias')->whereNull('transferencia_estornada_id')->count());

        $hash = str_repeat('a', 64);
        $outro = $this->como(PerfilSlug::Tesoureiro)->id;
        $this->inserir('transferencias', $this->tr(['chave_idempotencia' => 'k', 'hash_payload' => $hash]));
        $this->deveFalhar('transferencias', $this->tr(['chave_idempotencia' => 'k', 'hash_payload' => $hash]), 'transferencias_idempotencia_unica');
        $this->inserir('transferencias', $this->tr(['chave_idempotencia' => 'k', 'hash_payload' => $hash, 'criado_por' => $outro]));
        $this->deveFalhar('transferencias', $this->tr(['chave_idempotencia' => str_repeat('c', 65), 'hash_payload' => $hash]), 'too long');
    }

    public function test_fks_e_restrict_de_transferencias(): void
    {
        $b = $this->base();
        $this->deveFalhar('transferencias', $this->tr(['conta_origem_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar('transferencias', $this->tr(['conta_destino_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar('transferencias', $this->tr(['criado_por' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar('transferencias', $this->estorno(999999), 'foreign key constraint fails');

        $original = $this->inserir('transferencias', $this->tr());
        $this->inserir('transferencias', $this->estorno($original));

        foreach ([
            fn () => DB::table('contas')->where('id', $b['a'])->delete(),
            fn () => DB::table('contas')->where('id', $b['b'])->delete(),
            fn () => DB::table('users')->where('id', $b['user'])->delete(),
            fn () => DB::table('transferencias')->where('id', $original)->delete(),
            fn () => DB::table('contas')->where('id', $b['a'])->update(['id' => 999999]),
            fn () => DB::table('transferencias')->where('id', $original)->update(['id' => 888888]),
        ] as $acao) {
            try {
                $acao();
                $this->fail('RESTRICT deveria barrar');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
            }
        }
    }

    // ---------------- ajustes_saldo ----------------

    public function test_checks_uniques_e_fks_de_ajustes(): void
    {
        $this->inserir('ajustes_saldo', $this->aj());
        $this->inserir('ajustes_saldo', $this->aj(['sentido' => 'debito']));
        foreach (['0.00', '-0.01', '-100.00'] as $v) {
            $this->deveFalhar('ajustes_saldo', $this->aj(['valor' => $v]), 'chk_ajustes_valor_positivo');
        }
        $this->deveFalhar('ajustes_saldo', $this->aj(['sentido' => 'ajuste']), 'truncated');
        foreach (['', '  ', 'ab', ' a '] as $j) {
            $this->deveFalhar('ajustes_saldo', $this->aj(['justificativa' => $j]), 'chk_ajustes_justificativa_minima');
        }
        $this->deveFalhar('ajustes_saldo', $this->aj(['justificativa' => null]), 'cannot be null');
        $this->deveFalhar('ajustes_saldo', $this->aj(['justificativa' => str_repeat('x', 501)]), 'too long');
        $this->inserir('ajustes_saldo', $this->aj(['justificativa' => str_repeat('x', 500)]));
        $this->deveFalhar('ajustes_saldo', $this->aj(['conta_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar('ajustes_saldo', $this->aj(['criado_por' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar('ajustes_saldo', $this->aj(['chave_idempotencia' => 'so-chave']), 'chk_ajustes_idempotencia');
        $this->deveFalhar('ajustes_saldo', $this->aj(['hash_payload' => str_repeat('a', 64)]), 'chk_ajustes_idempotencia');

        $hash = str_repeat('a', 64);
        $outro = $this->como(PerfilSlug::Tesoureiro)->id;
        $this->inserir('ajustes_saldo', $this->aj(['chave_idempotencia' => 'k', 'hash_payload' => $hash]));
        $this->deveFalhar('ajustes_saldo', $this->aj(['chave_idempotencia' => 'k', 'hash_payload' => $hash]), 'ajustes_idempotencia_unica');
        $this->inserir('ajustes_saldo', $this->aj(['chave_idempotencia' => 'k', 'hash_payload' => $hash, 'criado_por' => $outro]));
        foreach (range(1, 3) as $_) {
            $this->inserir('ajustes_saldo', $this->aj());
        }

        $b = $this->base();
        foreach ([fn () => DB::table('contas')->where('id', $b['a'])->delete(), fn () => DB::table('users')->where('id', $b['user'])->delete(), fn () => DB::table('contas')->where('id', $b['a'])->update(['id' => 999999])] as $acao) {
            try {
                $acao();
                $this->fail('RESTRICT deveria barrar');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
            }
        }
        // O CHECK vale também em UPDATE: o valor de um ajuste não pode ser zerado.
        try {
            DB::table('ajustes_saldo')->limit(1)->update(['valor' => '0.00']);
            $this->fail('CHECK deveria barrar o UPDATE');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_ajustes_valor_positivo', $e->getMessage());
        }
    }

    public function test_decimal_exato_nas_novas_tabelas(): void
    {
        $this->inserir('transferencias', $this->tr(['valor' => '0.10']));
        $this->inserir('transferencias', $this->tr(['valor' => '0.20']));
        $this->inserir('ajustes_saldo', $this->aj(['valor' => '0.10']));
        $this->inserir('ajustes_saldo', $this->aj(['valor' => '0.20']));

        $this->assertSame('0.30', (string) DB::table('transferencias')->selectRaw('SUM(valor) s')->value('s'));
        $this->assertSame('0.30', (string) DB::table('ajustes_saldo')->selectRaw('SUM(valor) s')->value('s'));
    }
}
