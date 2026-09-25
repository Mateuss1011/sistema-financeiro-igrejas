<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Regras do filtro "ano_mes" (AAAA-MM) compartilhadas por Dashboard e Relatórios: o mês corrente é sempre o do
 * fuso da igreja (America/Sao_Paulo), decidido pelo backend, e meses futuros nunca são aceitos.
 */
final class AnoMes
{
    public const PADRAO = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** 'YYYY-MM' ordena corretamente como texto. */
    public static function corrente(): string
    {
        return Carbon::now('America/Sao_Paulo')->format('Y-m');
    }

    /** @return array{0: string, 1: string} primeiro e último dia do mês, como 'Y-m-d'. */
    public static function limites(string $anoMes): array
    {
        $inicio = $anoMes . '-01';

        return [$inicio, Carbon::createFromFormat('Y-m-d', $inicio)->endOfMonth()->format('Y-m-d')];
    }
}
