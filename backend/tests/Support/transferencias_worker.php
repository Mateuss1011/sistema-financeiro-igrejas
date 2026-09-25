<?php

/**
 * Processo auxiliar dos testes de concorrência das Fases 8 e 9 (um processo PHP = uma conexão real
 * ao banco).
 *
 * Uso: php transferencias_worker.php acao=<...> inicio=<epoch_ms> k=v ...
 *   acao=transferir           user= origem= destino= valor= data= [confirmar=1] [chave=]
 *   acao=estornar_transferencia transferencia= user= [confirmar=1]
 *   acao=ajustar              user= conta= valor= sentido= data= [confirmar=1] [chave=]
 *   acao=pagar_despesa        despesa= user= conta= data=
 *   acao=estornar_entrada     entrada= user=
 *   acao=excluir_conta        conta= user=
 *   acao=inativar_conta       conta= user=
 *   acao=fechar_periodo       ano_mes= user=
 *   acao=reabrir_periodo      ano_mes= user= [justificativa=]
 *   acao=criar_despesa        categoria= user= data= [valor=]
 *   acao=criar_entrada        conta= categoria= user= data= [valor=]
 * Todos aguardam o mesmo instante `inicio` para disputar de verdade.
 * Imprime uma linha JSON: {"ok":true,...} ou {"ok":false,"code":"..."}.
 */

use App\Exceptions\RegraNegocioException;
use App\Models\Conta;
use App\Models\User;
use App\Services\AjusteSaldoService;
use App\Services\ContaService;
use App\Services\DespesaService;
use App\Services\EntradaService;
use App\Services\PeriodoFinanceiroService;
use App\Services\TransferenciaService;

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
    $confirmar = ($args['confirmar'] ?? '') === '1';
    $chave = ($args['chave'] ?? '') !== '' ? $args['chave'] : null;

    switch ($args['acao']) {
        case 'transferir':
            [$t, $replay] = $app->make(TransferenciaService::class)->criar([
                'conta_origem_id' => (int) $args['origem'],
                'conta_destino_id' => (int) $args['destino'],
                'valor' => $args['valor'],
                'data_transferencia' => $args['data'],
                'descricao' => null,
            ], $confirmar, $ator, $chave);
            $resultado = ['ok' => true, 'id' => $t->id, 'replay' => $replay];
            break;
        case 'estornar_transferencia':
            $estorno = $app->make(TransferenciaService::class)->estornar((int) $args['transferencia'], 'Estorno concorrente', $confirmar, $ator);
            $resultado = ['ok' => true, 'id' => $estorno->id];
            break;
        case 'ajustar':
            [$a, $replay] = $app->make(AjusteSaldoService::class)->criar([
                'conta_id' => (int) $args['conta'],
                'valor' => $args['valor'],
                'sentido' => $args['sentido'],
                'data_ajuste' => $args['data'],
                'justificativa' => 'Ajuste concorrente',
            ], $confirmar, $ator, $chave);
            $resultado = ['ok' => true, 'id' => $a->id, 'replay' => $replay];
            break;
        case 'pagar_despesa':
            [$d] = $app->make(DespesaService::class)->pagar((int) $args['despesa'], ['conta_id' => (int) $args['conta'], 'data_pagamento' => $args['data']], false, $ator);
            $resultado = ['ok' => true, 'id' => $d->id];
            break;
        case 'estornar_entrada':
            $e = $app->make(EntradaService::class)->estornar((int) $args['entrada'], 'Estorno concorrente', false, $ator);
            $resultado = ['ok' => true, 'id' => $e->id];
            break;
        case 'excluir_conta':
            $app->make(ContaService::class)->excluir(Conta::findOrFail((int) $args['conta']), $ator);
            $resultado = ['ok' => true];
            break;
        case 'inativar_conta':
            $app->make(ContaService::class)->atualizar(Conta::findOrFail((int) $args['conta']), ['ativa' => false], $ator);
            $resultado = ['ok' => true];
            break;
        case 'fechar_periodo':
            $p = $app->make(PeriodoFinanceiroService::class)->fechar($args['ano_mes'], $ator);
            $resultado = ['ok' => true, 'id' => $p->id, 'status' => $p->status->value];
            break;
        case 'reabrir_periodo':
            $p = $app->make(PeriodoFinanceiroService::class)->reabrir($args['ano_mes'], $ator, ($args['justificativa'] ?? '') !== '' ? $args['justificativa'] : 'Reabertura concorrente');
            $resultado = ['ok' => true, 'id' => $p->id, 'status' => $p->status->value];
            break;
        case 'criar_despesa':
            [$d] = $app->make(DespesaService::class)->criar([
                'categoria_id' => (int) $args['categoria'],
                'valor' => $args['valor'] ?? '10.00',
                'data_competencia' => $args['data'],
                'descricao' => 'Despesa concorrente',
                'fornecedor_nome' => null,
            ], $ator, $chave);
            $resultado = ['ok' => true, 'id' => $d->id];
            break;
        case 'criar_entrada':
            [$e] = $app->make(EntradaService::class)->criar([
                'categoria_id' => (int) $args['categoria'],
                'conta_id' => (int) $args['conta'],
                'valor' => $args['valor'] ?? '10.00',
                'data_competencia' => $args['data'],
                'descricao' => null,
                'contribuinte_nome' => null,
            ], $ator, $chave);
            $resultado = ['ok' => true, 'id' => $e->id];
            break;
        default:
            $resultado = ['ok' => false, 'code' => 'ACAO_DESCONHECIDA'];
    }
} catch (RegraNegocioException $e) {
    $resultado = ['ok' => false, 'code' => $e->render()->getData(true)['code']];
} catch (Illuminate\Validation\ValidationException $e) {
    // Na API isto é um 422 (ex.: conta excluída por outra requisição antes da operação).
    $resultado = ['ok' => false, 'code' => 'VALIDACAO_422'];
} catch (Throwable $e) {
    $resultado = ['ok' => false, 'code' => 'ERRO_INESPERADO', 'detalhe' => get_class($e) . ': ' . $e->getMessage()];
}

fwrite(STDOUT, json_encode($resultado));
