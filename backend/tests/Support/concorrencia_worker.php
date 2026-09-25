<?php

/**
 * Processo auxiliar dos testes de concorrência (um processo PHP = uma conexão real ao banco).
 *
 * Uso:
 *   php concorrencia_worker.php estornar <entradaId> <userId> <inicioEpochMs> [confirmar]
 *   php concorrencia_worker.php criar <userId> <chave> <contaId> <categoriaId> <inicioEpochMs>
 *
 * Todos os processos aguardam o mesmo instante de início para disputar de verdade.
 * Imprime uma linha JSON: {"ok":true,...} ou {"ok":false,"code":"..."}.
 */

use App\Exceptions\RegraNegocioException;
use App\Models\User;
use App\Services\EntradaService;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.connections.' . config('database.default') . '.database') !== 'sfg_testing') {
    fwrite(STDOUT, json_encode(['ok' => false, 'code' => 'BANCO_INESPERADO']));
    exit(1);
}

$acao = $argv[1];
$aguardarAte = (int) ($acao === 'estornar' ? $argv[4] : $argv[6]);
while ((int) (microtime(true) * 1000) < $aguardarAte) {
    usleep(200);
}

try {
    $servico = $app->make(EntradaService::class);

    if ($acao === 'estornar') {
        $ator = User::findOrFail((int) $argv[3]);
        $estorno = $servico->estornar((int) $argv[2], 'Estorno concorrente', ($argv[5] ?? '') === 'confirmar', $ator);
        $resultado = ['ok' => true, 'id' => $estorno->id];
    } else {
        $ator = User::findOrFail((int) $argv[2]);
        [$entrada, $replay] = $servico->criar([
            'categoria_id' => (int) $argv[5],
            'conta_id' => (int) $argv[4],
            'valor' => '10.00',
            'data_competencia' => '2026-01-10',
            'descricao' => null,
            'contribuinte_nome' => null,
        ], $ator, $argv[3]);
        $resultado = ['ok' => true, 'id' => $entrada->id, 'replay' => $replay];
    }
} catch (RegraNegocioException $e) {
    $resultado = ['ok' => false, 'code' => $e->render()->getData(true)['code']];
} catch (Throwable $e) {
    $resultado = ['ok' => false, 'code' => 'ERRO_INESPERADO', 'detalhe' => get_class($e) . ': ' . $e->getMessage()];
}

fwrite(STDOUT, json_encode($resultado));
