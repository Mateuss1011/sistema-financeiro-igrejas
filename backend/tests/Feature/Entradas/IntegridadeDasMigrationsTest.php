<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Integridade das migrations reais (entradas e periodos_financeiros) no MariaDB. */
class IntegridadeDasMigrationsTest extends TestCase
{
    use RefreshDatabase, CenarioEntradas;

    /** Base (usuário, conta, categoria) criada sob demanda, uma vez por teste. */
    private ?array $base = null;

    private function linha(array $extra = []): array
    {
        $base = $this->base ??= [
            'user' => $this->como(PerfilSlug::Pastor)->id,
            'conta' => $this->conta('Integridade')->id,
            'categoria' => $this->categoria('Cat Integridade')->id,
        ];

        return array_merge([
            'categoria_id' => $base['categoria'], 'conta_id' => $base['conta'], 'valor' => '10.00',
            'data_competencia' => '2026-09-01', 'criado_por' => $base['user'],
            'created_at' => now(), 'updated_at' => now(),
        ], $extra);
    }

    private function inserir(array $extra = []): int
    {
        return DB::table('entradas')->insertGetId($this->linha($extra));
    }

    private function deveFalhar(callable $acao, string $trecho): void
    {
        try {
            $acao();
        } catch (QueryException $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());

            return;
        }

