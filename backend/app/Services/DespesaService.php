<?php

namespace App\Services;

use App\Enums\PerfilSlug;
use App\Enums\StatusDespesa;
use App\Enums\TipoCategoria;
use App\Enums\TipoConta;
use App\Exceptions\RegraNegocioException;
use App\Models\Categoria;
use App\Models\Despesa;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Todas as regras de despesas vivem aqui (o Controller só orquestra).
 *
 * ORDEM DE TRAVAS (obrigatória, para evitar deadlock e snapshot antigo no REPEATABLE READ do MariaDB):
 *   1. a linha do documento operado (despesa) — SELECT ... FOR UPDATE;
 *   2. as linhas de conta, em ordem crescente de id;
 *   3. a linha de categoria, quando a operação a valida;
 *   4. SÓ DEPOIS: leituras comuns (períodos, saldo). A primeira leitura não bloqueante de uma
 *      transação fixa o snapshot; lê-la antes da trava da conta poderia enxergar um saldo velho.
 */
class DespesaService
{
    private const JANELA_EXCLUSAO_HORAS = 48;

    public function __construct(
        private AuditoriaService $auditoria,
        private SaldoService $saldos,
        private PeriodoFinanceiroService $periodos,
    ) {
    }

    // ------------------------------------------------------------------ criar

