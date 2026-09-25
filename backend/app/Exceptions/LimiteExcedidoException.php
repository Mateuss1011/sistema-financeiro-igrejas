<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Limite de tentativas/requisições excedido (HTTP 429). Estende HttpException: o Laravel não a reporta como erro técnico
 * (um ataque de força bruta não deve encher o log de erros) e o handler global a converte no formato padrão da API
 * `{message, code, errors}` preservando o cabeçalho Retry-After.
 */
class LimiteExcedidoException extends HttpException
{
    public function __construct(int $segundos, public readonly string $codigoErro = 'MUITAS_TENTATIVAS')
    {
        parent::__construct(429, 'Muitas tentativas. Aguarde alguns instantes e tente novamente.', null, ['Retry-After' => max(1, $segundos)]);
    }
}
