<?php

namespace App\Support\Exportacao;

/**
 * Proteção contra CSV/Excel Formula Injection. Um texto controlado pelo usuário (descrição, fornecedor, nomes de
 * categoria/conta/usuário…) que comece com `=`, `+`, `-`, `@`, TAB ou CR poderia ser interpretado pelo Excel como
 * fórmula. Regra única (usada por CSV e XLSX): prefixar um apóstrofo (`'`), que o Excel trata como "texto".
 *
 * Só se aplica a valores TEXTUAIS. Números/dinheiro (inclusive negativos legítimos como `-50,00`) NÃO passam por aqui.
 */
final class TextoSeguro
{
    public static function neutralizar(mixed $valor): string
    {
        $texto = (string) $valor;

        return $texto !== '' && preg_match('/^[=+\-@\t\r]/', $texto) === 1 ? "'" . $texto : $texto;
    }
}
