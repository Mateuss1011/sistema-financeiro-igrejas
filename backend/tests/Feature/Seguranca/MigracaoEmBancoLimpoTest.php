<?php

namespace Tests\Feature\Seguranca;

use PDO;
use Tests\TestCase;

/**
 * Fase 13 — o schema sobe do zero num banco limpo. Cria um banco TEMPORÁRIO (nome próprio, nunca `sfg` nem `sfg_testing`),
 * roda `migrate --seed` num processo separado, confere tabelas, foreign keys, CHECKs, índices únicos e seeders, testa
 * `migrate:rollback` (o `down()` de todas as migrations) + nova subida, e derruba o banco no fim.
 */
class MigracaoEmBancoLimpoTest extends TestCase
{
    private const BANCO = 'sfg_fase13_migracao_tmp';

    private PDO $servidor;

    protected function setUp(): void
    {
        parent::setUp();
        $c = config('database.connections.mysql');
        $this->servidor = new PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertNotContains(self::BANCO, ['sfg', 'sfg_testing']);
        $this->servidor->exec('DROP DATABASE IF EXISTS `' . self::BANCO . '`');
        $this->servidor->exec('CREATE DATABASE `' . self::BANCO . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        $this->servidor->exec('DROP DATABASE IF EXISTS `' . self::BANCO . '`');
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} */
    private function rodarArtisan(array $argumentos): array
    {
        $ambiente = array_merge(getenv(), ['DB_DATABASE' => self::BANCO, 'DB_CONNECTION' => 'mysql', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
        $processo = proc_open([PHP_BINARY, 'artisan', ...$argumentos], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($processo), $saida];
    }

    private function consulta(string $sql): array
    {
        return $this->servidor->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function test_schema_sobe_do_zero_com_constraints_e_seeders_e_desce_e_sobe_de_novo(): void
    {
        [$codigo, $saida] = $this->rodarArtisan(['migrate', '--force', '--seed', '--no-interaction']);
        $this->assertSame(0, $codigo, "migrate --seed falhou:\n{$saida}");

        $banco = self::BANCO;
        $tabelas = $this->consulta("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$banco}' AND TABLE_TYPE='BASE TABLE'");
        foreach (['users', 'perfis', 'audit_logs', 'permissoes_excecao', 'categorias', 'contas', 'periodos_financeiros', 'entradas', 'despesas',
            'transferencias', 'ajustes_saldo', 'sessions', 'cache', 'jobs', 'migrations'] as $esperada) {
            $this->assertContains($esperada, $tabelas, "Tabela ausente após migrar: {$esperada}");
        }

        // Todas as migrations rodaram.
        $arquivos = count(glob(database_path('migrations/*.php')));
        $this->assertSame($arquivos, (int) $this->consulta("SELECT COUNT(*) FROM `{$banco}`.migrations")[0]);

        // Seeders: os 5 perfis fixos e as categorias padrão — e NENHUM usuário (credenciais nunca vêm do código).
        $perfis = $this->consulta("SELECT slug FROM `{$banco}`.perfis ORDER BY slug");
        $this->assertSame(['administrador', 'auxiliar_financeiro', 'pastor', 'secretario', 'tesoureiro'], $perfis);
        $this->assertGreaterThan(0, (int) $this->consulta("SELECT COUNT(*) FROM `{$banco}`.categorias")[0]);
        $this->assertSame(0, (int) $this->consulta("SELECT COUNT(*) FROM `{$banco}`.users")[0]);

        // Constraints de integridade presentes: FKs, CHECKs e índices únicos.
        $fks = (int) $this->consulta("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{$banco}' AND REFERENCED_TABLE_NAME IS NOT NULL")[0];
        $checks = (int) $this->consulta("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{$banco}'")[0];
        $unicos = (int) $this->consulta("SELECT COUNT(*) FROM (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{$banco}' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY TABLE_NAME, INDEX_NAME) t")[0];
        $this->assertGreaterThanOrEqual(22, $fks);
        $this->assertGreaterThanOrEqual(18, $checks);
        $this->assertGreaterThanOrEqual(13, $unicos);

        // O banco recém-criado usa InnoDB em todas as tabelas (transações e FKs dependem disso).
        $this->assertSame([], $this->consulta("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$banco}' AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'"));

        // Cada down() funciona: descer tudo esvazia o schema (só a tabela de controle sobra) e subir de novo é idempotente.
        [$codigo, $saida] = $this->rodarArtisan(['migrate:reset', '--force', '--no-interaction']);
        $this->assertSame(0, $codigo, "migrate:reset falhou:\n{$saida}");
        $this->assertSame(['migrations'], $this->consulta("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$banco}' AND TABLE_TYPE='BASE TABLE'"));

        [$codigo, $saida] = $this->rodarArtisan(['migrate', '--force', '--seed', '--no-interaction']);
        $this->assertSame(0, $codigo, "segunda subida falhou:\n{$saida}");
        $this->assertSame($fks, (int) $this->consulta("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='{$banco}' AND REFERENCED_TABLE_NAME IS NOT NULL")[0]);
    }

    public function test_migrar_de_novo_num_banco_ja_migrado_nao_altera_nada(): void
    {
        [$codigo] = $this->rodarArtisan(['migrate', '--force', '--no-interaction']);
        $this->assertSame(0, $codigo);

        [$codigo, $saida] = $this->rodarArtisan(['migrate', '--force', '--no-interaction']);
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Nothing to migrate', $saida);
    }
}
