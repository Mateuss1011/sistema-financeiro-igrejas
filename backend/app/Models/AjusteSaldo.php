<?php

namespace App\Models;

use App\Enums\SentidoAjuste;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste de saldo: correção de uma conta (valor positivo + sentido). Imutável: sem edição,
 * exclusão ou estorno; um ajuste errado é corrigido por outro ajuste de sentido oposto.
 */
class AjusteSaldo extends Model
{
    protected $table = 'ajustes_saldo';

    protected $fillable = ['conta_id', 'valor', 'sentido', 'data_ajuste', 'justificativa', 'criado_por', 'chave_idempotencia', 'hash_payload'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data_ajuste' => 'date',
            'sentido' => SentidoAjuste::class,
        ];
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class)->withTrashed();
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por')->withTrashed();
    }
}
