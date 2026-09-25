<?php

namespace Tests\Feature\Seguranca;

use App\Models\User;
use Tests\Feature\Relatorios\CenarioRelatorios;

/**
 * Helpers dos testes da Fase 13 (reaproveita a cadeia de cenários das fases anteriores: como, conta, categoria, entrada,
 * despesaPendente/despesaPaga, comExcecoes, massaDoMes, relatorioApi, exportarApi etc.).
 */
trait CenarioSeguranca
{
    use CenarioRelatorios;

    /** Simula produção para o tratamento de erros: sem debug, nada de stack trace. */
    protected function emProducao(): void
    {
        config(['app.debug' => false]);
    }

    /** Descarta o usuário em memória do guard: a próxima requisição reautentica a partir do banco, como em produção. */
    protected function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** Usuário recarregado do banco (nunca a instância antiga em memória). */
    protected function recarregado(User $usuario): User
    {
        return User::query()->findOrFail($usuario->id);
    }

    /** Nada que denuncie o interior do servidor pode aparecer numa resposta externa. */
    protected function assertSemVazamento($resposta): void
    {
        $corpo = $resposta->getContent();

        foreach (['vendor', 'Illuminate', 'App\\Models', 'App\\\\Models', '"trace"', '"exception"', '"file"', 'SQLSTATE', 'C:\\', 'C:\\\\', 'C:/', 'password_hash', 'remember_token'] as $indicio) {
            $this->assertStringNotContainsString($indicio, $corpo, "A resposta vazou '{$indicio}': " . substr($corpo, 0, 300));
        }
    }
}
