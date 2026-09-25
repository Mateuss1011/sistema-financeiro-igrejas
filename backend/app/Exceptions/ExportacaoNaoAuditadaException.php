<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A exportação é SEMPRE auditada (plano §6/§16). Se o registro de auditoria falhar, o arquivo NÃO é entregue: a
 * resposta é 500 no formato padrão da API, sem stack trace nem detalhe interno (o erro técnico vai para o log).
 */
class ExportacaoNaoAuditadaException extends Exception
{
    public function __construct(?\Throwable $anterior = null)
    {
        parent::__construct('Não foi possível concluir a exportação. Tente novamente em instantes.', 0, $anterior);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'EXPORTACAO_NAO_AUDITADA',
            'errors' => [],
        ], 500);
    }
}
