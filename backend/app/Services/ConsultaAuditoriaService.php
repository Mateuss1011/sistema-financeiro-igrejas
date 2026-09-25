<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CatalogoAuditoria;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consulta somente leitura de audit_logs (Fase 10). Nada aqui grava, altera ou remove logs.
 * As datas do filtro são dias do calendário de America/Sao_Paulo (o fuso da igreja) e viram limites
 * UTC, porque created_at é gravado em UTC; assim "hoje" na tela é o mesmo "hoje" do usuário.
 */
class ConsultaAuditoriaService
{
    public const CAMPOS_ORDENACAO = ['created_at', 'modulo', 'acao', 'id'];

    private const FUSO_IGREJA = 'America/Sao_Paulo';

    /** @param array<string, mixed> $filtros já validados por ConsultarAuditoriaRequest */
    public function consultar(array $filtros): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->when($filtros['modulo'] ?? null, fn ($q, $v) => $q->where('modulo', $v))
            ->when($filtros['acao'] ?? null, fn ($q, $v) => $q->where('acao', $v))
            ->when($filtros['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filtros['registro_id'] ?? null, fn ($q, $v) => $q->where('registro_id', $v))
            ->when(isset($filtros['sem_usuario']) && filter_var($filtros['sem_usuario'], FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereNull('user_id'))
            ->when($filtros['data_de'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $this->inicioDoDia($v)))
            ->when($filtros['data_ate'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $this->fimDoDia($v)));

        $this->aplicarOrdenacao($query, $filtros['ordenar'] ?? '-created_at,-id');

        return $query->paginate($filtros['por_pagina'] ?? 20);
    }

    /**
     * Opções dos filtros da tela: módulos/ações com rótulo e os usuários que já aparecem em algum log
     * (só id e nome — nenhum outro dado do usuário sai por aqui).
     *
     * @return array{modulos: list<array<string, mixed>>, usuarios: list<array{id: int, nome: string}>}
     */
    public function catalogo(): array
    {
        $usuarios = User::withTrashed()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name])
            ->all();

        return ['modulos' => CatalogoAuditoria::paraFiltros(), 'usuarios' => $usuarios];
    }

    private function inicioDoDia(string $data): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $data, self::FUSO_IGREJA)->startOfDay()->utc();
    }

    private function fimDoDia(string $data): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $data, self::FUSO_IGREJA)->endOfDay()->utc();
    }

    /** Ordenação por campos permitidos; `id` desempata sempre (na direção do primeiro campo). */
    private function aplicarOrdenacao($query, string $ordenar): void
    {
        $campos = array_values(array_filter(explode(',', $ordenar), fn ($campo) => ltrim($campo, '-') !== 'id'));
        $direcaoPadrao = 'asc';

        foreach ($campos as $i => $campo) {
            $direcao = str_starts_with($campo, '-') ? 'desc' : 'asc';
            if ($i === 0) {
                $direcaoPadrao = $direcao;
            }
            $query->orderBy(ltrim($campo, '-'), $direcao);
        }

        $query->orderBy('id', $direcaoPadrao);
    }
}
