<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Reproduz no banco de testes os cenários críticos do DDL descartável aprovado (migration real). */
class IntegridadeDaMigrationDeDespesasTest extends TestCase
{
    use RefreshDatabase, CenarioDespesas;

    private ?array $base = null;

    private const CO = 'chk_despesas_original_coerente';
    private const CE = 'chk_despesas_estorno_coerente';
    private const CI = 'chk_despesas_idempotencia';
    private const CV = 'chk_despesas_valor_positivo';

    private function base(): array
    {
        return $this->base ??= [
            'user' => $this->como(PerfilSlug::Pastor)->id,
            'categoria' => $this->categoriaDespesa('Cat Integridade')->id,
            'conta' => $this->conta('Conta Integridade')->id,
        ];
    }

    private function linha(array $extra = []): array
    {
        $b = $this->base();

        return array_merge([
            'categoria_id' => $b['categoria'], 'valor' => '10.00', 'data_competencia' => '2026-09-01', 'descricao' => 'd',
            'criado_por' => $b['user'], 'created_at' => now(), 'updated_at' => now(),
        ], $extra);
    }

    private function pendente(array $e = []): array
    {
        return $this->linha(array_merge(['status' => 'pendente'], $e));
    }

    private function paga(array $e = []): array
    {
        return $this->linha(array_merge([
            'status' => 'paga', 'conta_id' => $this->base()['conta'], 'data_pagamento' => '2026-09-02',
            'pago_por' => $this->base()['user'], 'pago_em' => now(),
        ], $e));
    }

    private function cancelada(array $e = []): array
    {
        return $this->linha(array_merge(['status' => 'cancelada', 'motivo_cancelamento' => 'motivo'], $e));
    }

    private function estorno(int $original, array $e = []): array
    {
        return $this->linha(array_merge([
            'status' => 'paga', 'despesa_estornada_id' => $original, 'conta_id' => $this->base()['conta'],
            'data_pagamento' => '2026-09-02', 'motivo_estorno' => 'erro', 'descricao' => null,
        ], $e));
    }

    private function inserir(array $linha): int
    {
        return DB::table('despesas')->insertGetId($linha);
    }

    private function deveFalhar(array $linha, string $trecho): void
    {
        try {
            $this->inserir($linha);
        } catch (QueryException $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());

            return;
        }

