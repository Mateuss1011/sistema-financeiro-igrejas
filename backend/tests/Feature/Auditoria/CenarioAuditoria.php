<?php

namespace Tests\Feature\Auditoria;

use App\Models\AuditLog;
use App\Models\User;
use Tests\Feature\Periodos\CenarioPeriodos;

/** Helpers dos testes da Fase 10 (reaproveita como/conta/categoria/comExcecoes/fecharApi etc. das fases anteriores). */
trait CenarioAuditoria
{
    use CenarioPeriodos;

    protected function consultarAuditoria(User $ator, array $query = [])
    {
        return $this->actingAs($ator)->getJson('/api/v1/auditoria?' . http_build_query($query));
    }

    protected function catalogoAuditoria(User $ator)
    {
        return $this->actingAs($ator)->getJson('/api/v1/auditoria/catalogo');
    }

    /**
     * Grava um log direto (setup), com controle de created_at (em UTC, como o banco guarda) — created_at não é
     * fillable e o Eloquent o preenche sozinho, então é ajustado depois via query para não depender do relógio.
     */
    protected function log(array $atributos = [], ?string $criadoEmUtc = null): AuditLog
    {
        $log = AuditLog::create(array_merge([
            'user_id' => null,
            'user_nome_congelado' => null,
            'user_perfil_congelado' => null,
            'acao' => 'created',
            'modulo' => 'categorias',
            'registro_id' => null,
        ], $atributos));

        if ($criadoEmUtc !== null) {
            AuditLog::query()->whereKey($log->id)->toBase()->update(['created_at' => $criadoEmUtc]);
            $log->refresh();
        }

        return $log;
    }

    /** Pares [modulo, acao] presentes em audit_logs, ordenados e sem repetição (para conferir a cobertura). */
    protected function paresRegistrados(): array
    {
        return AuditLog::query()->select('modulo', 'acao')->distinct()->get()
            ->map(fn (AuditLog $l) => $l->modulo . '/' . $l->acao)
            ->sort()->values()->all();
    }
}
