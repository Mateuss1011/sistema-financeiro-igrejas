<?php

namespace App\Support;

/**
 * Utilitário de valores monetários. Dinheiro trafega como string decimal ("1234.50");
 * nunca como float. Aritmética apenas via BCMath.
 */
final class Dinheiro
{
    /** Até 12 dígitos inteiros e 2 casas decimais (cabe em DECIMAL(14,2)). */
    private const PADRAO = '/^-?\d{1,12}(\.\d{1,2})?$/';

    /**
     * Normaliza para string com exatamente 2 casas ("1234.5" -> "1234.50").
     * Retorna null se o valor for inválido (formato, mais de 2 casas ou fora do limite).
     * Nunca arredonda em silêncio.
     */
    public static function normalizar(mixed $valor): ?string
    {
        if (is_int($valor)) {
            $texto = (string) $valor;
        } elseif (is_float($valor)) {
            // Representação mais curta que faz round-trip: reflete exatamente o que o cliente
            // enviou (1500.5) e rejeita ruído binário (0.1 + 0.2 = 0.30000000000000004).
            $texto = json_encode($valor);
        } elseif (is_string($valor)) {
            $texto = trim($valor);
        } else {
            return null;
        }

        if (! preg_match(self::PADRAO, $texto)) {
            return null;
        }

        $normalizado = bcadd($texto, '0', 2);

        return $normalizado === '-0.00' ? '0.00' : $normalizado;
    }

    public static function ehNegativo(string $valor): bool
    {
        return bccomp($valor, '0', 2) < 0;
    }
}