        $this->fail("Deveria falhar com '$trecho'.");
    }

    // ---------------- estrutura ----------------

    public function test_colunas_tipos_e_nulidade(): void
    {
        $c = collect(DB::select('SHOW COLUMNS FROM despesas'))->keyBy('Field');

        $esperado = [
            'id' => ['bigint(20) unsigned', 'NO'], 'categoria_id' => ['bigint(20) unsigned', 'NO'], 'conta_id' => ['bigint(20) unsigned', 'YES'],
            'valor' => ['decimal(14,2)', 'NO'], 'data_competencia' => ['date', 'NO'], 'data_pagamento' => ['date', 'YES'],
            'descricao' => ['varchar(255)', 'YES'], 'fornecedor_nome' => ['varchar(150)', 'YES'],
            'status' => ["enum('pendente','paga','estornada','cancelada')", 'NO'], 'despesa_estornada_id' => ['bigint(20) unsigned', 'YES'],
            'motivo_estorno' => ['varchar(500)', 'YES'], 'motivo_cancelamento' => ['varchar(500)', 'YES'], 'criado_por' => ['bigint(20) unsigned', 'NO'],
            'atualizado_por' => ['bigint(20) unsigned', 'YES'], 'pago_por' => ['bigint(20) unsigned', 'YES'], 'pago_em' => ['timestamp', 'YES'],
            'chave_idempotencia' => ['varchar(64)', 'YES'], 'hash_payload' => ['char(64)', 'YES'], 'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'],
        ];
        $this->assertEqualsCanonicalizing(array_keys($esperado), $c->keys()->all());
        foreach ($esperado as $campo => [$tipo, $nulo]) {
            $this->assertSame($tipo, $c[$campo]->Type, $campo);
            $this->assertSame($nulo, $c[$campo]->Null, $campo);
        }
        $this->assertSame('pendente', $c['status']->Default);
    }

    public function test_indices_uniques_fks_e_checks(): void
    {
        $idx = collect(DB::select('SHOW INDEX FROM despesas'))->groupBy('Key_name');
        $this->assertSame('0', (string) $idx['despesas_estorno_unico'][0]->Non_unique);
        $this->assertSame(['criado_por', 'chave_idempotencia'], $idx['despesas_idempotencia_unica']->pluck('Column_name')->all());
        $this->assertSame('0', (string) $idx['despesas_idempotencia_unica'][0]->Non_unique);
        $this->assertSame(['conta_id', 'data_pagamento'], $idx['despesas_conta_pagamento_idx']->pluck('Column_name')->all());
        $this->assertSame(['data_competencia'], $idx['despesas_competencia_idx']->pluck('Column_name')->all());
        $this->assertSame(['categoria_id'], $idx['despesas_categoria_idx']->pluck('Column_name')->all());
        $this->assertSame(['status', 'data_competencia'], $idx['despesas_status_competencia_idx']->pluck('Column_name')->all());

        $fks = collect(DB::select("SELECT CONSTRAINT_NAME n, DELETE_RULE d, UPDATE_RULE u FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'despesas'"));
        $this->assertCount(6, $fks);
        foreach ($fks as $fk) {
            $this->assertSame('RESTRICT', $fk->d, $fk->n);
            $this->assertSame('RESTRICT', $fk->u, $fk->n);
        }

        $checks = collect(DB::select("SELECT CONSTRAINT_NAME n FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'despesas'"))->pluck('n')->all();
        $this->assertEqualsCanonicalizing([self::CO, self::CE, self::CI, self::CV], $checks);
    }

    public function test_migration_nao_criou_colunas_nao_aprovadas(): void
    {
        $campos = collect(DB::select('SHOW COLUMNS FROM despesas'))->pluck('Field')->all();
        foreach (['deleted_at', 'saldo_atual', 'data_vencimento', 'cancelado_por', 'cancelado_em', 'periodo_id'] as $proibido) {
            $this->assertNotContains($proibido, $campos);
        }
    }

    // ---------------- CHECKs ----------------

    public function test_check_valor_positivo(): void
    {
        foreach (['0.00', '-0.01', '-10.00'] as $v) {
            $this->deveFalhar($this->pendente(['valor' => $v]), self::CV);
        }
        $this->inserir($this->pendente(['valor' => '0.01']));
        $this->inserir($this->pendente(['valor' => '999999999999.99']));
        $this->deveFalhar($this->pendente(['valor' => '1000000000000.00']), 'Out of range');
        $this->deveFalhar($this->pendente(['valor' => '0.001']), self::CV); // o banco arredonda; o CHECK barra o zero resultante
    }

    public function test_check_original_pendente(): void
    {
        $this->inserir($this->pendente());
        foreach (['conta_id' => 1, 'data_pagamento' => '2026-09-02', 'pago_por' => 1, 'pago_em' => '2026-09-02 10:00:00', 'motivo_cancelamento' => 'x', 'motivo_estorno' => 'x'] as $campo => $valor) {
            $this->deveFalhar($this->pendente([$campo => $campo === 'conta_id' ? $this->base()['conta'] : ($campo === 'pago_por' ? $this->base()['user'] : $valor)]), self::CO);
        }
    }

    public function test_check_original_paga_e_estornada(): void
    {
        $this->inserir($this->paga());
        $this->inserir($this->paga(['status' => 'estornada']));

        foreach (['conta_id', 'data_pagamento', 'pago_por', 'pago_em'] as $campo) {
            $this->deveFalhar($this->paga([$campo => null]), self::CO);
            $this->deveFalhar($this->paga(['status' => 'estornada', $campo => null]), self::CO);
        }
        $this->deveFalhar($this->paga(['motivo_cancelamento' => 'x']), self::CO);
        $this->deveFalhar($this->paga(['motivo_estorno' => 'x']), self::CO);
        $this->deveFalhar($this->paga(['status' => 'estornada', 'motivo_estorno' => 'x']), self::CO);
        $this->deveFalhar($this->linha(['status' => 'estornada']), self::CO); // "nua"
    }

    public function test_check_original_cancelada(): void
    {
        $this->inserir($this->cancelada());
        $this->deveFalhar($this->cancelada(['motivo_cancelamento' => null]), self::CO);
        $this->deveFalhar($this->cancelada(['conta_id' => $this->base()['conta']]), self::CO);
        $this->deveFalhar($this->cancelada(['data_pagamento' => '2026-09-02']), self::CO);
        $this->deveFalhar($this->cancelada(['pago_por' => $this->base()['user']]), self::CO);
        $this->deveFalhar($this->cancelada(['pago_em' => '2026-09-02 10:00:00']), self::CO);
        $this->deveFalhar($this->cancelada(['motivo_estorno' => 'x']), self::CO);
    }

    public function test_check_linha_de_estorno(): void
    {
        $original = $this->inserir($this->paga());
        $outra = $this->inserir($this->paga());

        $this->inserir($this->estorno($original));
        foreach (['pendente', 'estornada', 'cancelada'] as $status) {
            $this->deveFalhar($this->estorno($outra, ['status' => $status]), self::CE);
        }
        $this->deveFalhar($this->estorno($outra, ['conta_id' => null]), self::CE);
        $this->deveFalhar($this->estorno($outra, ['data_pagamento' => null]), self::CE);
        $this->deveFalhar($this->estorno($outra, ['motivo_estorno' => null]), self::CE);
        $this->deveFalhar($this->estorno($outra, ['motivo_cancelamento' => 'x']), self::CE);
        $this->deveFalhar($this->estorno($outra, ['pago_por' => $this->base()['user']]), self::CE);
        $this->deveFalhar($this->estorno($outra, ['pago_em' => '2026-09-02 10:00:00']), self::CE);
    }

    public function test_check_de_idempotencia_chave_e_hash(): void
    {
        $hash = str_repeat('a', 64);
        $this->inserir($this->pendente(['chave_idempotencia' => 'k', 'hash_payload' => $hash]));
        $this->inserir($this->pendente());
        $this->deveFalhar($this->pendente(['chave_idempotencia' => 'sem-hash']), self::CI);
        $this->deveFalhar($this->pendente(['hash_payload' => $hash]), self::CI);
    }

    // ---------------- FKs e UNIQUEs ----------------

    public function test_fks_e_restrict(): void
    {
        $b = $this->base();
        $this->deveFalhar($this->pendente(['categoria_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar($this->paga(['conta_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar($this->pendente(['criado_por' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar($this->pendente(['atualizado_por' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar($this->paga(['pago_por' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar($this->estorno(999999), 'foreign key constraint fails');

        $original = $this->inserir($this->paga());
        $this->inserir($this->estorno($original));

        foreach ([
            fn () => DB::table('categorias')->where('id', $b['categoria'])->delete(),
            fn () => DB::table('contas')->where('id', $b['conta'])->delete(),
            fn () => DB::table('users')->where('id', $b['user'])->delete(),
            fn () => DB::table('despesas')->where('id', $original)->delete(),
            fn () => DB::table('contas')->where('id', $b['conta'])->update(['id' => 999999]),
            fn () => DB::table('despesas')->where('id', $original)->update(['id' => 888888]),
        ] as $acao) {
            try {
                $acao();
                $this->fail('RESTRICT deveria barrar');
            } catch (QueryException $e) {
                $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
            }
        }
        // Exclusão física de uma Pendente sem filhos é permitida (regra de negócio do plano).
        $id = $this->inserir($this->pendente());
        $this->assertSame(1, DB::table('despesas')->where('id', $id)->delete());
    }

    public function test_unique_do_estorno_e_multiplos_nulls(): void
    {
        $original = $this->inserir($this->paga());
        $this->inserir($this->estorno($original));
        $this->deveFalhar($this->estorno($original, ['motivo_estorno' => 'de novo']), 'despesas_estorno_unico');

        foreach ([$this->pendente(), $this->pendente(), $this->paga(), $this->cancelada()] as $linha) {
            $this->inserir($linha);
        }
        // original + 4 linhas sem vínculo: vários NULL em despesa_estornada_id convivem com o UNIQUE.
        $this->assertSame(5, DB::table('despesas')->whereNull('despesa_estornada_id')->count());
    }

    public function test_unique_da_idempotencia_por_usuario_e_nulls(): void
    {
        $hash = str_repeat('a', 64);
        $outro = $this->como(PerfilSlug::Tesoureiro)->id;

        $this->inserir($this->pendente(['chave_idempotencia' => 'k1', 'hash_payload' => $hash]));
        $this->deveFalhar($this->pendente(['chave_idempotencia' => 'k1', 'hash_payload' => $hash]), 'despesas_idempotencia_unica');
        $this->inserir($this->pendente(['chave_idempotencia' => 'k1', 'hash_payload' => $hash, 'criado_por' => $outro]));
        $this->inserir($this->pendente());
        $this->inserir($this->pendente());
        $this->inserir($this->pendente(['criado_por' => $outro]));
        $this->assertSame(5, DB::table('despesas')->count());
    }

    public function test_decimal_exato_status_enum_e_updates_de_transicao(): void
    {
        $this->inserir($this->pendente(['valor' => '0.10']));
        $this->inserir($this->pendente(['valor' => '0.20']));
        $this->assertSame('0.30', (string) DB::table('despesas')->selectRaw('SUM(valor) s')->value('s'));
        $this->deveFalhar($this->pendente(['status' => 'confirmada']), 'truncated');

        $id = $this->inserir($this->pendente());
        // pendente -> paga exige os campos de pagamento (CHECK também em UPDATE)
        try {
            DB::table('despesas')->where('id', $id)->update(['status' => 'paga']);
            $this->fail('CHECK deveria barrar o UPDATE');
        } catch (QueryException $e) {
            $this->assertStringContainsString(self::CO, $e->getMessage());
        }
        $b = $this->base();
        DB::table('despesas')->where('id', $id)->update(['status' => 'paga', 'conta_id' => $b['conta'], 'data_pagamento' => '2026-09-02', 'pago_por' => $b['user'], 'pago_em' => now()]);
        $this->assertSame('paga', DB::table('despesas')->where('id', $id)->value('status'));
    }
}
