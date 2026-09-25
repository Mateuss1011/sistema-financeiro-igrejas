<?php

namespace App\Services;

use App\Exceptions\RegraNegocioException;
use App\Models\Categoria;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CategoriaService
{
    public function __construct(private AuditoriaService $auditoria)
    {
    }

    public function criar(array $dados, User $ator): Categoria
    {
        return DB::transaction(function () use ($dados, $ator) {
            $categoria = Categoria::create($dados + ['ativa' => true]);

            $this->auditoria->registrar(
                acao: 'created',
                modulo: 'categorias',
                registroId: $categoria->id,
                dadosNovos: $this->snapshot($categoria),
                usuario: $ator,
            );

            return $categoria;
        });
    }

    /** Só nome e ativa podem mudar; o tipo é imutável (barrado também no Form Request). */
    public function atualizar(Categoria $categoria, array $dados, User $ator): Categoria
    {
        return DB::transaction(function () use ($categoria, $dados, $ator) {
            $antes = $this->snapshot($categoria);

            $categoria->fill(array_intersect_key($dados, array_flip(['nome', 'ativa'])));

            if (! $categoria->isDirty()) {
                return $categoria;
            }

            $mudouStatus = $categoria->isDirty('ativa');
            $categoria->save();

            $acao = $mudouStatus ? ($categoria->ativa ? 'activated' : 'deactivated') : 'updated';

            $this->auditoria->registrar(
                acao: $acao,
                modulo: 'categorias',
                registroId: $categoria->id,
                dadosAnteriores: $antes,
                dadosNovos: $this->snapshot($categoria),
                usuario: $ator,
            );

            return $categoria;
        });
    }

    public function excluir(Categoria $categoria, User $ator): void
    {
        DB::transaction(function () use ($categoria, $ator) {
            // Trava a linha antes de checar o uso: serializa com um lançamento simultâneo nesta categoria.
            Categoria::query()->whereKey($categoria->id)->lockForUpdate()->first();

            if ($this->estaEmUso($categoria)) {
                throw new RegraNegocioException(
                    'Categoria em uso não pode ser excluída; inative-a.',
                    'CATEGORIA_EM_USO'
                );
            }

            $antes = $this->snapshot($categoria);
            $categoria->delete();

            $this->auditoria->registrar(
                acao: 'deleted',
                modulo: 'categorias',
                registroId: $categoria->id,
                dadosAnteriores: $antes,
                usuario: $ator,
            );
        });
    }

    /**
     * Ponto único de verificação de uso: qualquer entrada ou despesa vinculada à categoria (inclusive Pendentes,
     * Canceladas e linhas de estorno). A FK ON DELETE RESTRICT é a camada extra de integridade.
     */
    public function estaEmUso(Categoria $categoria): bool
    {
        return Entrada::query()->where('categoria_id', $categoria->id)->exists()
            || Despesa::query()->where('categoria_id', $categoria->id)->exists();
    }

    private function snapshot(Categoria $categoria): array
    {
        return [
            'nome' => $categoria->nome,
            'tipo' => $categoria->tipo->value,
            'ativa' => $categoria->ativa,
        ];
    }
}
