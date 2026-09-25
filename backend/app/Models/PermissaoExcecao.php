<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissaoExcecao extends Model
{
    const UPDATED_AT = null;
    const CREATED_AT = 'criado_em';

    protected $table = 'permissoes_excecao';

    protected $fillable = ['user_id', 'permissao', 'concedida_por'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function concedidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concedida_por');
    }
}
