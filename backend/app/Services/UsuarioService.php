<?php

namespace App\Services;

use App\Enums\PerfilSlug;
use App\Exceptions\RegraNegocioException;
use App\Models\PermissaoExcecao;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    public function __construct(private AuditoriaService $auditoria)
    {
    }

    public function criar(array $dados, User $ator): User
    {
        return DB::transaction(function () use ($dados, $ator) {
            $usuario = $this->gravarComEmailUnico(fn () => User::create($dados));

            $this->auditoria->registrar(
                acao: 'created',
                modulo: 'usuarios',
                registroId: $usuario->id,
                dadosNovos: $this->snapshot($usuario),
                usuario: $ator,
            );

            return $usuario;
        });
    }

    public function atualizar(User $alvo, array $dados, User $ator): User
    {
        return DB::transaction(function () use ($alvo, $dados, $ator) {
            // Serializa contra qualquer outra mudança que possa tirar um Pastor de "ativo" e RELÊ o alvo já sob a trava.
            $pastoresAtivos = $this->travarPastoresAtivos();
            $alvo = $this->recarregarTravado($alvo);
            $antes = $this->snapshot($alvo);

            $vaiDeixarDeSerPastor = array_key_exists('perfil_id', $dados)
                && $alvo->ehPerfil(PerfilSlug::Pastor)
                && $alvo->perfil_id !== (int) $dados['perfil_id'];

            $mudaStatus = array_key_exists('ativo', $dados)
                && (bool) $dados['ativo'] !== $alvo->ativo;
            $vaiDesativarPastor = $mudaStatus
                && ! (bool) $dados['ativo']
                && $alvo->ehPerfil(PerfilSlug::Pastor);

            if ($vaiDeixarDeSerPastor || $vaiDesativarPastor) {
                $this->garantirQueNaoEhUltimoPastorAtivo($alvo, $pastoresAtivos);
            }

            $alvo->fill($dados);
            $this->gravarComEmailUnico(fn () => $alvo->save());

            $acao = $mudaStatus
                ? ((bool) $dados['ativo'] ? 'activated' : 'deactivated')
                : 'updated';

            $this->auditoria->registrar(
                acao: $acao,
                modulo: 'usuarios',
                registroId: $alvo->id,
                dadosAnteriores: $antes,
                dadosNovos: $this->snapshot($alvo),
                usuario: $ator,
            );

            return $alvo->refresh();
        });
    }

    public function desativar(User $alvo, User $ator): User
    {
        return DB::transaction(function () use ($alvo, $ator) {
            $pastoresAtivos = $this->travarPastoresAtivos();
            $alvo = $this->recarregarTravado($alvo);

            if ($alvo->ehPerfil(PerfilSlug::Pastor) && $alvo->ativo) {
                $this->garantirQueNaoEhUltimoPastorAtivo($alvo, $pastoresAtivos);
            }

            $antes = $this->snapshot($alvo);
            $alvo->ativo = false;
            $alvo->save();

            $this->auditoria->registrar(
                acao: 'deactivated',
                modulo: 'usuarios',
                registroId: $alvo->id,
                dadosAnteriores: $antes,
                dadosNovos: $this->snapshot($alvo),
                usuario: $ator,
            );

            return $alvo->refresh();
        });
    }

    public function concederExcecao(User $alvo, string $permissao, User $pastor): PermissaoExcecao
    {
        return DB::transaction(function () use ($alvo, $permissao, $pastor) {
            $excecao = PermissaoExcecao::firstOrCreate(
                ['user_id' => $alvo->id, 'permissao' => $permissao],
                ['concedida_por' => $pastor->id]
            );

            $this->auditoria->registrar(
                acao: 'permission_granted',
                modulo: 'usuarios',
                registroId: $alvo->id,
                dadosNovos: ['permissao' => $permissao],
                usuario: $pastor,
            );

            return $excecao;
        });
    }

    public function revogarExcecao(User $alvo, string $permissao, User $pastor): void
    {
        DB::transaction(function () use ($alvo, $permissao, $pastor) {
            PermissaoExcecao::where('user_id', $alvo->id)
                ->where('permissao', $permissao)
                ->delete();

            $this->auditoria->registrar(
                acao: 'permission_revoked',
                modulo: 'usuarios',
                registroId: $alvo->id,
                dadosAnteriores: ['permissao' => $permissao],
                usuario: $pastor,
            );
        });
    }

    /**
     * Trava (FOR UPDATE, sempre em ordem de id → sem deadlock entre elas) as linhas de todos os Pastores ativos. Sem isto,
     * dois Pastores que se desativam/rebaixam ao mesmo tempo enxergavam, cada um, "o outro ainda está ativo" e ambos
     * passavam pela regra do último Pastor, deixando o sistema sem nenhum (ou explodiam em deadlock → 500).
     */
    private function travarPastoresAtivos(): Collection
    {
        return User::pastoresAtivos()->orderBy('users.id')->lockForUpdate()->pluck('users.id');
    }

    /**
     * Leitura ATUAL (com trava) do alvo. Não serve um `refresh()`: a subconsulta em `perfis` do lock acima é uma leitura
     * consistente que fixa o snapshot da transação ANTES de esperar a trava, então qualquer SELECT comum depois dela
     * enxergaria o estado antigo — a leitura sob `FOR UPDATE` é a única que vê o que a outra transação acabou de gravar.
     */
    private function recarregarTravado(User $alvo): User
    {
        return User::query()->lockForUpdate()->findOrFail($alvo->id);
    }

    /** Dois cadastros/edições simultâneos com o mesmo e-mail: o índice único barra o segundo, que vira recusa de validação. */
    private function gravarComEmailUnico(callable $gravacao): mixed
    {
        try {
            return $gravacao();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }
    }

    /** @param  Collection<int, int>  $pastoresAtivos  ids dos Pastores ativos lidos SOB TRAVA (estado atual, não snapshot). */
    private function garantirQueNaoEhUltimoPastorAtivo(User $alvo, Collection $pastoresAtivos): void
    {
        if ($pastoresAtivos->reject(fn ($id) => (int) $id === $alvo->id)->isEmpty()) {
            throw new RegraNegocioException(
                'Não é possível remover o último Pastor ativo do sistema.',
                'ULTIMO_PASTOR_ATIVO'
            );
        }
    }

    private function snapshot(User $usuario): array
    {
        return $usuario->only(['name', 'email', 'perfil_id', 'ativo']);
    }
}
