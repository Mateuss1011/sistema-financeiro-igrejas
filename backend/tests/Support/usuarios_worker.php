<?php

/**
 * Processo auxiliar dos testes de concorrência da Fase 13 (um processo PHP = uma conexão real ao banco).
 *
 * Uso: php usuarios_worker.php acao=<...> inicio=<epoch_ms> k=v ...
 *   acao=desativar   alvo= user=
 *   acao=rebaixar    alvo= user= perfil=<perfil_id>
 *   acao=criar       user= email= perfil=<perfil_id>
 * Todos aguardam o mesmo instante `inicio` para disputar de verdade.
 * Imprime uma linha JSON: {"ok":true,...} ou {"ok":false,"code":"..."}.
 */

use App\Exceptions\RegraNegocioException;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Validation\ValidationException;

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
    $servico = $app->make(UsuarioService::class);

    switch ($args['acao']) {
        case 'desativar':
            $servico->desativar(User::findOrFail((int) $args['alvo']), $ator);
            $resultado = ['ok' => true];
            break;
        case 'rebaixar':
            $servico->atualizar(User::findOrFail((int) $args['alvo']), ['perfil_id' => (int) $args['perfil']], $ator);
            $resultado = ['ok' => true];
            break;
        case 'criar':
            $u = $servico->criar(['name' => 'Concorrente', 'email' => $args['email'], 'password' => 'Senh4-Forte!2026', 'perfil_id' => (int) $args['perfil']], $ator);
            $resultado = ['ok' => true, 'id' => $u->id];
            break;
        default:
            $resultado = ['ok' => false, 'code' => 'ACAO_DESCONHECIDA'];
    }
} catch (RegraNegocioException $e) {
    $resultado = ['ok' => false, 'code' => $e->render()->getData(true)['code']];
} catch (ValidationException $e) {
    $resultado = ['ok' => false, 'code' => 'VALIDACAO'];
} catch (Throwable $e) {
    $resultado = ['ok' => false, 'code' => 'ERRO_INESPERADO', 'detalhe' => get_class($e) . ': ' . $e->getMessage()];
}

fwrite(STDOUT, json_encode($resultado));
