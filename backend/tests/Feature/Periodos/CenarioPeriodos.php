<?php

namespace Tests\Feature\Periodos;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Transferencias\CenarioTransferencias;

/** Helpers dos testes da Fase 9 (reaproveita como/conta/categoria/hoje/comExcecoes/fecharPeriodo etc.). */
trait CenarioPeriodos
{
    use CenarioTransferencias;

    protected function fecharApi(User $ator, string $anoMes)
    {
        return $this->actingAs($ator)->postJson("/api/v1/periodos-financeiros/{$anoMes}/fechar");
    }

    protected function reabrirApi(User $ator, string $anoMes, string $justificativa = 'Lançamento retroativo necessário')
    {
        return $this->actingAs($ator)->postJson("/api/v1/periodos-financeiros/{$anoMes}/reabrir", ['justificativa' => $justificativa]);
    }

    protected function listarPeriodosApi(User $ator, array $query = [])
    {
        return $this->actingAs($ator)->getJson('/api/v1/periodos-financeiros?' . http_build_query($query));
    }

    /** Status atual do período direto no banco ('aberto' sem linha, senão o status da linha). */
    protected function statusPeriodo(string $anoMes): string
    {
        return DB::table('periodos_financeiros')->where('ano_mes', $anoMes)->value('status') ?? 'aberto';
    }

    protected function mesSeguinte(string $anoMes): string
    {
        return date('Y-m', strtotime($anoMes . '-01 +1 month'));
    }

    protected function mesAnterior(string $anoMes): string
    {
        return date('Y-m', strtotime($anoMes . '-01 -1 month'));
    }
}
