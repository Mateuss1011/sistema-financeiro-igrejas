<?php

namespace App\Services;

use App\Exceptions\RegraNegocioException;
use App\Models\AjusteSaldo;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\Transferencia;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Support\Facades\DB;

class ContaService
{
    public function __construct(private AuditoriaService $auditoria)
    {
    }

    /** O saldo inicial é definido aqui, uma única vez, e auditado neste evento. */
    public function criar(array $dados, User $ator): Conta
    {
        return DB::transaction(function () use ($dados, $ator) {
            $dados['saldo_inicial'] = Dinheiro::normalizar($dados['saldo_inicial'] ?? '0') ?? '0.00';

            $conta = Conta::create($dados + ['ativa' => true]);
            $conta->refresh();

            $this->auditoria->registrar(
                acao: 'created',
                modulo: 'contas',
                registroId: $conta->id,
                dadosNovos: $this->snapshot($conta),
                usuario: $ator,
            );

            return $conta;
        });
    }

    /** Só nome e ativa podem mudar; tipo e saldo_inicial são imutáveis (barrados no Form Request). */
    public function atualizar(Conta $conta, array $dados, User $ator): Conta
    {
        return DB::transaction(function () use ($conta, $dados, $ator) {
            $antes = $this->snapshot($conta);

            $conta->fill(array_intersect_key($dados, array_flip(['nome', 'ativa'])));

            if (! $conta->isDirty()) {
                return $conta;
            }

            $mudouStatus = $conta->isDirty('ativa');
            $conta->save();

            $acao = $mudouStatus ? ($conta->ativa ? 'activated' : 'deactivated') : 'updated';

            $this->auditoria->registrar(
                acao: $acao,
                modulo: 'contas',
                registroId: $conta->id,
                dadosAnteriores: $antes,
                dadosNovos: $this->snapshot($conta),
                usuario: $ator,
            );

            return $conta;
        });
    }

    /** Exclusão lógica (soft delete). Conta em uso nunca é excluída — deve ser inativada. */
    public function excluir(Conta $conta, User $ator): void
    {
        DB::transaction(function () use ($conta, $ator) {
            // Trava a linha antes de checar o uso: serializa com um lançamento simultâneo nesta conta.
            Conta::query()->whereKey($conta->id)->lockForUpdate()->first();

            if ($this->estaEmUso($conta)) {
                throw new RegraNegocioException(
                    'Conta com movimentações não pode ser excluída; inative-a.',
                    'CONTA_EM_USO'
                );
            }

            $antes = $this->snapshot($conta);
            $conta->delete();

            $this->auditoria->registrar(
                acao: 'deleted',
                modulo: 'contas',
                registroId: $conta->id,
                dadosAnteriores: $antes,
                usuario: $ator,
            );
        });
    }

    /**
     * Ponto ÚNICO de verificação de uso: qualquer entrada ou despesa vinculada à conta (inclusive estornos;
     * despesas só têm conta depois de pagas). Inclui também transferências (como
     * origem OU destino, inclusive estornos) e ajustes de saldo. As FKs ON DELETE RESTRICT protegem apenas exclusão física;
     * o soft delete depende deste método.
     */
    public function estaEmUso(Conta $conta): bool
    {
        return Entrada::query()->where('conta_id', $conta->id)->exists()
            || Despesa::query()->where('conta_id', $conta->id)->exists()
            || Transferencia::query()->where('conta_origem_id', $conta->id)->orWhere('conta_destino_id', $conta->id)->exists()
            || AjusteSaldo::query()->where('conta_id', $conta->id)->exists();
    }

    private function snapshot(Conta $conta): array
    {
        return [
            'nome' => $conta->nome,
            'tipo' => $conta->tipo->value,
            'saldo_inicial' => $conta->saldo_inicial,
            'ativa' => $conta->ativa,
        ];
    }
}
