<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Carbon\Carbon;
use Tests\Feature\Periodos\CenarioPeriodos;

/** Helpers dos testes da Fase 11 (reaproveita como/conta/categoria/entrada/despesas/fecharApi etc.). */
trait CenarioDashboard
{
    use CenarioPeriodos;

    protected function dashboardApi(User $ator, array $query = [])
    {
        $sufixo = $query === [] ? '' : '?' . http_build_query($query);

        return $this->actingAs($ator)->getJson('/api/v1/dashboard' . $sufixo);
    }

    /** Mês corrente no fuso da igreja, como o backend o entende. */
    protected function mesAtual(): string
    {
        return Carbon::now('America/Sao_Paulo')->format('Y-m');
    }

    /** Um mês inteiro no passado (relativo a hoje) para setups com datas fixas e nunca futuras. */
    protected function mesPassado(int $mesesAtras = 2): string
    {
        return Carbon::now('America/Sao_Paulo')->startOfMonth()->subMonths($mesesAtras)->format('Y-m');
    }

    protected function ultimoDiaDe(string $anoMes): string
    {
        return Carbon::createFromFormat('Y-m-d', $anoMes . '-01')->endOfMonth()->format('Y-m-d');
    }
}