        $this->fail("Deveria falhar com '$trecho'.");
    }

    // ---------- estrutura ----------

    public function test_colunas_tipos_e_nulidade_da_tabela_entradas(): void
    {
        $c = collect(DB::select('SHOW COLUMNS FROM entradas'))->keyBy('Field');

        $this->assertSame('decimal(14,2)', $c['valor']->Type);
        $this->assertSame('date', $c['data_competencia']->Type);
        $this->assertSame("enum('confirmada','estornada')", $c['status']->Type);
        $this->assertSame('confirmada', $c['status']->Default);
        $this->assertSame('varchar(255)', $c['descricao']->Type);
        $this->assertSame('varchar(150)', $c['contribuinte_nome']->Type);
        $this->assertSame('varchar(500)', $c['motivo_estorno']->Type);
        $this->assertSame('varchar(64)', $c['chave_idempotencia']->Type);
        foreach (['categoria_id', 'conta_id', 'valor', 'data_competencia', 'status', 'criado_por'] as $obrigatoria) {
            $this->assertSame('NO', $c[$obrigatoria]->Null, $obrigatoria);
        }
        foreach (['descricao', 'contribuinte_nome', 'entrada_estornada_id', 'motivo_estorno', 'chave_idempotencia'] as $opcional) {
            $this->assertSame('YES', $c[$opcional]->Null, $opcional);
        }
        $this->assertNotContains('deleted_at', $c->keys()->all());
        $this->assertNotContains('saldo_atual', $c->keys()->all());
        $this->assertNotContains('periodo_id', $c->keys()->all());
    }

    public function test_indices_e_uniques(): void
    {
        $idx = collect(DB::select('SHOW INDEX FROM entradas'))->groupBy('Key_name');

        $this->assertSame('0', (string) $idx['entradas_estorno_unico'][0]->Non_unique);
        $this->assertSame(['entrada_estornada_id'], $idx['entradas_estorno_unico']->pluck('Column_name')->all());
        $this->assertSame('0', (string) $idx['entradas_idempotencia_unica'][0]->Non_unique);
        $this->assertSame(['criado_por', 'chave_idempotencia'], $idx['entradas_idempotencia_unica']->pluck('Column_name')->all());
        $this->assertSame(['conta_id', 'data_competencia'], $idx['entradas_conta_data_idx']->pluck('Column_name')->all());
        $this->assertSame(['data_competencia'], $idx['entradas_data_idx']->pluck('Column_name')->all());
        $this->assertSame(['categoria_id'], $idx['entradas_categoria_idx']->pluck('Column_name')->all());
    }

    public function test_todas_as_fks_sao_restrict_para_delete_e_update(): void
    {
        $fks = collect(DB::select(
            "SELECT TABLE_NAME t, CONSTRAINT_NAME n, DELETE_RULE d, UPDATE_RULE u FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('entradas', 'periodos_financeiros')"
        ));

        $this->assertCount(6, $fks);
        foreach ($fks as $fk) {
            $this->assertSame('RESTRICT', $fk->d, $fk->n);
            $this->assertSame('RESTRICT', $fk->u, $fk->n);
        }
        $this->assertEqualsCanonicalizing(
            ['entradas_categoria_id_foreign', 'entradas_conta_id_foreign', 'entradas_criado_por_foreign', 'entradas_entrada_estornada_id_foreign',
                'periodos_financeiros_fechado_por_foreign', 'periodos_financeiros_reaberto_por_foreign'],
            $fks->pluck('n')->all()
        );
    }

    // ---------- CHECKs, FKs, UNIQUEs de entradas ----------

    public function test_check_valor_positivo(): void
    {
        foreach (['0.00', '-0.01', '-10.00'] as $valor) {
            $this->deveFalhar(fn () => $this->inserir(['valor' => $valor]), 'chk_entradas_valor_positivo');
        }

        $this->inserir(['valor' => '0.01']);
        $this->inserir(['valor' => '999999999999.99']);
        $this->assertSame(2, DB::table('entradas')->count());
        $this->deveFalhar(fn () => $this->inserir(['valor' => '1000000000000.00']), 'Out of range');
    }

    public function test_decimal_preserva_centavos_exatos(): void
    {
        $this->inserir(['valor' => '0.10']);
        $this->inserir(['valor' => '0.20']);

        $this->assertSame('0.30', (string) DB::table('entradas')->selectRaw('SUM(valor) s')->value('s'));
    }

    public function test_check_de_coerencia_do_estorno(): void
    {
        $original = $this->inserir();

        $this->deveFalhar(fn () => $this->inserir(['entrada_estornada_id' => $original]), 'chk_entradas_estorno_coerente'); // sem motivo
        $this->deveFalhar(fn () => $this->inserir(['motivo_estorno' => 'x']), 'chk_entradas_estorno_coerente'); // motivo sem vínculo
        $this->deveFalhar(fn () => $this->inserir(['entrada_estornada_id' => $original, 'motivo_estorno' => 'x', 'status' => 'estornada']), 'chk_entradas_estorno_coerente');

        $this->inserir(['entrada_estornada_id' => $original, 'motivo_estorno' => 'ok']);
        $this->assertSame(2, DB::table('entradas')->count());
    }

    public function test_unique_do_estorno_barra_segundo_estorno_e_aceita_varios_nulls(): void
    {
        $original = $this->inserir();
        $this->inserir(['entrada_estornada_id' => $original, 'motivo_estorno' => 'ok']);

        $this->deveFalhar(fn () => $this->inserir(['entrada_estornada_id' => $original, 'motivo_estorno' => 'de novo']), 'entradas_estorno_unico');

        $this->inserir();
        $this->inserir();
        $this->assertSame(4, DB::table('entradas')->count());
    }

    public function test_fk_self_exige_original_existente_e_restringe_delete(): void
    {
        $this->deveFalhar(fn () => $this->inserir(['entrada_estornada_id' => 999999, 'motivo_estorno' => 'x']), 'foreign key constraint fails');

        $original = $this->inserir();
        $this->inserir(['entrada_estornada_id' => $original, 'motivo_estorno' => 'ok']);
        $this->deveFalhar(fn () => DB::table('entradas')->where('id', $original)->delete(), 'foreign key constraint fails');
    }

    public function test_unique_da_idempotencia_por_usuario_e_nulls(): void
    {
        $this->inserir(['chave_idempotencia' => 'k1']);
        $this->deveFalhar(fn () => $this->inserir(['chave_idempotencia' => 'k1']), 'entradas_idempotencia_unica');

        $outro = $this->como(PerfilSlug::Tesoureiro)->id;
        $this->inserir(['chave_idempotencia' => 'k1', 'criado_por' => $outro]);

        $this->inserir();
        $this->inserir();
        $this->inserir(['criado_por' => $outro]);
        $this->assertSame(5, DB::table('entradas')->count());
    }

    public function test_restrict_impede_delete_fisico_de_conta_categoria_e_usuario_com_entradas(): void
    {
        $linha = $this->linha();
        DB::table('entradas')->insert($linha);

        $this->deveFalhar(fn () => DB::table('contas')->where('id', $linha['conta_id'])->delete(), 'foreign key constraint fails');
        $this->deveFalhar(fn () => DB::table('categorias')->where('id', $linha['categoria_id'])->delete(), 'foreign key constraint fails');
        $this->deveFalhar(fn () => DB::table('users')->where('id', $linha['criado_por'])->delete(), 'foreign key constraint fails');
        $this->deveFalhar(fn () => DB::table('contas')->where('id', $linha['conta_id'])->update(['id' => 999999]), 'foreign key constraint fails');
    }

    public function test_fks_rejeitam_referencias_inexistentes_e_status_invalido(): void
    {
        $this->deveFalhar(fn () => $this->inserir(['categoria_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar(fn () => $this->inserir(['conta_id' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar(fn () => $this->inserir(['criado_por' => 999999]), 'foreign key constraint fails');
        $this->deveFalhar(fn () => $this->inserir(['status' => 'cancelada']), 'Data truncated');
    }

    // ---------- periodos_financeiros ----------

    private function periodo(array $extra = []): int
    {
        return DB::table('periodos_financeiros')->insertGetId(array_merge([
            'ano_mes' => '2026-01', 'status' => 'fechado', 'fechado_por' => $this->linha()['criado_por'], 'fechado_em' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    public function test_periodos_colunas_unique_e_regexp_de_ano_mes(): void
    {
        $c = collect(DB::select('SHOW COLUMNS FROM periodos_financeiros'))->keyBy('Field');
        $this->assertSame('char(7)', $c['ano_mes']->Type);
        $this->assertSame("enum('aberto','fechado')", $c['status']->Type);
        $this->assertSame('aberto', $c['status']->Default);
        $this->assertSame('varchar(500)', $c['justificativa_reabertura']->Type);

        $this->periodo();
        $this->deveFalhar(fn () => $this->periodo(), 'periodos_financeiros_ano_mes_unique');

        foreach (['2026-13', '2026-00', '26-01', '2026/01', 'abcd-ef', '2026-1'] as $invalido) {
            $this->deveFalhar(fn () => $this->periodo(['ano_mes' => $invalido]), 'chk_periodos_ano_mes');
        }

        foreach (['2026-02', '2026-12', '1999-09'] as $valido) {
            $this->periodo(['ano_mes' => $valido]);
        }
        $this->assertSame(4, DB::table('periodos_financeiros')->count());
    }

    public function test_periodo_nunca_pode_estar_fechado_e_reaberto_ao_mesmo_tempo(): void
    {
        $user = $this->linha()['criado_por'];
        $reabertura = ['reaberto_por' => $user, 'reaberto_em' => now(), 'justificativa_reabertura' => 'Correção'];

        // Fechado com qualquer campo de reabertura, ou sem dados de fechamento: inválido.
        foreach ($reabertura as $campo => $valor) {
            $this->deveFalhar(fn () => $this->periodo([$campo => $valor]), 'chk_periodos_ciclo');
        }
        $this->deveFalhar(fn () => $this->periodo($reabertura), 'chk_periodos_ciclo');
        $this->deveFalhar(fn () => $this->periodo(['fechado_por' => null]), 'chk_periodos_ciclo');
        $this->deveFalhar(fn () => $this->periodo(['fechado_em' => null]), 'chk_periodos_ciclo');

        // Aberto sem histórico de fechamento: não suportado nesta fase.
        $this->deveFalhar(fn () => $this->periodo(['status' => 'aberto', 'fechado_por' => null, 'fechado_em' => null]), 'chk_periodos_ciclo');
        // Aberto após reabertura: exige fechamento E reabertura completos.
        $this->deveFalhar(fn () => $this->periodo(['status' => 'aberto']), 'chk_periodos_ciclo');
        foreach (['reaberto_por', 'reaberto_em', 'justificativa_reabertura'] as $faltando) {
            $this->deveFalhar(fn () => $this->periodo(['status' => 'aberto', 'ano_mes' => '2025-05'] + array_merge($reabertura, [$faltando => null])), 'chk_periodos_ciclo');
        }

        $this->periodo(['ano_mes' => '2025-01']); // fechado válido
        $this->periodo(['ano_mes' => '2025-02', 'status' => 'aberto'] + $reabertura); // reaberto válido
        $this->assertSame(2, DB::table('periodos_financeiros')->count());
    }

    public function test_periodos_fks_de_usuario_sao_restrict(): void
    {
        $this->deveFalhar(fn () => $this->periodo(['fechado_por' => 999999]), 'foreign key constraint fails');

        $user = $this->linha()['criado_por'];
        $this->periodo();
        $this->deveFalhar(fn () => DB::table('users')->where('id', $user)->delete(), 'foreign key constraint fails');
    }
}
