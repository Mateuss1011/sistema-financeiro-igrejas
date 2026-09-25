<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 13 — concorrência REAL na gestão de usuários (cada worker é um processo PHP com conexão própria ao MariaDB).
 * Invariante central: o sistema NUNCA fica sem Pastor ativo, mesmo quando dois Pastores se desativam/rebaixam ao mesmo
 * tempo (sem trava, cada transação enxergava "o outro ainda ativo" e ambas passavam). E dois cadastros simultâneos do
 * mesmo e-mail resultam em UM usuário e uma recusa de validação — nunca em erro interno.
 */
class ConcorrenciaDeUsuariosTest extends TestCase
{
    use CenarioSeguranca;

    private const TABELAS = ['transferencias', 'ajustes_saldo', 'despesas', 'entradas', 'periodos_financeiros', 'permissoes_excecao', 'audit_logs', 'contas', 'categorias', 'users'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sfg_testing', DB::getDatabaseName(), 'Teste de concorrência só roda no banco de testes.');
        $this->limpar();
    }

    protected function tearDown(): void
    {
        $this->limpar();
        parent::tearDown();
    }

    private function limpar(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABELAS as $tabela) {
            DB::table($tabela)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @param  list<array<string, string|int>>  $jobs */
    private function dispararEmParalelo(array $jobs): array
    {
        $inicio = (int) (microtime(true) * 1000) + 3000;
        $script = base_path('tests/Support/usuarios_worker.php');
        $ambiente = array_merge(getenv(), ['DB_DATABASE' => 'sfg_testing', 'APP_ENV' => 'testing']);
        $processos = [];

        foreach ($jobs as $i => $job) {
            $argumentos = [];
            foreach (($job + ['inicio' => $inicio]) as $k => $v) {
                $argumentos[] = "$k=$v";
            }
            $proc = proc_open([PHP_BINARY, $script, ...$argumentos], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);
            $processos[$i] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $resultados = [];
        foreach ($processos as $i => $p) {
            $saida = stream_get_contents($p['pipes'][1]);
            $erro = stream_get_contents($p['pipes'][2]);
            proc_close($p['proc']);
            $resultados[$i] = json_decode($saida, true) ?? ['ok' => false, 'code' => 'SAIDA_INVALIDA', 'detalhe' => $saida . $erro];
        }

        return $resultados;
    }

    private function pastoresAtivos(): int
    {
        return User::pastoresAtivos()->count();
    }

    public function test_dois_pastores_que_se_desativam_ao_mesmo_tempo_nunca_deixam_o_sistema_sem_pastor(): void
    {
        for ($rodada = 1; $rodada <= 6; $rodada++) {
            $this->limpar();
            $a = $this->como(PerfilSlug::Pastor);
            $b = $this->como(PerfilSlug::Pastor);

            $r = $this->dispararEmParalelo([
                ['acao' => 'desativar', 'alvo' => $b->id, 'user' => $a->id],
                ['acao' => 'desativar', 'alvo' => $a->id, 'user' => $b->id],
            ]);

            $json = json_encode($r);
            $this->assertSame(0, count(array_filter($r, fn ($x) => ($x['code'] ?? '') === 'ERRO_INESPERADO')), "Rodada {$rodada}: {$json}");
            $this->assertSame(1, $this->pastoresAtivos(), "Rodada {$rodada}: exatamente um Pastor deve restar ativo. {$json}");
            $this->assertSame(1, count(array_filter($r, fn ($x) => $x['ok'])), "Rodada {$rodada}: só uma desativação pode vencer. {$json}");
            $this->assertSame(1, count(array_filter($r, fn ($x) => ($x['code'] ?? '') === 'ULTIMO_PASTOR_ATIVO')), "Rodada {$rodada}: {$json}");
        }
    }

    public function test_pastor_que_rebaixa_o_outro_enquanto_e_desativado_nunca_zera_os_pastores(): void
    {
        $tesoureiro = Perfil::where('slug', PerfilSlug::Tesoureiro->value)->value('id');

        for ($rodada = 1; $rodada <= 6; $rodada++) {
            $this->limpar();
            $a = $this->como(PerfilSlug::Pastor);
            $b = $this->como(PerfilSlug::Pastor);

            $r = $this->dispararEmParalelo([
                ['acao' => 'rebaixar', 'alvo' => $b->id, 'user' => $a->id, 'perfil' => $tesoureiro],
                ['acao' => 'desativar', 'alvo' => $a->id, 'user' => $b->id],
            ]);

            $json = json_encode($r);
            $this->assertSame(0, count(array_filter($r, fn ($x) => ($x['code'] ?? '') === 'ERRO_INESPERADO')), "Rodada {$rodada}: {$json}");
            $this->assertSame(1, $this->pastoresAtivos(), "Rodada {$rodada}: {$json}");
        }
    }

    public function test_tres_pastores_desativando_em_circulo_deixam_ao_menos_um(): void
    {
        $this->limpar();
        $a = $this->como(PerfilSlug::Pastor);
        $b = $this->como(PerfilSlug::Pastor);
        $c = $this->como(PerfilSlug::Pastor);

        $r = $this->dispararEmParalelo([
            ['acao' => 'desativar', 'alvo' => $b->id, 'user' => $a->id],
            ['acao' => 'desativar', 'alvo' => $c->id, 'user' => $b->id],
            ['acao' => 'desativar', 'alvo' => $a->id, 'user' => $c->id],
        ]);

        $json = json_encode($r);
        $this->assertSame(0, count(array_filter($r, fn ($x) => ($x['code'] ?? '') === 'ERRO_INESPERADO')), $json);
        $this->assertGreaterThanOrEqual(1, $this->pastoresAtivos(), $json);
    }

    public function test_cadastros_simultaneos_do_mesmo_email_geram_um_usuario_e_uma_recusa_limpa(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $perfil = Perfil::where('slug', PerfilSlug::Tesoureiro->value)->value('id');

        for ($rodada = 1; $rodada <= 4; $rodada++) {
            User::query()->where('email', "duplicado{$rodada}@exemplo.com")->delete();

            $r = $this->dispararEmParalelo([
                ['acao' => 'criar', 'user' => $pastor->id, 'email' => "duplicado{$rodada}@exemplo.com", 'perfil' => $perfil],
                ['acao' => 'criar', 'user' => $pastor->id, 'email' => "duplicado{$rodada}@exemplo.com", 'perfil' => $perfil],
                ['acao' => 'criar', 'user' => $pastor->id, 'email' => "duplicado{$rodada}@exemplo.com", 'perfil' => $perfil],
            ]);

            $json = json_encode($r);
            $this->assertSame(1, User::query()->where('email', "duplicado{$rodada}@exemplo.com")->count(), "Rodada {$rodada}: {$json}");
            $this->assertSame(1, count(array_filter($r, fn ($x) => $x['ok'])), $json);
            $this->assertSame(2, count(array_filter($r, fn ($x) => ($x['code'] ?? '') === 'VALIDACAO')), "Duplicidade deve virar recusa de validação, não erro interno: {$json}");
        }
    }
}
