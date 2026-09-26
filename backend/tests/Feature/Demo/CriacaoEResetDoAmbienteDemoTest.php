<?php

namespace Tests\Feature\Demo;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Entrada;
use App\Models\User;
use App\Support\Demo\AmbienteDemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ambiente de demonstração: usuários, idempotência, reset seguro, bloqueio em produção e cuidados com a senha.
 */
class CriacaoEResetDoAmbienteDemoTest extends TestCase
{
    use RefreshDatabase, CenarioDemo;

    // ------------------------------------------------------------------ usuários

    public function test_cria_exatamente_os_cinco_usuarios_demo_com_perfil_e_e_mail_corretos(): void
    {
        $this->comCategoriasPadrao();

        [$codigo] = $this->rodarDemo();

        $this->assertSame(0, $codigo);
        $this->assertSame(5, User::query()->where('email', 'like', '%@sfg.demo')->count());
        $this->assertSame(5, User::query()->count(), 'Nenhum outro usuário pode ser criado.');

        $esperado = [
            'demo.pastor@sfg.demo' => ['Demo Pastor', PerfilSlug::Pastor],
            'demo.administrador@sfg.demo' => ['Demo Administrador', PerfilSlug::Administrador],
            'demo.tesoureiro@sfg.demo' => ['Demo Tesoureiro', PerfilSlug::Tesoureiro],
            'demo.auxiliar@sfg.demo' => ['Demo Auxiliar Financeiro', PerfilSlug::AuxiliarFinanceiro],
            'demo.secretario@sfg.demo' => ['Demo Secretário', PerfilSlug::Secretario],
        ];
        foreach ($esperado as $email => [$nome, $perfil]) {
            $u = User::query()->where('email', $email)->firstOrFail();
            $this->assertSame($nome, $u->name);
            $this->assertSame($perfil, $u->perfil->slug);
            $this->assertTrue($u->ativo);
            $this->assertTrue(Hash::check(self::senhaTeste(), $u->password), "A senha informada deve valer para {$email}");
            $this->assertNotSame(self::senhaTeste(), $u->password);
        }
    }

    public function test_os_usuarios_demo_entram_pela_autenticacao_real_e_nao_recebem_privilegios_extras(): void
    {
        $this->comCategoriasPadrao();
        $this->rodarDemo();

        foreach (AmbienteDemo::USUARIOS as $def) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->withHeaders(['Origin' => 'http://localhost:5173'])
                ->postJson('/api/v1/auth/login', ['email' => $def['email'], 'password' => self::senhaTeste()])
                ->assertOk()->assertJsonPath('data.perfil.slug', $def['perfil']->value);
        }

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/login', ['email' => 'demo.pastor@sfg.demo', 'password' => 'senha-errada'])->assertStatus(422);

