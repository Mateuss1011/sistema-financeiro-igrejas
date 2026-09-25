<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 13 — autenticação, sessão e proteção contra força bruta. Tudo pela API real.
 *
 * Decisão do limite de login: 5 FALHAS por minuto para o par e-mail+IP (o contador só sobe em falha e é zerado no
 * sucesso, então um usuário legítimo que erra a senha uma ou duas vezes nunca é bloqueado), mais um teto de 30
 * tentativas/minuto por IP contra "password spraying" em vários e-mails. Bloqueado = 429 SEM sequer tentar autenticar
 * (mesmo com a senha certa) e SEM gravar auditoria (um atacante não pode inflar a tabela de logs).
 */
class AutenticacaoESessaoTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    private function tentar(string $email, string $senha)
    {
        return $this->withHeaders(self::ORIGEM_DO_FRONTEND)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $senha]);
    }

    /** Origem do SPA (SANCTUM_STATEFUL_DOMAINS): só requisições assim recebem sessão de cookie. */
    private const ORIGEM_DO_FRONTEND = ['Origin' => 'http://localhost:5173'];

    public function test_a_sexta_tentativa_apos_cinco_falhas_e_bloqueada_mesmo_com_a_senha_correta(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        for ($i = 1; $i <= 5; $i++) {
            $this->tentar($usuario->email, 'senha-errada-' . $i)->assertStatus(422);
        }

        $antes = AuditLog::query()->count();
        $resposta = $this->tentar($usuario->email, 'password');

        $resposta->assertStatus(429)->assertJsonPath('code', 'MUITAS_TENTATIVAS');
        $this->assertGreaterThan(0, (int) $resposta->headers->get('Retry-After'));
        $this->assertGuest('web');
        $this->assertSame($antes, AuditLog::query()->count(), 'Tentativa bloqueada não pode gerar auditoria nem autenticar.');
        $this->assertSame(0, AuditLog::query()->where('acao', 'login')->count());
        $this->assertNull($this->recarregado($usuario)->ultimo_login_em);
        $this->assertSemVazamento($resposta);
    }

    public function test_login_de_origem_sem_sessao_e_recusado_de_forma_limpa_e_nunca_como_500(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        // Sem Origin/Referer do frontend não há sessão de cookie: credenciais certas ou erradas dão a MESMA recusa.
        foreach (['password', 'errada'] as $senha) {
            $resposta = $this->postJson('/api/v1/auth/login', ['email' => $usuario->email, 'password' => $senha]);
            $resposta->assertStatus(403)->assertJsonPath('code', 'ORIGEM_NAO_PERMITIDA');
            $this->assertSemVazamento($resposta);
        }

        $this->assertGuest('web');
        $this->assertSame(0, AuditLog::query()->count(), 'Recusa por origem não é tentativa de login.');
    }

    public function test_o_bloqueio_vale_para_o_par_email_e_ip_e_nao_atinge_outro_usuario(): void
    {
        $alvo = $this->como(PerfilSlug::Tesoureiro);
        $outro = $this->como(PerfilSlug::Pastor);

        for ($i = 0; $i < 6; $i++) {
            $this->tentar($alvo->email, 'errada');
        }
        $this->tentar($alvo->email, 'password')->assertStatus(429);

        $this->tentar($outro->email, 'password')->assertOk();
    }

    public function test_e_mail_em_caixa_diferente_nao_contorna_o_bloqueio(): void
    {
        $alvo = $this->como(PerfilSlug::Tesoureiro);

        for ($i = 0; $i < 5; $i++) {
            $this->tentar($alvo->email, 'errada')->assertStatus(422);
        }

        $this->tentar(strtoupper($alvo->email), 'password')->assertStatus(429);
        $this->tentar(' ' . $alvo->email, 'password')->assertStatus(429);
    }

    public function test_login_correto_dentro_do_limite_funciona_e_zera_o_contador(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        for ($i = 0; $i < 4; $i++) {
            $this->tentar($usuario->email, 'errada')->assertStatus(422);
        }
        $this->tentar($usuario->email, 'password')->assertOk();

        // Novo visitante: mais 4 falhas depois do sucesso NÃO bloqueiam (o contador foi zerado no acerto).
        $this->novaRequisicao();
        $this->flushSession();
        for ($i = 0; $i < 4; $i++) {
            $this->tentar($usuario->email, 'errada')->assertStatus(422);
        }
    }

    public function test_tentativas_de_usuario_inexistente_tambem_sao_limitadas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('nao-existe@exemplo.com', 'x' . $i)->assertStatus(422);
        }

        $this->tentar('nao-existe@exemplo.com', 'x')->assertStatus(429);
    }

    public function test_teto_por_ip_contra_password_spraying(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->tentar("spray{$i}@exemplo.com", 'x')->assertStatus(422);
        }

        $this->tentar('spray-final@exemplo.com', 'x')->assertStatus(429);
    }

    public function test_mensagem_de_credencial_invalida_nao_revela_se_o_email_existe(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        $existente = $this->tentar($usuario->email, 'errada');
        $inexistente = $this->tentar('fantasma@exemplo.com', 'errada');

        $this->assertSame($existente->status(), $inexistente->status());
        $this->assertSame($existente->json('errors.email'), $inexistente->json('errors.email'));
        $this->assertSame($existente->json('message'), $inexistente->json('message'));
    }

    public function test_usuario_inativo_nao_entra(): void
    {
        $inativo = User::factory()->comPerfil(PerfilSlug::Tesoureiro)->inativo()->create();

        $this->tentar($inativo->email, 'password')->assertStatus(422);
        $this->assertGuest('web');
        $this->assertSame(0, AuditLog::query()->where('acao', 'login')->count());
    }

    public function test_payloads_hostis_no_login_dao_422_e_nunca_500(): void
    {
        $casos = [
            'email em array' => ['email' => ['a' => 'b'], 'password' => 'x'],
            'senha em array' => ['email' => 'a@b.com', 'password' => ['x']],
            'senha de 1 MB' => ['email' => 'a@b.com', 'password' => str_repeat('x', 1_000_000)],
            'email gigante' => ['email' => str_repeat('a', 300) . '@exemplo.com', 'password' => 'x'],
            'vazio' => [],
            'nulos' => ['email' => null, 'password' => null],
            'objeto aninhado' => ['email' => ['$ne' => 1], 'password' => ['$gt' => '']],
        ];

        foreach ($casos as $rotulo => $payload) {
            $resposta = $this->withHeaders(self::ORIGEM_DO_FRONTEND)->postJson('/api/v1/auth/login', $payload);
            $this->assertSame(422, $resposta->status(), "Login com payload hostil ({$rotulo}) deveria ser 422");
            $this->assertSemVazamento($resposta);
        }

        $this->assertGuest('web');
    }

    public function test_usuario_desativado_perde_o_acesso_na_proxima_requisicao(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        $this->novaRequisicao();
        $this->actingAs($usuario)->getJson('/api/v1/auth/me')->assertOk();

        $usuario->forceFill(['ativo' => false])->save();

        $this->novaRequisicao();
        $resposta = $this->actingAs($this->recarregado($usuario))->getJson('/api/v1/relatorios/entradas');
        $resposta->assertStatus(401)->assertJsonPath('code', 'USUARIO_INATIVO');

        $this->novaRequisicao();
        $this->actingAs($this->recarregado($usuario))->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->novaRequisicao();
        $this->actingAs($this->recarregado($usuario))->postJson('/api/v1/entradas', [])->assertStatus(401);
    }

    public function test_usuario_ativo_continua_normal_com_o_guarda_de_atividade(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        $this->novaRequisicao();
        $this->actingAs($this->recarregado($usuario))->getJson('/api/v1/auth/me')->assertOk();
        $this->novaRequisicao();
        $this->actingAs($this->recarregado($usuario))->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_respostas_de_autenticacao_e_usuarios_nunca_trazem_segredos(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->como(PerfilSlug::Tesoureiro);

        $login = $this->tentar($pastor->email, 'password')->assertOk();
        $this->novaRequisicao();
        $me = $this->actingAs($this->recarregado($pastor))->getJson('/api/v1/auth/me')->assertOk();
        $this->novaRequisicao();
        $lista = $this->actingAs($this->recarregado($pastor))->getJson('/api/v1/usuarios')->assertOk();

        foreach ([$login, $me, $lista] as $resposta) {
            $corpo = strtolower($resposta->getContent());
            foreach (['password', 'senha', 'remember_token', 'token', '$2y$', 'secret'] as $segredo) {
                $this->assertStringNotContainsString($segredo, $corpo, "Resposta expôs '{$segredo}'");
            }
        }
    }

    public function test_senha_e_guardada_com_hash_seguro_e_nunca_em_texto_puro(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        $this->novaRequisicao();
        $this->actingAs($pastor)->postJson('/api/v1/usuarios', [
            'name' => 'Novo Usuário', 'email' => 'novo@exemplo.com', 'password' => 'Senh4-Forte!2026',
            'perfil_id' => $this->como(PerfilSlug::Tesoureiro)->perfil_id,
        ])->assertCreated();

        $armazenada = User::query()->where('email', 'novo@exemplo.com')->value('password');
        $this->assertNotSame('Senh4-Forte!2026', $armazenada);
        $this->assertSame('bcrypt', Hash::info($armazenada)['algoName']);
        $this->assertTrue(Hash::check('Senh4-Forte!2026', $armazenada));
        $this->assertFalse(AuditLog::query()->get()->contains(fn ($l) => str_contains(json_encode($l->dados_novos) . json_encode($l->dados_anteriores), 'Senh4-Forte')),
            'A senha nunca pode aparecer na auditoria.');
    }

    public function test_logout_encerra_o_acesso_e_a_sessao_nao_reaproveita_o_usuario(): void
    {
        $usuario = $this->como(PerfilSlug::Tesoureiro);

        $this->tentar($usuario->email, 'password')->assertOk();
        $this->withHeaders(self::ORIGEM_DO_FRONTEND)->postJson('/api/v1/auth/logout')->assertOk();

        $this->novaRequisicao();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
