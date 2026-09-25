<?php

namespace App\Models;

use App\Enums\StatusDespesa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ciclo: Pendente → Paga → Estornada, ou Pendente → Cancelada (terminal).
 * Uma linha com `despesa_estornada_id` é o ESTORNO (status 'paga', valor positivo) da despesa apontada.
 * Sem soft delete; conta_id só existe em linhas com dinheiro movido.
 */
class Despesa extends Model
{
    protected $table = 'despesas';

    protected $fillable = [
        'categoria_id', 'conta_id', 'valor', 'data_competencia', 'data_pagamento', 'descricao', 'fornecedor_nome',
        'status', 'despesa_estornada_id', 'motivo_estorno', 'motivo_cancelamento',
        'criado_por', 'atualizado_por', 'pago_por', 'pago_em', 'chave_idempotencia', 'hash_payload',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', // sempre string, nunca float
            'data_competencia' => 'date',
            'data_pagamento' => 'date',
            'pago_em' => 'datetime',
            'status' => StatusDespesa::class,
        ];
    }

    public function ehEstorno(): bool
    {
        return $this->despesa_estornada_id !== null;
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class)->withTrashed();
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por')->withTrashed();
    }

    public function pagoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pago_por')->withTrashed();
    }

    /** Para um estorno: a despesa original. */
    public function despesaEstornada(): BelongsTo
    {
        return $this->belongsTo(self::class, 'despesa_estornada_id');
    }

    /** Para uma original: a linha de estorno (se existir). */
    public function estorno(): HasOne
    {
        return $this->hasOne(self::class, 'despesa_estornada_id');
    }
}
