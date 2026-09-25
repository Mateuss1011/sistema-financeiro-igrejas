<?php

namespace Tests\Feature\Deploy;

use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Fase 14 — prova, num banco TEMPORÁRIO, que o script `docs/deploy/mariadb-privilegios.sql` faz o que promete: o
 * usuário da aplicação opera normalmente (inclusive pelo Laravel), mas NÃO consegue alterar nem excluir `audit_logs`
 * (nem apagar/alterar a estrutura do banco). Nunca toca em `sfg` nem em `sfg_testing`.
 */
class PrivilegiosDoUsuarioDaAplicacaoTest extends TestCase
{
    private const BANCO = 'sfg_fase14_privilegios_tmp';
    private const HOST = '127.0.0.1';
    private const APP = 'sfg_t14_app';
    private const MIGRADOR = 'sfg_t14_migrador';
    private const SENHA_APP = 'Tmp-App-Senha-14';
    private const SENHA_MIGRADOR = 'Tmp-Migrador-Senha-14';

    private PDO $root;

    protected function setUp(): void
    {
        parent::setUp();
        $c = config('database.connections.mysql');
        $this->root = new PDO("mysql:host={$c['host']};port={$c['port']}", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->limpar();
        $this->root->exec('CREATE DATABASE `' . self::BANCO . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        $this->limpar();
        parent::tearDown();
    }

    private function limpar(): void
    {
        $this->root->exec('DROP DATABASE IF EXISTS `' . self::BANCO . '`');
        foreach ([self::APP, self::MIGRADOR] as $usuario) {
            $this->root->exec("DROP USER IF EXISTS '{$usuario}'@'" . self::HOST . "'");
        }
    }

    /** @return array{0: int, 1: string} */
    private function rodarArtisan(array $argumentos, array $ambiente = []): array
    {
        $env = array_merge(getenv(), ['DB_DATABASE' => self::BANCO, 'DB_CONNECTION' => 'mysql', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'], $ambiente);
        $processo = proc_open([PHP_BINARY, 'artisan', ...$argumentos], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($processo), $saida];
    }

    private function scriptPreenchido(): string
    {
        $sql = file_get_contents(base_path('../docs/deploy/mariadb-privilegios.sql'));

        return strtr($sql, [
            '__SFG_DB__' => self::BANCO, '__SFG_HOST__' => self::HOST,
            '__SFG_APP_USER__' => self::APP, '__SFG_APP_PASSWORD__' => self::SENHA_APP,
            '__SFG_MIGRATOR_USER__' => self::MIGRADOR, '__SFG_MIGRATOR_PASSWORD__' => self::SENHA_MIGRADOR,
        ]);
    }

    /** Executa o script como administrador, comando a comando (ignora comentários). */
    private function executarScript(): int
    {
        $limpo = implode("\n", array_filter(explode("\n", str_replace("\r\n", "\n", $this->scriptPreenchido())), fn ($l) => ! str_starts_with(trim($l), '--')));
        $executados = 0;
        foreach (array_filter(array_map('trim', explode(";\n", $limpo))) as $comando) {
            $this->root->exec($comando);
            $executados++;
        }

        return $executados;
    }

    private function comoUsuario(string $usuario, string $senha): PDO
    {
        $c = config('database.connections.mysql');

        return new PDO("mysql:host={$c['host']};port={$c['port']};dbname=" . self::BANCO, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function codigoDoErro(callable $acao): ?int
    {
        try {
            $acao();
        } catch (PDOException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }

        return null;
    }

    public function test_o_script_cobre_todas_as_tabelas_do_schema_e_o_usuario_da_aplicacao_nao_altera_nem_apaga_a_auditoria(): void
    {
        // 1) instalação nova: usuários (parte 1) → migrations com o migrador → privilégios da aplicação (parte 2).
        //    O script é aplicado inteiro depois das migrations (o CREATE USER IF NOT EXISTS é idempotente).
        [$codigo, $saida] = $this->rodarArtisan(['migrate', '--force', '--seed', '--no-interaction']);
        $this->assertSame(0, $codigo, $saida);
        $this->assertGreaterThan(20, $this->executarScript());

        // 2) o script cita TODAS as tabelas (menos `migrations`): tabela nova sem GRANT fica sem acesso e este teste avisa.
        $tabelas = $this->root->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . self::BANCO . "' AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        preg_match_all('/`__SFG_DB__`\.`([a-z_]+)`\s+TO\s+\'__SFG_APP_USER__\'/', file_get_contents(base_path('../docs/deploy/mariadb-privilegios.sql')), $m);
        $concedidas = array_unique($m[1]);
        $this->assertSame([], array_values(array_diff($tabelas, $concedidas, ['migrations'])), 'Tabela sem GRANT no script de privilégios');
        $this->assertSame([], array_values(array_diff($concedidas, $tabelas)), 'O script concede tabela que não existe no schema');

        // 3) o que o usuário da aplicação PODE e NÃO PODE, direto no banco.
        $app = $this->comoUsuario(self::APP, self::SENHA_APP);
        $perfil = (int) $app->query("SELECT id FROM perfis WHERE slug='pastor'")->fetchColumn();
        $app->exec("INSERT INTO users (name,email,password,perfil_id,ativo,created_at,updated_at) VALUES ('T','t14@x.com','x',{$perfil},1,NOW(),NOW())");
        $app->exec("INSERT INTO audit_logs (acao,modulo,created_at) VALUES ('created','usuarios',NOW())");
        $this->assertSame(1, (int) $app->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        $app->exec("UPDATE users SET name='T2' WHERE email='t14@x.com'");
        $app->exec("DELETE FROM sessions");

        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->exec("UPDATE audit_logs SET acao='adulterado'")), 'UPDATE em audit_logs deveria ser negado');
        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->exec('DELETE FROM audit_logs')), 'DELETE em audit_logs deveria ser negado');
        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->exec('TRUNCATE TABLE audit_logs')), 'TRUNCATE em audit_logs deveria ser negado');
        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->exec('DROP TABLE audit_logs')), 'DROP TABLE deveria ser negado');
        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->exec('ALTER TABLE audit_logs ADD COLUMN x INT')), 'ALTER TABLE deveria ser negado');
        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->exec('CREATE TABLE intruso (id INT)')), 'CREATE TABLE deveria ser negado');
        $this->assertSame(1142, $this->codigoDoErro(fn () => $app->query('SELECT * FROM migrations')->fetchAll()), 'a aplicação não lê a tabela migrations');
        $this->assertNotNull($this->codigoDoErro(fn () => $app->query('SELECT * FROM mysql.user')->fetchAll()), 'o usuário da aplicação não enxerga outros bancos');

        // A auditoria continua intacta.
        $this->assertSame('created', $this->root->query('SELECT acao FROM `' . self::BANCO . '`.audit_logs')->fetchColumn());

        // 4) o migrador (deploy) tem DDL — e só nesse banco.
        $migrador = $this->comoUsuario(self::MIGRADOR, self::SENHA_MIGRADOR);
        $migrador->exec('CREATE TABLE prova_ddl (id INT)');
        $migrador->exec('DROP TABLE prova_ddl');
    }

    public function test_o_laravel_opera_normalmente_com_o_usuario_restrito_e_recebe_erro_ao_tentar_alterar_a_auditoria(): void
    {
        [$codigo, $saida] = $this->rodarArtisan(['migrate', '--force', '--seed', '--no-interaction']);
        $this->assertSame(0, $codigo, $saida);
        $this->executarScript();

        $php = <<<'PHP'
$out = [];
$perfil = App\Models\Perfil::where('slug', 'pastor')->first();
$u = App\Models\User::create(['name' => 'T', 'email' => 't14@x.com', 'password' => 'Senha-Forte-14!', 'perfil_id' => $perfil->id, 'ativo' => true]);
$out['user'] = (bool) $u->id;
$out['audit_insert'] = (bool) App\Models\AuditLog::create(['user_id' => $u->id, 'acao' => 'created', 'modulo' => 'usuarios'])->id;
$out['sessao'] = DB::table('sessions')->insert(['id' => 'x', 'payload' => 'p', 'last_activity' => time()]) && DB::table('sessions')->delete() === 1;
$out['excecao'] = (bool) App\Models\PermissaoExcecao::create(['user_id' => $u->id, 'permissao' => 'entradas.operar', 'concedida_por' => $u->id])->id && App\Models\PermissaoExcecao::query()->delete() === 1;
$out['categoria'] = (bool) App\Models\Categoria::create(['nome' => 'Tmp14', 'tipo' => 'entrada'])->id && App\Models\Categoria::where('nome', 'Tmp14')->delete() === 1;
foreach (['update' => fn () => App\Models\AuditLog::query()->update(['acao' => 'x']), 'delete' => fn () => App\Models\AuditLog::query()->delete(), 'truncate' => fn () => DB::statement('TRUNCATE TABLE audit_logs')] as $nome => $acao) {
    try { $acao(); $out[$nome] = 'PERMITIU'; } catch (Throwable $e) { $out[$nome] = (string) ($e->errorInfo[1] ?? 'erro'); }
}
$out['linhas_auditoria'] = App\Models\AuditLog::query()->count();
echo 'RESULTADO=' . json_encode($out);
PHP;

        [$codigo, $saida] = $this->rodarArtisan(['tinker', '--execute=' . $php], ['DB_USERNAME' => self::APP, 'DB_PASSWORD' => self::SENHA_APP]);
        $this->assertSame(1, preg_match('/RESULTADO=(\{.*\})/', $saida, $r), $saida);
        $resultado = json_decode($r[1], true);

        $this->assertTrue($resultado['user'] && $resultado['audit_insert'] && $resultado['sessao'] && $resultado['excecao'] && $resultado['categoria'], $r[1]);
        $this->assertSame('1142', $resultado['update'], $r[1]);
        $this->assertSame('1142', $resultado['delete'], $r[1]);
        $this->assertSame('1142', $resultado['truncate'], $r[1]);
        $this->assertSame(1, $resultado['linhas_auditoria'], 'A auditoria gravada continua lá.');
    }

    public function test_todos_os_fluxos_da_aplicacao_funcionam_com_o_usuario_restrito(): void
    {
        [$codigo, $saida] = $this->rodarArtisan(['migrate', '--force', '--seed', '--no-interaction']);
        $this->assertSame(0, $codigo, $saida);
        $this->executarScript();

        // Percorre a API REAL (kernel HTTP) como Pastor, com a conexão do usuário restrito: cadastro, lançamentos,
        // pagamento, transferência, ajuste, fechamento/reabertura, consultas, exportação (auditada), exclusão física de
        // despesa pendente, usuários e exceções. Nenhum passo pode precisar de UPDATE/DELETE em audit_logs.
        $php = <<<'PHP'
use Illuminate\Http\Request;
$perfil = App\Models\Perfil::where('slug', 'pastor')->first();
$pastor = App\Models\User::create(['name' => 'P', 'email' => 'p14@x.com', 'password' => 'Senha-Forte-14!', 'perfil_id' => $perfil->id, 'ativo' => true]);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$hoje = now('America/Sao_Paulo')->toDateString();
$mes = now('America/Sao_Paulo')->format('Y-m');
$chamar = function (string $metodo, string $uri, array $corpo = []) use ($kernel, $pastor) {
    app('auth')->forgetGuards();
    app('auth')->guard('web')->setUser($pastor);
    $req = Request::create('/api/v1' . $uri, $metodo, $corpo, [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $metodo === 'GET' ? null : json_encode($corpo));
    $r = $kernel->handle($req);
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
};
$passos = [];
$reg = function (string $nome, array $r, array $ok = [200, 201]) use (&$passos) { $passos[$nome] = in_array($r[0], $ok, true) ? 'ok' : "FALHOU {$r[0]}: " . substr(json_encode($r[1]), 0, 160); return $r[1]['data'] ?? null; };
$catE = $reg('categoria entrada', $chamar('POST', '/categorias', ['nome' => 'Dizimo14', 'tipo' => 'entrada']));
$catD = $reg('categoria despesa', $chamar('POST', '/categorias', ['nome' => 'Energia14', 'tipo' => 'despesa']));
$cA = $reg('conta A', $chamar('POST', '/contas', ['nome' => 'Banco14', 'tipo' => 'banco', 'saldo_inicial' => '1000.00']));
$cB = $reg('conta B', $chamar('POST', '/contas', ['nome' => 'Caixa14', 'tipo' => 'caixa', 'saldo_inicial' => '0.00']));
$reg('entrada', $chamar('POST', '/entradas', ['categoria_id' => $catE['id'], 'conta_id' => $cA['id'], 'valor' => '100.00', 'data_competencia' => $hoje]));
$d1 = $reg('despesa 1', $chamar('POST', '/despesas', ['categoria_id' => $catD['id'], 'valor' => '30.00', 'data_competencia' => $hoje, 'descricao' => 'Luz']));
$reg('pagar despesa', $chamar('POST', "/despesas/{$d1['id']}/pagar", ['conta_id' => $cA['id'], 'data_pagamento' => $hoje]));
$d2 = $reg('despesa 2', $chamar('POST', '/despesas', ['categoria_id' => $catD['id'], 'valor' => '5.00', 'data_competencia' => $hoje, 'descricao' => 'Agua']));
$reg('excluir despesa pendente (DELETE fisico)', $chamar('DELETE', "/despesas/{$d2['id']}"));
$reg('transferencia', $chamar('POST', '/transferencias', ['conta_origem_id' => $cA['id'], 'conta_destino_id' => $cB['id'], 'valor' => '50.00', 'data_transferencia' => $hoje]));
$reg('ajuste', $chamar('POST', '/ajustes', ['conta_id' => $cA['id'], 'valor' => '1.00', 'sentido' => 'credito', 'data_ajuste' => $hoje, 'justificativa' => 'Conferencia']));
$reg('fechar periodo', $chamar('POST', "/periodos-financeiros/{$mes}/fechar"));
$reg('reabrir periodo', $chamar('POST', "/periodos-financeiros/{$mes}/reabrir", ['justificativa' => 'Correcao necessaria']));
foreach (['/dashboard', '/relatorios/saldos', '/relatorios/movimentacoes', '/auditoria', '/auditoria/catalogo', '/usuarios', '/entradas', '/despesas'] as $rota) { $reg("GET {$rota}", $chamar('GET', $rota)); }
$reg('exportar csv (auditado)', $chamar('GET', '/relatorios/entradas/exportar/csv'));
$u = $reg('criar usuario', $chamar('POST', '/usuarios', ['name' => 'T', 'email' => 't14@x.com', 'password' => 'Senha-Forte-14!', 'perfil_id' => App\Models\Perfil::where('slug', 'tesoureiro')->value('id')]));
$reg('conceder excecao', $chamar('POST', "/usuarios/{$u['id']}/permissoes-excecao", ['permissao' => 'entradas.operar']));
$reg('revogar excecao (DELETE fisico)', $chamar('DELETE', "/usuarios/{$u['id']}/permissoes-excecao/entradas.operar"));
$reg('desativar usuario', $chamar('DELETE', "/usuarios/{$u['id']}"));
echo 'RESULTADO=' . json_encode(['passos' => $passos, 'auditoria' => App\Models\AuditLog::query()->count()]);
PHP;

        [, $saida] = $this->rodarArtisan(['tinker', '--execute=' . $php], ['DB_USERNAME' => self::APP, 'DB_PASSWORD' => self::SENHA_APP]);
        $this->assertSame(1, preg_match('/RESULTADO=(\{.*\})/', $saida, $r), $saida);
        $resultado = json_decode($r[1], true);

        $falhas = array_filter($resultado['passos'], fn ($v) => $v !== 'ok');
        $this->assertSame([], $falhas, $r[1]);
        $this->assertGreaterThan(15, count($resultado['passos']));
        $this->assertGreaterThan(15, $resultado['auditoria'], 'As operações acima geram dezenas de registros de auditoria.');
    }
}
