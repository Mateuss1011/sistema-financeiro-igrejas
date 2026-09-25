<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class RegraNegocioException extends Exception
{
    public function __construct(string $message, private string $codigoErro = 'REGRA_NEGOCIO')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->codigoErro,
            'errors' => [],
        ], 409);
    }
}
