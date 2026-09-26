<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use App\Support\Demo\AmbienteDemo;
use Database\Seeders\CategoriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helpers dos testes do ambiente de demonstração. As senhas usadas aqui são geradas em tempo de execução e só existem na
 * memória do teste (banco de testes); nem os testes nem o código da aplicação têm senha fixa.
 */
trait CenarioDemo
{
    /**
     * Senha descartável, gerada a cada execução da suíte (não existe senha fixa no repositório). Só vale no banco de testes.
     */
    protected static function senhaTeste(): string
    {
        static $senha;

        return $senha ??= 'Aa1!' . Str::random(24);
    }

    /** Uma segunda senha descartável, diferente da primeira. */
    protected static function outraSenhaTeste(): string
    {
        static $senha;

        return $senha ??= 'Bb2@' . Str::random(24);
    }

    /** Roda `sfg:demo` com a senha vinda da variável de ambiente (modo controlado) e devolve [código, saída]. */
    protected function rodarDemo(?string $senha = null): array
    {
        $senha ??= self::senhaTeste();
        putenv('SFG_DEMO_PASSWORD=' . $senha);
        try {
            $codigo = Artisan::call('sfg:demo');
        } finally {
            putenv('SFG_DEMO_PASSWORD');
        }

        return [$codigo, Artisan::output()];
    }

    protected function rodarReset(): array
    {
        $codigo = Artisan::call('sfg:demo:reset');

        return [$codigo, Artisan::output()];
    }

    /** Com as categorias padrão do sistema (como no banco real depois do seed). */
    protected function comCategoriasPadrao(): void
    {
        $this->seed(CategoriaSeeder::class);
    }

    protected function usuarioDemo(string $chave): User
    {
        return User::query()->where('email', AmbienteDemo::USUARIOS[$chave]['email'])->firstOrFail();
    }

    /** Foto das quantidades de todas as tabelas de dados. @return array<string, int> */
    protected function fotoDoBanco(): array
    {
        $foto = [];
        foreach (['users', 'contas', 'categorias', 'entradas', 'despesas', 'transferencias', 'ajustes_saldo', 'periodos_financeiros', 'permissoes_excecao', 'audit_logs'] as $tabela) {
            $foto[$tabela] = DB::table($tabela)->count();
        }

        return $foto;
    }

    protected function mesAtual(): string
    {
        return now('America/Sao_Paulo')->format('Y-m');
    }

    protected function mesAnterior(): string
    {
        return now('America/Sao_Paulo')->startOfMonth()->subMonth()->format('Y-m');
    }
}
