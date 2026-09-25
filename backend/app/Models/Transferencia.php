<?php

namespace App\Models;

use App\Enums\StatusTransferencia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Transferência entre contas: uma linha = uma operação (+valor na destino, −valor na origem).
 * Imutável, sem soft delete. O estorno é uma linha NOVA em sentido inverso (origem e destino
 * trocados) vinculada à original por `transferencia_estornada_id`.
 */
class Transferencia extends Model
{
    protected $table = 'transferencias';

    protected $fillable = [
        'conta_origem_id', 'conta_destino_id', 'valor', 'data_transferencia', 'descricao', 'status',
        'transferencia_estornada_id', 'motivo_estorno', 'criado_por', 'chave_idempotencia', 'hash_payload',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', // sempre string, nunca float
            'data_transferencia' => 'date',
            'status' => StatusTransferencia::class,
        ];
    }

    public function ehEstorno(): bool
    {
        return $this->transferencia_estornada_id !== null;
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Conta::class, 'conta_origem_id')->withTrashed();
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Conta::class, 'conta_destino_id')->withTrashed();
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por')->withTrashed();
    }

    /** Para uma original: a linha de estorno (se existir). */
    public function estorno(): HasOne
    {
        return $this->hasOne(self::class, 'transferencia_estornada_id');
    }
}
