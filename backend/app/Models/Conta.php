<?php

namespace App\Models;

use App\Enums\TipoConta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `nome_ativo` é uma coluna gerada pelo banco (só leitura) usada para a unicidade do nome
 * entre contas não excluídas; por isso não está em $fillable.
 * `saldo_atual` não existe no banco: é calculado por App\Services\SaldoService.
 */
class Conta extends Model
{
    use SoftDeletes;

    protected $table = 'contas';

    /** Saldo calculado em lote (SaldoService::anexarSaldos); só em memória, nunca persistido. */
    public ?string $saldoCalculado = null;

    protected $fillable = ['nome', 'tipo', 'saldo_inicial', 'ativa'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoConta::class,
            'saldo_inicial' => 'decimal:2', // sempre string, nunca float
            'ativa' => 'boolean',
        ];
    }
}
