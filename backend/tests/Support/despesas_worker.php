<?php

/**
 * Processo auxiliar dos testes de concorrência de DESPESAS (um processo PHP = uma conexão real).
 *
 * Uso: php despesas_worker.php acao=<...> inicio=<epoch_ms> k=v ...
 *   acao=pagar             despesa= user= conta= data= [confirmar=1]
 *   acao=cancelar          despesa= user=
 *   acao=estornar_despesa  despesa= user=
 *   acao=estornar_entrada  entrada= user=
 *   acao=criar             user= chave= categoria=
 *   acao=excluir_conta     conta= user=
 *   acao=excluir_categoria categoria= user=
 * Todos os processos aguardam o mesmo instante `inicio` para disputar de verdade.
 * Imprime uma linha JSON: {"ok":true,...} ou {"ok":false,"code":"..."}.
 */

use App\Exceptions\RegraNegocioException;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\User;
use App\Services\CategoriaService;
use App\Services\ContaService;
use App\Services\DespesaService;
use App\Services\EntradaService;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.connections.' . config('database.default') . '.database') !== 'sfg_testing') {
    fwrite(STDOUT, json_encode(['ok' => false, 'code' => 'BANCO_INESPERADO']));
    exit(1);
}

$args = [];
foreach (array_slice($argv, 1) as $par) {
    [$k, $v] = array_pad(explode('=', $par, 2), 2, '');
    $args[$k] = $v;
}

while ((int) (microtime(true) * 1000) < (int) $args['inicio']) {
    usleep(200);
}

try {
    $ator = User::findOrFail((int) $args['user']);

    switch ($args['acao']) {
        case 'pagar':
            [$despesa, $replay] = $app->make(DespesaService::class)->pagar(
                (int) $args['despesa'],
                ['conta_id' => (int) $args['conta'], 'data_pagamento' => $args['data']],
                ($args['confirmar'] ?? '') === '1',
                $ator,
            );
            $resultado = ['ok' => true, 'id' => $despesa->id, 'replay' => $replay];
            break;
        case 'cancelar':
            $despesa = $app->make(DespesaService::class)->cancelar((int) $args['despesa'], 'Cancelamento concorrente', $ator);
            $resultado = ['ok' => true, 'id' => $despesa->id];
            break;
        case 'estornar_despesa':
            $estorno = $app->make(DespesaService::class)->estornar((int) $args['despesa'], 'Estorno concorrente', $ator);
            $resultado = ['ok' => true, 'id' => $estorno->id];
            break;
        case 'estornar_entrada':
            $estorno = $app->make(EntradaService::class)->estornar((int) $args['entrada'], 'Estorno concorrente', false, $ator);
            $resultado = ['ok' => true, 'id' => $estorno->id];
            break;
        case 'criar':
            [$despesa, $replay] = $app->make(DespesaService::class)->criar([
                'categoria_id' => (int) $args['categoria'],
                'valor' => '10.00',
                'data_competencia' => '2026-01-10',
                'descricao' => 'Despesa concorrente',
                'fornecedor_nome' => null,
            ], $ator, $args['chave']);
            $resultado = ['ok' => true, 'id' => $despesa->id, 'replay' => $replay];
            break;
        case 'excluir_conta':
            $app->make(ContaService::class)->excluir(Conta::findOrFail((int) $args['conta']), $ator);
            $resultado = ['ok' => true];
            break;
        case 'excluir_categoria':
            $app->make(CategoriaService::class)->excluir(Categoria::findOrFail((int) $args['categoria']), $ator);
            $resultado = ['ok' => true];
            break;
        default:
            $resultado = ['ok' => false, 'code' => 'ACAO_DESCONHECIDA'];
    }
} catch (RegraNegocioException $e) {
    $resultado = ['ok' => false, 'code' => $e->render()->getData(true)['code']];
} catch (Illuminate\Validation\ValidationException $e) {
    // Na API isto é um 422 (ex.: conta/categoria excluída por outra requisição antes da operação).
    $resultado = ['ok' => false, 'code' => 'VALIDACAO_422'];
} catch (Throwable $e) {
    $resultado = ['ok' => false, 'code' => 'ERRO_INESPERADO', 'detalhe' => get_class($e) . ': ' . $e->getMessage()];
}

fwrite(STDOUT, json_encode($resultado));