    /**
     * Cria uma despesa PENDENTE (nunca recebe conta nem status do cliente).
     * `$dados` já normalizado: categoria_id int, valor "10.00", data_competencia "Y-m-d",
     * descricao string, fornecedor_nome string|null.
     *
     * Idempotência: chave + hash imutável do payload da criação. O hash (e não o estado atual da
     * despesa) é comparado no replay, porque a Pendente pode ser editada depois.
     *
     * @return array{0: Despesa, 1: bool} [despesa, replay]
     */
    public function criar(array $dados, User $ator, ?string $chaveIdempotencia = null): array
    {
        $hash = $this->hashPayload($dados);

        try {
            return DB::transaction(function () use ($dados, $ator, $chaveIdempotencia, $hash) {
                if ($chaveIdempotencia !== null && ($existente = $this->buscarPorChave($ator, $chaveIdempotencia))) {
                    return [$this->replayCriacao($existente, $hash), true];
                }

                $this->validarCategoria($dados['categoria_id']);
                $this->periodos->garantirAberto($dados['data_competencia']);

                $despesa = Despesa::create([
                    'categoria_id' => $dados['categoria_id'],
                    'valor' => $dados['valor'],
                    'data_competencia' => $dados['data_competencia'],
                    'descricao' => $dados['descricao'],
                    'fornecedor_nome' => $dados['fornecedor_nome'] ?? null,
                    'status' => StatusDespesa::Pendente->value,
                    'criado_por' => $ator->id,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'hash_payload' => $chaveIdempotencia !== null ? $hash : null,
                ]);
                $despesa->refresh();

                // Sem descricao/fornecedor_nome: dado pessoal não vai para o log imutável.
                $this->auditoria->registrar(
                    acao: 'created',
                    modulo: 'despesas',
                    registroId: $despesa->id,
                    dadosNovos: $this->snapshot($despesa),
                    usuario: $ator,
                );

                return [$despesa, false];
            });
        } catch (QueryException $e) {
            // Corrida: outra requisição com a mesma chave gravou primeiro; a transação foi desfeita.
            if ($chaveIdempotencia !== null && $this->violou($e, 'despesas_idempotencia_unica')) {
                $existente = $this->buscarPorChave($ator, $chaveIdempotencia);
                if ($existente !== null) {
                    return [$this->replayCriacao($existente, $hash), true];
                }
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------ editar

    /**
     * Edita uma Pendente (categoria, valor, competência, descrição, fornecedor). Sem mudança real:
     * não persiste e não audita. `$dados` traz só os campos enviados, já normalizados.
     */
    public function atualizar(int $id, array $dados, User $ator): Despesa
    {
        return DB::transaction(function () use ($id, $dados, $ator) {
            $despesa = $this->travarDespesa($id);
            $this->exigirPendente($despesa);

            $antes = $this->camposEditaveis($despesa);
            $mudancas = [];
            foreach (array_intersect_key($dados, $antes) as $campo => $novo) {
                if (! $this->mesmoValor($campo, $antes[$campo], $novo)) {
                    $mudancas[$campo] = $novo;
                }
            }

            if ($mudancas === []) {
                return $despesa;
            }

            if (isset($mudancas['categoria_id'])) {
                // Inativa bloqueia ATRIBUIR a categoria; manter a atual (mesmo inativa) não bloqueia.
                $this->validarCategoria($mudancas['categoria_id']);
            }

            // Fase 9: se a competência mudar, duas datas (potencialmente dois meses) precisam estar
            // abertas na MESMA transação — garantirAbertoTodos trava os períodos distintos em ordem
            // crescente de ano_mes, para nunca cruzar com outra operação que também precise de dois.
            $datasParaChecar = [$despesa->data_competencia];
            if (isset($mudancas['data_competencia'])) {
                $datasParaChecar[] = $mudancas['data_competencia'];
            }
            $this->periodos->garantirAbertoTodos($datasParaChecar);

            $anteriores = $despesa->only(['categoria_id', 'valor']) + ['data_competencia' => $despesa->data_competencia->format('Y-m-d')];
            $despesa->fill($mudancas);
            $despesa->atualizado_por = $ator->id;
            $despesa->save();
            $despesa->refresh();

            $naoPessoais = ['categoria_id', 'valor', 'data_competencia'];
            $this->auditoria->registrar(
                acao: 'updated',
                modulo: 'despesas',
                registroId: $despesa->id,
                dadosAnteriores: array_intersect_key($anteriores, array_flip(array_keys($mudancas))),
                dadosNovos: [
                    // Só os NOMES dos campos alterados + valores não pessoais.
                    'campos_alterados' => array_keys($mudancas),
                ] + array_intersect_key($this->snapshot($despesa), array_flip($naoPessoais)),
                usuario: $ator,
            );

            return $despesa;
        });
    }

    // ------------------------------------------------------------------ pagar

    /**
     * Paga uma Pendente: exige conta e data de pagamento; reduz o saldo da conta.
     * Se já estiver Paga pelo mesmo usuário, mesma conta e mesma data, é tratado como replay.
     *
     * @param  array{conta_id: int, data_pagamento: string}  $dados
     * @return array{0: Despesa, 1: bool} [despesa, replay]
     */
    public function pagar(int $id, array $dados, bool $confirmarSaldoNegativo, User $ator): array
    {
        return DB::transaction(function () use ($id, $dados, $confirmarSaldoNegativo, $ator) {
            // 1) documento
            $despesa = $this->travarDespesa($id);

            if ($this->ehReplayDePagamento($despesa, $dados, $ator)) {
                return [$despesa, true];
            }
            $this->exigirPendente($despesa);

            // 2) conta
            $conta = $this->saldos->travarConta($dados['conta_id']);
            if ($conta === null) {
                throw ValidationException::withMessages(['conta_id' => ['A conta selecionada é inválida.']]);
            }
            if (! $conta->ativa) {
                throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
            }

            // 4) leituras comuns, só depois das travas. Competência e pagamento podem cair em meses
            // diferentes: garantirAbertoTodos trava os dois em ordem crescente (ver Fase 9).
            $this->periodos->garantirAbertoTodos([$despesa->data_competencia, $dados['data_pagamento']]);

            $novoSaldo = bcsub($this->saldos->saldoAtual($conta), (string) $despesa->valor, 2);
            $ficaNegativo = bccomp($novoSaldo, '0', 2) < 0;

            if ($ficaNegativo && $conta->tipo === TipoConta::Caixa) {
                throw new RegraNegocioException('Saldo insuficiente no caixa para pagar esta despesa.', 'SALDO_INSUFICIENTE');
            }
            if ($ficaNegativo && ! $confirmarSaldoNegativo) {
                throw new RegraNegocioException(
                    'Este pagamento deixará o saldo da conta negativo. Confirme para prosseguir.',
                    'SALDO_NEGATIVO_REQUER_CONFIRMACAO'
                );
            }

            $despesa->fill([
                'status' => StatusDespesa::Paga->value,
                'conta_id' => $conta->id,
                'data_pagamento' => $dados['data_pagamento'],
                'pago_por' => $ator->id,
                'pago_em' => now(),
                'atualizado_por' => $ator->id,
            ]);
            $despesa->save();
            $despesa->refresh();

            $this->auditoria->registrar(
                acao: 'paid',
                modulo: 'despesas',
                registroId: $despesa->id,
                dadosAnteriores: ['status' => StatusDespesa::Pendente->value],
                dadosNovos: $this->snapshot($despesa) + ['saldo_negativo_confirmado' => $ficaNegativo],
                usuario: $ator,
            );

            return [$despesa, false];
        });
    }

    // ------------------------------------------------------------------ cancelar

    /** Cancela uma Pendente (terminal, sem efeito no saldo). */
    public function cancelar(int $id, string $justificativa, User $ator): Despesa
    {
        return DB::transaction(function () use ($id, $justificativa, $ator) {
            $despesa = $this->travarDespesa($id);
            $this->exigirPendente($despesa);
            $this->periodos->garantirAberto($despesa->data_competencia);

            $despesa->fill([
                'status' => StatusDespesa::Cancelada->value,
                'motivo_cancelamento' => $justificativa,
                'atualizado_por' => $ator->id,
            ]);
            $despesa->save();
            $despesa->refresh();

            $this->auditoria->registrar(
                acao: 'canceled',
                modulo: 'despesas',
                registroId: $despesa->id,
                dadosAnteriores: ['status' => StatusDespesa::Pendente->value],
                dadosNovos: $this->snapshot($despesa),
                justificativa: $justificativa,
                usuario: $ator,
            );

            return $despesa;
        });
    }

    // ------------------------------------------------------------------ estornar

    /**
     * Estorna uma Paga: cria a linha de estorno (status 'paga', valor positivo, vinculada à original,
     * sem copiar descrição/fornecedor) e marca a original como Estornada. Devolve o valor ao saldo.
     */
    public function estornar(int $id, string $justificativa, User $ator): Despesa
    {
        try {
            return DB::transaction(function () use ($id, $justificativa, $ator) {
                // 1) documento
                $original = $this->travarDespesa($id);

                if ($original->ehEstorno()) {
                    throw new RegraNegocioException('Um estorno não pode ser estornado.', 'ESTORNO_NAO_ESTORNAVEL');
                }
                if ($original->status === StatusDespesa::Estornada) {
                    throw new RegraNegocioException('Esta despesa já foi estornada.', 'DESPESA_JA_ESTORNADA');
                }
                if ($original->status !== StatusDespesa::Paga) {
                    throw new RegraNegocioException('Só é possível estornar uma despesa paga.', 'DESPESA_NAO_PAGA');
                }

                // 2) conta (o estorno movimenta a conta: precisa estar ativa)
                $conta = $this->saldos->travarConta($original->conta_id);
                if ($conta === null || ! $conta->ativa) {
                    throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
                }

                // 4) leituras comuns depois das travas
                $this->periodos->garantirAberto($original->data_competencia);

                $estorno = Despesa::create([
                    'categoria_id' => $original->categoria_id,
                    'conta_id' => $original->conta_id,
                    'valor' => $original->valor,
                    'data_competencia' => $original->data_competencia->format('Y-m-d'),
                    'data_pagamento' => $original->data_pagamento->format('Y-m-d'),
                    'status' => StatusDespesa::Paga->value,
                    'despesa_estornada_id' => $original->id,
                    'motivo_estorno' => $justificativa,
                    'criado_por' => $ator->id,
                ]);
                $estorno->refresh();

                $original->status = StatusDespesa::Estornada;
                $original->atualizado_por = $ator->id;
                $original->save();

                $this->auditoria->registrar(
                    acao: 'reversed',
                    modulo: 'despesas',
                    registroId: $original->id,
                    dadosAnteriores: ['status' => StatusDespesa::Paga->value],
                    dadosNovos: $this->snapshot($original) + ['estorno_id' => $estorno->id],
                    justificativa: $justificativa,
                    usuario: $ator,
                );

                return $estorno;
            });
        } catch (QueryException $e) {
            // Rede de segurança do UNIQUE(despesa_estornada_id): normalmente barrado pela trava acima.
            if ($this->violou($e, 'despesas_estorno_unico')) {
                throw new RegraNegocioException('Esta despesa já foi estornada.', 'DESPESA_JA_ESTORNADA');
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------ excluir

    /**
     * Exclusão FÍSICA, só de Pendente. Regra normal: criada pelo próprio usuário, até 48h, período
     * aberto. O Pastor pode ignorar apenas "outro criador" e "janela de 48h" (auditado); nunca o
     * status diferente de Pendente nem o período fechado. A autorização por perfil/dono está na Policy.
     */
    public function excluir(int $id, User $ator): void
    {
        DB::transaction(function () use ($id, $ator) {
            $despesa = $this->travarDespesa($id);
            $this->exigirPendente($despesa);
            $this->periodos->garantirAberto($despesa->data_competencia);

            $ignoradas = [];
            if ($despesa->criado_por !== $ator->id) {
                $ignoradas[] = 'outro_criador';
            }
            if (now()->greaterThan($despesa->created_at->copy()->addHours(self::JANELA_EXCLUSAO_HORAS))) {
                $ignoradas[] = 'janela_48h_expirada';
            }

            $ehPastor = $ator->ehPerfil(PerfilSlug::Pastor);
            if (! $ehPastor && in_array('outro_criador', $ignoradas, true)) {
                throw new AuthorizationException('Você só pode excluir as suas próprias despesas.');
            }
            if (! $ehPastor && $ignoradas !== []) {
                throw new RegraNegocioException(
                    'A janela de 48 horas para excluir esta despesa expirou; cancele-a.',
                    'JANELA_EXCLUSAO_EXPIRADA'
                );
            }

            $snapshot = $this->snapshot($despesa);
            $despesa->delete();

            $this->auditoria->registrar(
                acao: 'deleted',
                modulo: 'despesas',
                registroId: $despesa->id,
                dadosAnteriores: $snapshot, // sem descricao/fornecedor_nome
                dadosNovos: ['excecao_pastor' => $ignoradas !== [], 'condicoes_ignoradas' => $ignoradas],
                usuario: $ator,
            );
        });
    }

    // ------------------------------------------------------------------ apoio

    private function travarDespesa(int $id): Despesa
    {
        return Despesa::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function exigirPendente(Despesa $despesa): void
    {
        if ($despesa->ehEstorno() || $despesa->status !== StatusDespesa::Pendente) {
            throw new RegraNegocioException('Só uma despesa Pendente pode sofrer esta operação.', 'DESPESA_NAO_PENDENTE');
        }
    }

    /** Categoria travada (FOR UPDATE): precisa existir, ser de despesa (422) e estar ativa (409). */
    private function validarCategoria(int $categoriaId): void
    {
        $categoria = Categoria::query()->whereKey($categoriaId)->lockForUpdate()->first();

        if ($categoria === null || $categoria->tipo !== TipoCategoria::Despesa) {
            throw ValidationException::withMessages(['categoria_id' => ['A categoria selecionada é inválida para despesas.']]);
        }
        if (! $categoria->ativa) {
            throw new RegraNegocioException('Categoria inativa não pode ser usada em novos lançamentos.', 'CATEGORIA_INATIVA');
        }
    }

    private function ehReplayDePagamento(Despesa $despesa, array $dados, User $ator): bool
    {
        return ! $despesa->ehEstorno()
            && $despesa->status === StatusDespesa::Paga
            && $despesa->pago_por === $ator->id
            && (int) $despesa->conta_id === (int) $dados['conta_id']
            && $despesa->data_pagamento->format('Y-m-d') === $dados['data_pagamento'];
    }

    private function buscarPorChave(User $ator, string $chave): ?Despesa
    {
        return Despesa::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
    }

    private function replayCriacao(Despesa $existente, string $hash): Despesa
    {
        if ($existente->hash_payload !== $hash) {
            throw new RegraNegocioException(
                'Esta chave de idempotência já foi usada com dados diferentes.',
                'IDEMPOTENCY_KEY_REUTILIZADA'
            );
        }

        return $existente;
    }

    /** sha256 do payload normalizado da criação (ordem fixa de campos). */
    private function hashPayload(array $dados): string
    {
        return hash('sha256', json_encode([
            (int) $dados['categoria_id'],
            $dados['valor'],
            $dados['data_competencia'],
            $dados['descricao'],
            $dados['fornecedor_nome'] ?? null,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function camposEditaveis(Despesa $despesa): array
    {
        return [
            'categoria_id' => (int) $despesa->categoria_id,
            'valor' => (string) $despesa->valor,
            'data_competencia' => $despesa->data_competencia->format('Y-m-d'),
            'descricao' => $despesa->descricao,
            'fornecedor_nome' => $despesa->fornecedor_nome,
        ];
    }

    private function mesmoValor(string $campo, mixed $atual, mixed $novo): bool
    {
        return $campo === 'valor' ? bccomp((string) $atual, (string) $novo, 2) === 0 : $atual === $novo;
    }

    private function violou(QueryException $e, string $indice): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), $indice);
    }

    /** Campos auditados (sem dados pessoais). */
    private function snapshot(Despesa $despesa): array
    {
        return [
            'categoria_id' => $despesa->categoria_id,
            'conta_id' => $despesa->conta_id,
            'valor' => (string) $despesa->valor,
            'data_competencia' => $despesa->data_competencia->format('Y-m-d'),
            'data_pagamento' => $despesa->data_pagamento?->format('Y-m-d'),
            'status' => $despesa->status->value,
        ];
    }
}