        $this->assertSame(0, DB::table('permissoes_excecao')->count(), 'Nenhuma exceção de permissão é concedida aos usuários demo.');
    }

    public function test_nao_altera_usuarios_reais(): void
    {
        $this->comCategoriasPadrao();
        $real = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create(['email' => 'pessoa@igreja.example', 'name' => 'Pessoa Real']);
        $foto = fn () => array_map('strval', User::query()->find($real->id)->only(['name', 'email', 'password', 'perfil_id', 'ativo', 'updated_at']));
        $antes = $foto();

        $this->rodarDemo();
        $this->rodarDemo();

        $this->assertSame($antes, $foto());
        $this->assertSame(6, User::query()->count());
    }

    public function test_reexecutar_aplica_a_nova_senha_e_reativa_o_usuario_demo_sem_duplicar(): void
    {
        $this->comCategoriasPadrao();
        $this->rodarDemo();
        $this->usuarioDemo('auxiliar')->forceFill(['ativo' => false])->save();

        [$codigo] = $this->rodarDemo(self::outraSenhaTeste());

        $this->assertSame(0, $codigo);
        $aux = $this->usuarioDemo('auxiliar');
        $this->assertTrue($aux->ativo);
        $this->assertTrue(Hash::check(self::outraSenhaTeste(), $aux->password));
        $this->assertFalse(Hash::check(self::senhaTeste(), $aux->password));
        $this->assertSame(5, User::query()->count());
    }

    // ------------------------------------------------------------------ idempotência

    public function test_segunda_execucao_nao_duplica_nada(): void
    {
        $this->comCategoriasPadrao();

        $this->rodarDemo();
        $primeira = $this->fotoDoBanco();

        [$codigo, $saida] = $this->rodarDemo();
        $segunda = $this->fotoDoBanco();

        $this->assertSame(0, $codigo);
        $this->assertSame($primeira, $segunda, 'A segunda execução não pode criar nem apagar nada.');
        $this->assertStringContainsString('Já existiam', $saida);
    }

    public function test_reset_e_depois_criar_de_novo_da_o_mesmo_resultado(): void
    {
        $this->comCategoriasPadrao();
        $inicial = $this->fotoDoBanco();

        $this->rodarDemo();
        $completo = $this->fotoDoBanco();
        $resumoA = app(AmbienteDemo::class)->resumo();

        $this->rodarReset();
        $this->assertSame($inicial, $this->fotoDoBanco(), 'O reset devolve o banco ao estado anterior à demonstração.');

        $this->rodarDemo();
        $this->assertSame($completo, $this->fotoDoBanco());
        $this->assertSame($resumoA, app(AmbienteDemo::class)->resumo());
    }

    // ------------------------------------------------------------------ reset

    public function test_reset_remove_somente_o_demo_e_preserva_os_dados_reais(): void
    {
        $this->comCategoriasPadrao();
        $realUsuario = User::factory()->comPerfil(PerfilSlug::Pastor)->create(['email' => 'pastor@igreja.example']);
        $realConta = Conta::create(['nome' => 'Banco da Igreja', 'tipo' => 'banco', 'saldo_inicial' => '100.00']);
        $realCategoria = Categoria::create(['nome' => 'Categoria da Igreja', 'tipo' => 'entrada', 'ativa' => true]);
        $realEntrada = Entrada::create(['categoria_id' => $realCategoria->id, 'conta_id' => $realConta->id, 'valor' => '50.00', 'data_competencia' => now()->toDateString(), 'status' => 'confirmada', 'criado_por' => $realUsuario->id]);
        AuditLog::create(['user_id' => $realUsuario->id, 'acao' => 'created', 'modulo' => 'contas', 'registro_id' => $realConta->id]);
        $antes = $this->fotoDoBanco();

        $this->rodarDemo();
        [$codigo, $saida] = $this->rodarReset();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Dados de demonstração removidos', $saida);
        $this->assertSame($antes, $this->fotoDoBanco());
        $this->assertSame(0, User::query()->where('email', 'like', '%@sfg.demo')->count());
        $this->assertSame(0, Conta::withTrashed()->where('nome', 'like', 'DEMO - %')->count());
        foreach ([$realUsuario, $realConta, $realCategoria, $realEntrada] as $registro) {
            $this->assertNotNull($registro::query()->find($registro->id), 'O reset apagou um dado que não é de demonstração: ' . $registro::class);
        }
        $this->assertSame(1, AuditLog::query()->where('user_id', $realUsuario->id)->count(), 'A auditoria de usuários reais é preservada.');
    }

    public function test_reset_remove_tambem_as_categorias_e_contas_criadas_pela_demo_quando_nao_ha_categoria_padrao(): void
    {
        $this->assertSame(0, Categoria::query()->count());

        $this->rodarDemo();
        $this->assertSame(8, Categoria::query()->where('nome', 'like', 'DEMO - %')->count());
        $this->assertSame(3, Conta::query()->where('nome', 'like', 'DEMO - %')->count());

        $this->rodarReset();

        $this->assertSame(0, Categoria::query()->count());
        $this->assertSame(0, Conta::withTrashed()->count());
        $this->assertSame(0, User::withTrashed()->count());
    }

    public function test_reset_preserva_categoria_demo_que_passou_a_ser_usada_por_dado_real(): void
    {
        $this->rodarDemo();
        $real = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();
        $categoriaDemo = Categoria::query()->where('nome', 'DEMO - Dízimo')->firstOrFail();
        $contaReal = Conta::create(['nome' => 'Conta da Igreja', 'tipo' => 'banco', 'saldo_inicial' => '10.00']);
        $entradaReal = Entrada::create(['categoria_id' => $categoriaDemo->id, 'conta_id' => $contaReal->id, 'valor' => '9.00', 'data_competencia' => now()->toDateString(), 'status' => 'confirmada', 'criado_por' => $real->id]);

        [$codigo] = $this->rodarReset();

        $this->assertSame(0, $codigo);
        $this->assertNotNull(Categoria::query()->find($categoriaDemo->id), 'Categoria em uso por dado real não pode ser apagada.');
        $this->assertNotNull(Entrada::query()->find($entradaReal->id));
        $this->assertSame(0, User::query()->where('email', 'like', '%@sfg.demo')->count());
    }

    public function test_reset_duas_vezes_e_sem_dados_nao_e_erro(): void
    {
        $this->rodarDemo();
        $this->assertSame(0, $this->rodarReset()[0]);

        [$codigo, $saida] = $this->rodarReset();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Nenhum dado de demonstração encontrado.', $saida);
    }

    // ------------------------------------------------------------------ produção

    public function test_em_producao_os_dois_comandos_recusam_e_nada_muda(): void
    {
        $this->comCategoriasPadrao();
        $this->rodarDemo();
        $antes = $this->fotoDoBanco();

        $this->app->detectEnvironment(fn () => 'production');

        [$codigoCriar, $saidaCriar] = $this->rodarDemo();
        [$codigoReset, $saidaReset] = $this->rodarReset();

        $this->assertSame(1, $codigoCriar);
        $this->assertSame(1, $codigoReset);
        $this->assertStringContainsString('O ambiente de demonstração não pode ser executado em produção.', $saidaCriar);
        $this->assertStringContainsString('O ambiente de demonstração não pode ser executado em produção.', $saidaReset);
        $this->assertSame($antes, $this->fotoDoBanco());
    }

    public function test_em_producao_o_comando_recusa_antes_mesmo_de_pedir_a_senha(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Sem SFG_DEMO_PASSWORD: se o comando chegasse a pedir a senha, o teste falharia por pergunta inesperada.
        $this->artisan('sfg:demo')->expectsOutputToContain('O ambiente de demonstração não pode ser executado em produção.')->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_reset_preserva_conta_demo_que_passou_a_ter_dado_real(): void
    {
        $this->comCategoriasPadrao();
        $this->rodarDemo();
        $real = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();
        $contaDemo = Conta::query()->where('nome', 'DEMO - Caixa Geral')->firstOrFail();
        $categoria = Categoria::query()->where('tipo', 'entrada')->firstOrFail();
        $entradaReal = Entrada::create(['categoria_id' => $categoria->id, 'conta_id' => $contaDemo->id, 'valor' => '9.00', 'data_competencia' => now()->toDateString(), 'status' => 'confirmada', 'criado_por' => $real->id]);

        [$codigo] = $this->rodarReset();

        $this->assertSame(0, $codigo, 'O reset não pode falhar por causa de dado real numa conta demo.');
        $this->assertNotNull(Conta::query()->find($contaDemo->id), 'Conta com dado real não pode ser apagada.');
        $this->assertNotNull(Entrada::query()->find($entradaReal->id));
        $this->assertSame(0, User::query()->where('email', 'like', '%@sfg.demo')->count());
    }

    public function test_o_proprio_servico_tambem_recusa_em_producao_mesmo_chamado_por_fora_dos_comandos(): void
    {
        $this->comCategoriasPadrao();
        $this->rodarDemo();
        $antes = $this->fotoDoBanco();
        $this->app->detectEnvironment(fn () => 'production');

        foreach ([fn () => app(AmbienteDemo::class)->criar(self::senhaTeste()), fn () => app(AmbienteDemo::class)->limpar()] as $chamada) {
            try {
                $chamada();
                $this->fail('O serviço deveria recusar em produção');
            } catch (\LogicException $e) {
                $this->assertSame('O ambiente de demonstração não pode ser executado em produção.', $e->getMessage());
            }
        }

        $this->assertSame($antes, $this->fotoDoBanco());
    }

    public function test_nao_existe_forma_de_burlar_o_bloqueio_de_producao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $definicao = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();
        foreach (['sfg:demo', 'sfg:demo:reset'] as $nome) {
            $opcoes = array_keys($definicao[$nome]->getDefinition()->getOptions());
            $this->assertNotContains('force', $opcoes, "{$nome} não pode ter --force");
        }

        // Nem existe a opção: tentar usá-la é erro de uso do comando, e nada é criado.
        try {
            $this->artisan('sfg:demo', ['--force' => true]);
            $this->fail('--force não deveria existir');
        } catch (\Symfony\Component\Console\Exception\InvalidOptionException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, User::query()->count());
    }

    // ------------------------------------------------------------------ senha

    public function test_senha_interativa_oculta_quando_nao_ha_variavel_de_ambiente(): void
    {
        $this->comCategoriasPadrao();

        $this->artisan('sfg:demo')
            ->expectsQuestion('Senha de demonstração (não aparece na tela)', self::senhaTeste())
            ->expectsQuestion('Repita a senha', self::senhaTeste())
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::senhaTeste(), $this->usuarioDemo('pastor')->password));
    }

    public function test_recusa_senhas_diferentes_fracas_ou_ausentes_e_nao_cria_nada(): void
    {
        $this->artisan('sfg:demo')
            ->expectsQuestion('Senha de demonstração (não aparece na tela)', self::senhaTeste())
            ->expectsQuestion('Repita a senha', 'outra-coisa')
            ->expectsOutputToContain('As senhas não conferem')
            ->assertFailed();

        $this->artisan('sfg:demo')
            ->expectsQuestion('Senha de demonstração (não aparece na tela)', '123')
            ->expectsQuestion('Repita a senha', '123')
            ->assertFailed();

        [$codigo] = $this->rodarDemo('curta');
        $this->assertSame(1, $codigo, 'Senha fraca vinda do ambiente também é recusada.');

        $this->artisan('sfg:demo', ['--no-interaction' => true])->expectsOutputToContain('modo interativo')->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_senha_nao_e_impressa_nem_gravada_em_texto_puro_em_lugar_nenhum(): void
    {
        $this->comCategoriasPadrao();
        $arquivoDeLog = tempnam(sys_get_temp_dir(), 'sfg-demo-log-');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $arquivoDeLog]);

        [, $saida] = $this->rodarDemo();

        $this->assertStringNotContainsString(self::senhaTeste(), $saida);
        $this->assertStringNotContainsString(self::senhaTeste(), (string) file_get_contents($arquivoDeLog));
        $this->assertStringNotContainsString(self::senhaTeste(), json_encode(AuditLog::query()->get()->toArray()));
        foreach (['users', 'contas', 'entradas', 'despesas', 'transferencias', 'ajustes_saldo', 'categorias'] as $tabela) {
            $this->assertStringNotContainsString(self::senhaTeste(), json_encode(DB::table($tabela)->get()->toArray()), "senha em texto puro na tabela {$tabela}");
        }
        @unlink($arquivoDeLog);
    }

    public function test_falha_no_meio_nao_deixa_registros_parciais_e_nao_vaza_a_senha(): void
    {
        // Sem perfis, o comando não consegue montar nada: erro claro, nenhuma linha parcial, nenhuma senha na saída.
        DB::table('perfis')->delete();

        [$codigo, $saida] = $this->rodarDemo();

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Não foi possível montar', $saida);
        $this->assertStringNotContainsString(self::senhaTeste(), $saida);
        $this->assertSame(0, User::query()->count());
    }

    public function test_o_codigo_e_a_documentacao_nao_tem_senha_fixa(): void
    {
        $arquivos = array_merge(
            glob(app_path('Console/Commands/Demo*.php')),
            glob(app_path('Support/Demo/*.php')),
            [base_path('../README.md'), base_path('.env.example'), base_path('../frontend/.env.example')],
            glob(base_path('../docs/demo/*.md')) ?: [],
        );

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            $this->assertStringNotContainsString(self::senhaTeste(), $conteudo, $arquivo);
            $this->assertDoesNotMatchRegularExpression('/[\'"]password[\'"]\s*=>\s*[\'"][^\'"\s]{6,}[\'"]/i', $conteudo, "senha literal em {$arquivo}");
            $this->assertDoesNotMatchRegularExpression('/SFG_DEMO_PASSWORD\s*=\s*\S+/', $conteudo, "valor de SFG_DEMO_PASSWORD em {$arquivo}");
        }
    }
}
