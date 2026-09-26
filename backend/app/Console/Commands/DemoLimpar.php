<?php

namespace App\Console\Commands;

use App\Support\Demo\AmbienteDemo;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * `php artisan sfg:demo:reset` — remove SOMENTE os dados criados pela demonstração (usuários `@sfg.demo`, o que eles lançaram,
 * contas/categorias `DEMO - ...` e a trilha de auditoria produzida por eles), na ordem das chaves estrangeiras. Não usa
 * migrate:fresh nem db:wipe, não apaga tabelas inteiras e recusa rodar em produção.
 */
class DemoLimpar extends Command
{
    protected $signature = 'sfg:demo:reset';

    protected $description = 'Remove somente os dados de demonstração (não toca em nenhum dado real).';

    public function handle(AmbienteDemo $demo): int
    {
        if (app()->isProduction()) {
            $this->error(AmbienteDemo::MENSAGEM_PRODUCAO);

            return self::FAILURE;
        }

        try {
            $removidos = $demo->limpar();
        } catch (QueryException) {
            $this->error('Há registros que não são de demonstração ligados aos usuários demo. Nada foi removido.');

            return self::FAILURE;
        }

        if ($removidos === null) {
            $this->info('Nenhum dado de demonstração encontrado.');

            return self::SUCCESS;
        }

        $this->info('Dados de demonstração removidos. Nenhum dado real foi tocado.');
        $this->table(['Grupo', 'Removidos'], collect($removidos)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}
