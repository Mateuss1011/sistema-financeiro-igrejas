<?php

namespace App\Services;

use App\Models\User;

/**
 * Fase 11: indicadores básicos do mês (sem gráficos), somente leitura.
 *
 * Desde a Fase 12 o cálculo vive em IndicadoresFinanceirosService — a fonte ÚNICA compartilhada com os Relatórios e
 * as Exportações (regras de cálculo, escopo do Auxiliar, saldos e situação do período estão documentados lá). Este
 * service permanece como fachada do Dashboard e não tem SQL próprio.
 */
class DashboardService
{
    public function __construct(private IndicadoresFinanceirosService $indicadores)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function consultar(User $ator, string $anoMes): array
    {
        return $this->indicadores->resumo($ator, $anoMes);
    }
}
