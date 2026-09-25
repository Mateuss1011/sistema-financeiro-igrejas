<?php

namespace Tests\Feature\Deploy;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 14 — bootstrap do primeiro Pastor (`php artisan sfg:criar-pastor`). Ponto NÃO definido pelo plano oficial:
 * mecanismo mínimo, interativo, sem credencial no código e que só funciona numa instalação sem usuários.
 */
class CriarPrimeiroPastorTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Pastor-Inicial!2026';

    public function test_cria_o_primeiro_pastor_com_senha_oculta_e_audita_sem_a_senha(): void
    {
        $this->artisan('sfg:criar-pastor')
            ->expectsQuestion('Nome completo do Pastor', 'Pastor Inicial')
            ->expectsQuestion('E-mail de login', 'pastor@igreja.example')
            ->expectsQuestion('Senha (não aparece na tela)', self::SENHA)
            ->expectsQuestion('Repita a senha', self::SENHA)
            ->expectsOutputToContain('Pastor criado')
            ->assertSuccessful();

        $pastor = User::query()->firstOrFail();
        $this->assertSame(PerfilSlug::Pastor, $pastor->perfil->slug);
        $this->assertTrue($pastor->ativo);
        $this->assertNotSame(self::SENHA, $pastor->password);
        $this->assertTrue(Hash::check(self::SENHA, $pastor->password));

        $log = AuditLog::query()->where('modulo', 'usuarios')->where('acao', 'created')->firstOrFail();
        $this->assertSame($pastor->id, $log->registro_id);
        $this->assertStringNotContainsString(self::SENHA, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString('$2y$', json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_o_pastor_criado_consegue_entrar_pela_api(): void
    {
        $this->artisan('sfg:criar-pastor', ['--nome' => 'Pastor Inicial', '--email' => 'pastor@igreja.example'])
            ->expectsQuestion('Senha (não aparece na tela)', self::SENHA)
            ->expectsQuestion('Repita a senha', self::SENHA)
            ->assertSuccessful();

        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/login', ['email' => 'pastor@igreja.example', 'password' => self::SENHA])
            ->assertOk()
            ->assertJsonPath('data.perfil.slug', 'pastor');
    }

    public function test_recusa_quando_ja_existe_qualquer_usuario_e_nao_cria_outro(): void
    {
        User::factory()->comPerfil(PerfilSlug::Tesoureiro)->create();

        $this->artisan('sfg:criar-pastor')->expectsOutputToContain('Já existem usuários')->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_recusa_usuario_desativado_ou_removido_tambem(): void
    {
        $antigo = User::factory()->comPerfil(PerfilSlug::Pastor)->create();
        $antigo->delete(); // soft delete: ainda conta como "já existem usuários"

        $this->artisan('sfg:criar-pastor')->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_senhas_diferentes_ou_fracas_ou_email_invalido_nao_criam_nada(): void
    {
        $this->artisan('sfg:criar-pastor', ['--nome' => 'P', '--email' => 'pastor@igreja.example'])
            ->expectsQuestion('Senha (não aparece na tela)', self::SENHA)
            ->expectsQuestion('Repita a senha', 'outra-coisa')
            ->expectsOutputToContain('As senhas não conferem')
            ->assertFailed();

        $this->artisan('sfg:criar-pastor', ['--nome' => 'P', '--email' => 'pastor@igreja.example'])
            ->expectsQuestion('Senha (não aparece na tela)', '123')
            ->expectsQuestion('Repita a senha', '123')
            ->assertFailed();

        $this->artisan('sfg:criar-pastor', ['--nome' => 'P', '--email' => 'nao-e-email'])
            ->expectsQuestion('Senha (não aparece na tela)', self::SENHA)
            ->expectsQuestion('Repita a senha', self::SENHA)
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_nao_executa_sem_terminal_interativo_para_a_senha_nunca_vir_de_argumento(): void
    {
        $this->artisan('sfg:criar-pastor', ['--nome' => 'P', '--email' => 'pastor@igreja.example', '--no-interaction' => true])
            ->expectsOutputToContain('modo interativo')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_o_comando_nao_aceita_senha_por_opcao(): void
    {
        $definicao = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all()['sfg:criar-pastor']->getDefinition();

        $this->assertFalse($definicao->hasOption('senha'));
        $this->assertFalse($definicao->hasOption('password'));
        $this->assertFalse($definicao->hasArgument('senha'));
    }
}
