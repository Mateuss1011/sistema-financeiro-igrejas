<?php

namespace App\Models;

use App\Enums\StatusPeriodo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 9: fecha/reabre. Período sem linha nesta tabela é considerado ABERTO — nunca existe uma
 * linha "aberto" que não tenha passado por um fechamento (o CHECK `chk_periodos_ciclo` garante isso).
 */
class PeriodoFinanceiro extends Model
{
    protected $table = 'periodos_financeiros';

    protected $fillable = [
        'ano_mes',
        'status',
        'fechado_por',
        'fechado_em',
        'reaberto_por',
        'reaberto_em',
        'justificativa_reabertura',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPeriodo::class,
            'fechado_em' => 'datetime',
            'reaberto_em' => 'datetime',
        ];
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por');
    }

    public function reabertoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reaberto_por');
    }
}
