<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** A exportação é síncrona e tem limite de linhas (config `relatorios.limite_exportacao`): acima disso, refine os filtros. */
class ExportacaoMuitoGrandeException extends Exception
{
    public function __construct(private int $limite)
    {
        parent::__construct("A exportação excede o limite de {$limite} linhas. Refine os filtros (por exemplo, escolha uma conta ou categoria) e tente novamente.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'EXPORTACAO_MUITO_GRANDE',
            'errors' => [],
        ], 422);
    }
}
