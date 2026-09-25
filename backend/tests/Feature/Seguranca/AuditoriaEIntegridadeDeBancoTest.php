<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Perfil;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * Fase 13 — auditoria (somente leitura, sem vazar segredo, com nome/perfil congelados) e integridade do banco (as
 * constraints existem e barram estados inválidos MESMO por fora da aplicação).
 */
class AuditoriaEIntegridadeDeBancoTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    // ------------------------------------------------------------------ auditoria

    public function test_registro_de_auditoria_nao_pode_ser_alterado_nem_excluido_pela_aplicacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $log = AuditLog::create(['user_id' => $pastor->id, 'acao' => 'created', 'modulo' => 'usuarios', 'registro_id' => 1, 'justificativa' => 'original']);

        $this->assertThrows(fn () => $log->update(['justificativa' => 'adulterada']), LogicException::class);
        $this->assertThrows(function () use ($log) {
            $log->justificativa = 'adulterada';
            $log->save();
        }, LogicException::class);
        $this->assertThrows(fn () => $log->delete(), LogicException::class);
        $this->assertThrows(fn () => AuditLog::query()->find($log->id)->delete(), LogicException::class);

        $this->assertSame('original', DB::table('audit_logs')->where('id', $log->id)->value('justificativa'));
        $this->assertSame(1, DB::table('audit_logs')->where('id', $log->id)->count());
    }

    public function test_nao_existe_nenhuma_rota_que_escreva_na_auditoria(): void
    {
        foreach (Route::getRoutes() as $rota) {
            if (str_contains($rota->uri(), 'auditoria') || str_contains($rota->uri(), 'audit')) {
                $this->assertSame(['GET', 'HEAD'], $rota->methods(), "Rota de auditoria com escrita: {$rota->uri()}");
            }
        }

        // Nenhum controller referencia escrita em AuditLog fora do AuditoriaService (que só cria).
        foreach (glob(app_path('Http/Controllers/Api/V1/*.php')) as $controller) {
            $this->assertDoesNotMatchRegularExpression('/AuditLog::(create|insert|update|destroy|truncate)|audit_logs/', file_get_contents($controller), basename($controller));
        }
    }

    public function test_consultas_e_leituras_nunca_geram_auditoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->massaDoMes($this->mesPassado(1));
        $antes = AuditLog::query()->count();

        $rotas = ['contas', 'categorias', 'entradas', 'despesas', 'transferencias', 'ajustes', 'periodos-financeiros', 'usuarios', 'perfis',
            'permissoes-excecao', 'auditoria', 'auditoria/catalogo', 'dashboard', 'relatorios', 'relatorios/resumo', 'relatorios/entradas',
            'relatorios/despesas', 'relatorios/movimentacoes', 'relatorios/saldos', 'auth/me'];
        foreach ($rotas as $rota) {
            $this->novaRequisicao();
            Cache::flush();
            $this->actingAs($pastor)->getJson("/api/v1/{$rota}")->assertOk();
        }
        // Tentativas negadas ou inválidas de consulta também não auditam.
        $this->novaRequisicao();
        $this->actingAs($this->como(PerfilSlug::Secretario))->getJson('/api/v1/relatorios/entradas')->assertForbidden();
        $this->novaRequisicao();
        $this->actingAs($pastor)->getJson('/api/v1/relatorios/entradas?ano_mes=2999-01')->assertStatus(422);

        $this->assertSame($antes, AuditLog::query()->count(), 'Leitura não gera log de auditoria (só a exportação, que é uma saída de dados).');
    }

    public function test_exportacao_recusada_ou_invalida_nao_gera_auditoria_de_exportacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->exportarApi($this->como(PerfilSlug::AuxiliarFinanceiro), 'entradas', 'csv')->assertForbidden();
        $this->novaRequisicao();
        $this->exportarApi($pastor, 'entradas', 'csv', ['ano_mes' => '2999-01'])->assertStatus(422);
        $this->novaRequisicao();
        $this->exportarApi($pastor, 'entradas', 'csv', ['ordenar' => ['x']])->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->where('modulo', 'exportacoes')->count());

        $this->novaRequisicao();
        $this->exportarApi($pastor, 'entradas', 'csv')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('modulo', 'exportacoes')->where('acao', 'exported')->count());
    }

    public function test_dados_sensiveis_nunca_saem_pela_consulta_de_auditoria(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        AuditLog::create([
            'user_id' => $pastor->id, 'acao' => 'updated', 'modulo' => 'usuarios', 'registro_id' => $pastor->id,
            'dados_anteriores' => ['password' => 'hash-antigo', 'name' => 'Ok', 'aninhado' => ['token' => 'abc123', 'Senha' => 'x']],
            'dados_novos' => ['remember_token' => 'rt', 'secret' => 's', 'api_token' => 't', 'name' => 'Ok2'],
        ]);

        $resposta = $this->actingAs($pastor)->getJson('/api/v1/auditoria?por_pagina=100')->assertOk();
        $corpo = $resposta->getContent();

        foreach (['hash-antigo', 'abc123', '"rt"', '"s"', '"t"'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $corpo);
        }
        $this->assertStringContainsString('[oculto]', $corpo);
        $this->assertStringContainsString('Ok2', $corpo, 'Os campos comuns continuam visíveis.');
    }

    public function test_senha_digitada_nunca_e_gravada_na_auditoria_de_login_nem_de_usuario(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/v1/auth/login', ['email' => $pastor->email, 'password' => 'Senha-Errada-Secreta-1'])->assertStatus(422);
        $this->novaRequisicao();
        $this->actingAs($pastor)->postJson('/api/v1/usuarios', ['name' => 'N', 'email' => 'n@exemplo.com', 'password' => 'Senha-Nova-Secreta-2', 'perfil_id' => Perfil::where('slug', 'tesoureiro')->value('id')])->assertCreated();

        $tudo = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString('Senha-Errada-Secreta-1', $tudo);
        $this->assertStringNotContainsString('Senha-Nova-Secreta-2', $tudo);
        $this->assertStringNotContainsString('$2y$', $tudo);
    }

    public function test_nome_e_perfil_congelados_sobrevivem_a_renomeacao_e_a_mudanca_de_perfil(): void
    {
        $tesoureiro = $this->como(PerfilSlug::Tesoureiro);
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('A', 'banco', '10.00');
        $cat = $this->categoria('Dz');
        $nomeNaEpoca = $tesoureiro->name;

        $this->actingAs($tesoureiro)->postJson('/api/v1/entradas', $this->payload($conta, $cat))->assertCreated();

        $tesoureiro->update(['name' => 'Nome Novo Depois', 'perfil_id' => Perfil::where('slug', 'secretario')->value('id')]);

        $this->novaRequisicao();
        $registro = $this->actingAs($pastor)->getJson('/api/v1/auditoria?modulo=entradas&por_pagina=100')->assertOk()->json('data.0');
        $this->assertSame($nomeNaEpoca, $registro['usuario']['nome']);
        $this->assertSame('tesoureiro', $registro['usuario']['perfil']);
    }

    // ------------------------------------------------------------------ integridade do banco

    private function assertBancoRecusa(callable $insercao, string $rotulo): void
    {
        try {
            $insercao();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("O banco aceitou um estado inválido: {$rotulo}");
    }

    public function test_o_banco_barra_estados_invalidos_mesmo_por_fora_da_aplicacao(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $conta = $this->conta('A', 'banco', '10.00');
        $outra = $this->conta('B', 'caixa', '10.00');
        $cat = $this->categoria('Dz');
        $catD = $this->categoriaDespesa('En');
        $agora = now();
        $hoje = $this->hoje();

        $entrada = fn (array $extra = []) => array_merge(['categoria_id' => $cat->id, 'conta_id' => $conta->id, 'valor' => '1.00', 'data_competencia' => $hoje,
            'status' => 'confirmada', 'criado_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora], $extra);

        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['valor' => '0.00'])), 'entrada com valor zero');
        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['valor' => '-5.00'])), 'entrada com valor negativo');
        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['conta_id' => 987654])), 'entrada em conta inexistente (FK)');
        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['criado_por' => 987654])), 'entrada de autor inexistente (FK)');
        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['entrada_estornada_id' => 987654, 'motivo_estorno' => 'x'])), 'estorno de entrada inexistente (FK)');

        $original = DB::table('entradas')->insertGetId($entrada());
        DB::table('entradas')->insert($entrada(['entrada_estornada_id' => $original, 'motivo_estorno' => 'Motivo']));
        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['entrada_estornada_id' => $original, 'motivo_estorno' => 'Outro'])), 'segundo estorno da mesma entrada');
        $this->assertBancoRecusa(fn () => DB::table('entradas')->insert($entrada(['entrada_estornada_id' => $original])), 'estorno sem motivo');

        $this->assertBancoRecusa(fn () => DB::table('despesas')->insert(['categoria_id' => $catD->id, 'valor' => '1.00', 'data_competencia' => $hoje, 'descricao' => 'x',
            'status' => 'paga', 'criado_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora]), 'despesa paga sem conta/data de pagamento');
        $this->assertBancoRecusa(fn () => DB::table('despesas')->insert(['categoria_id' => $catD->id, 'valor' => '0.00', 'data_competencia' => $hoje, 'descricao' => 'x',
            'status' => 'pendente', 'criado_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora]), 'despesa com valor zero');
        $this->assertBancoRecusa(fn () => DB::table('despesas')->insert(['categoria_id' => $catD->id, 'valor' => '1.00', 'data_competencia' => $hoje, 'descricao' => 'x',
            'status' => 'pendente', 'criado_por' => $pastor->id, 'chave_idempotencia' => 'so-chave', 'created_at' => $agora, 'updated_at' => $agora]), 'chave de idempotência sem hash');

        $transf = ['conta_origem_id' => $conta->id, 'conta_destino_id' => $outra->id, 'valor' => '1.00', 'data_transferencia' => $hoje, 'status' => 'confirmada',
            'criado_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora];
        $this->assertBancoRecusa(fn () => DB::table('transferencias')->insert(array_merge($transf, ['conta_destino_id' => $conta->id])), 'transferência para a própria conta');
        $this->assertBancoRecusa(fn () => DB::table('transferencias')->insert(array_merge($transf, ['valor' => '-1.00'])), 'transferência negativa');

        $this->assertBancoRecusa(fn () => DB::table('ajustes_saldo')->insert(['conta_id' => $conta->id, 'valor' => '1.00', 'sentido' => 'credito', 'data_ajuste' => $hoje,
            'justificativa' => 'ab', 'criado_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora]), 'ajuste com justificativa curta');

        $this->assertBancoRecusa(fn () => DB::table('periodos_financeiros')->insert(['ano_mes' => '2026-13', 'status' => 'fechado', 'fechado_por' => $pastor->id,
            'fechado_em' => $agora, 'created_at' => $agora, 'updated_at' => $agora]), 'período com mês 13');
        $this->assertBancoRecusa(fn () => DB::table('contas')->insert(['nome' => 'Caixa negativo', 'tipo' => 'caixa', 'saldo_inicial' => '-1.00', 'ativa' => 1,
            'created_at' => $agora, 'updated_at' => $agora]), 'caixa com saldo inicial negativo');

        $this->assertBancoRecusa(fn () => DB::table('users')->insert(['name' => 'Dup', 'email' => $pastor->email, 'password' => 'x', 'perfil_id' => $pastor->perfil_id,
            'ativo' => 1, 'created_at' => $agora, 'updated_at' => $agora]), 'e-mail duplicado');
        $this->assertBancoRecusa(fn () => DB::table('users')->insert(['name' => 'Sem perfil', 'email' => 'sp@exemplo.com', 'password' => 'x', 'perfil_id' => 987654,
            'ativo' => 1, 'created_at' => $agora, 'updated_at' => $agora]), 'usuário com perfil inexistente (FK)');
        $this->assertBancoRecusa(fn () => DB::table('audit_logs')->insert(['acao' => 'created', 'modulo' => 'x', 'dados_novos' => '{quebrado', 'created_at' => $agora]), 'auditoria com JSON inválido');
        $this->assertBancoRecusa(fn () => DB::table('permissoes_excecao')->insert([
            ['user_id' => $pastor->id, 'permissao' => 'entradas.operar', 'concedida_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora],
            ['user_id' => $pastor->id, 'permissao' => 'entradas.operar', 'concedida_por' => $pastor->id, 'created_at' => $agora, 'updated_at' => $agora],
        ]), 'exceção de permissão duplicada');
    }

    public function test_relacionamentos_financeiros_nao_podem_ser_orfaos(): void
    {
        $esperadas = ['entradas' => ['categoria_id', 'conta_id', 'criado_por', 'entrada_estornada_id'], 'despesas' => ['categoria_id', 'conta_id', 'criado_por', 'pago_por', 'atualizado_por', 'despesa_estornada_id'],
            'transferencias' => ['conta_origem_id', 'conta_destino_id', 'criado_por', 'transferencia_estornada_id'], 'ajustes_saldo' => ['conta_id', 'criado_por'],
            'periodos_financeiros' => ['fechado_por', 'reaberto_por'], 'permissoes_excecao' => ['user_id', 'concedida_por'], 'audit_logs' => ['user_id'], 'users' => ['perfil_id']];

        $banco = DB::getDatabaseName();
        foreach ($esperadas as $tabela => $colunas) {
            foreach ($colunas as $coluna) {
                $existe = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', $banco)->where('TABLE_NAME', $tabela)
                    ->where('COLUMN_NAME', $coluna)->whereNotNull('REFERENCED_TABLE_NAME')->exists();
                $this->assertTrue($existe, "Falta a foreign key {$tabela}.{$coluna}");
            }
        }
    }
}
