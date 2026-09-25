<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'user_nome_congelado',
        'user_perfil_congelado',
        'acao',
        'modulo',
        'registro_id',
        'dados_anteriores',
        'dados_novos',
        'justificativa',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'dados_anteriores' => 'array',
        'dados_novos' => 'array',
    ];

    /**
     * A auditoria é somente-leitura para a aplicação: um registro, uma vez gravado, não pode ser alterado nem excluído
     * por Eloquent (defesa em profundidade além da ausência de rotas de escrita). Correções de negócio geram NOVOS
     * registros. A proteção no nível do banco (usuário do MariaDB sem UPDATE/DELETE em `audit_logs`) é tarefa de deploy.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registros de auditoria são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Registros de auditoria não podem ser excluídos.'));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
