<?php

namespace App\Models;

use App\Enums\StatusEntrada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Livro de entradas IMUTÁVEL: sem soft delete e sem edição. Correção só por estorno.
 * Uma linha com `entrada_estornada_id` preenchido é o ESTORNO (valor positivo) da entrada apontada.
 */
class Entrada extends Model
{
    protected $table = 'entradas';

    protected $fillable = [
        'categoria_id', 'conta_id', 'valor', 'data_competencia', 'descricao', 'contribuinte_nome',
        'status', 'entrada_estornada_id', 'motivo_estorno', 'criado_por', 'chave_idempotencia',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', // sempre string, nunca float
            'data_competencia' => 'date',
            'status' => StatusEntrada::class,
        ];
    }

    public function ehEstorno(): bool
    {
        return $this->entrada_estornada_id !== null;
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

    /** Para um estorno: a entrada original. */
    public function entradaEstornada(): BelongsTo
    {
        return $this->belongsTo(self::class, 'entrada_estornada_id');
    }

    /** Para uma original: a linha de estorno (se existir). */
    public function estorno(): HasOne
    {
        return $this->hasOne(self::class, 'entrada_estornada_id');
    }
}
