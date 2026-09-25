<?php

namespace App\Services;

use App\Enums\StatusTransferencia;
use App\Enums\TipoConta;
use App\Exceptions\RegraNegocioException;
use App\Models\Conta;
use App\Models\Transferencia;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transferências entre contas. Todas as regras vivem aqui.
 *
 * ORDEM DE TRAVAS (obrigatória; estende a da Fase 7):
 *   1. o documento operado, quando existe (transferência a estornar) — SELECT ... FOR UPDATE;
 *   2. as DUAS contas, SEMPRE em ordem crescente de id (SaldoService::travarContas), de modo que
 *      A→B e B→A travam A e depois B e nunca se cruzam (sem deadlock);
 *   3. SÓ DEPOIS: leituras comuns — idempotência, estado das contas, período — e o cálculo do saldo.
 * No MariaDB (REPEATABLE READ) a primeira leitura comum da transação fixa o snapshot; por isso NENHUMA
 * consulta comum (nem a de idempotência) pode acontecer antes de travar as contas.
 *
 * A transferência é UMA linha: o saldo das duas contas é derivado dela, então a operação é atômica por
 * construção (não existe "debitou a origem e falhou o crédito no destino").
 */
class TransferenciaService
{
    public function __construct(
        private AuditoriaService $auditoria,
        private SaldoService $saldos,
        private PeriodoFinanceiroService $periodos,
    ) {
    }

    /**
     * `$dados` normalizado: conta_origem_id int, conta_destino_id int, valor "10.00",
     * data_transferencia "Y-m-d", descricao string|null.
     *
     * @return array{0: Transferencia, 1: bool} [transferência, replay]
     */
    public function criar(array $dados, bool $confirmarSaldoNegativo, User $ator, ?string $chaveIdempotencia = null): array
    {
        if ($dados['conta_origem_id'] === $dados['conta_destino_id']) {
            throw ValidationException::withMessages(['conta_destino_id' => ['A conta de destino deve ser diferente da conta de origem.']]);
        }

        $hash = $this->hashPayload($dados);

        try {
            return DB::transaction(function () use ($dados, $confirmarSaldoNegativo, $ator, $chaveIdempotencia, $hash) {
                // 2) contas, em ordem crescente de id, ANTES de qualquer leitura comum
                $contas = $this->saldos->travarContas([$dados['conta_origem_id'], $dados['conta_destino_id']]);

                // 3) leituras comuns, só depois das travas
                if ($chaveIdempotencia !== null && ($existente = $this->buscarPorChave($ator, $chaveIdempotencia))) {
                    return [$this->replay($existente, $hash), true];
                }

                $origem = $contas->get($dados['conta_origem_id']);
                $destino = $contas->get($dados['conta_destino_id']);
                if ($origem === null) {
                    throw ValidationException::withMessages(['conta_origem_id' => ['A conta de origem selecionada é inválida.']]);
                }
                if ($destino === null) {
                    throw ValidationException::withMessages(['conta_destino_id' => ['A conta de destino selecionada é inválida.']]);
                }

                $this->exigirContasAtivas($origem, $destino);
                $this->periodos->garantirAberto($dados['data_transferencia']);
                $negativo = $this->exigirSaldo($origem, $dados['valor'], $confirmarSaldoNegativo);

                $transferencia = Transferencia::create([
                    'conta_origem_id' => $origem->id,
                    'conta_destino_id' => $destino->id,
                    'valor' => $dados['valor'],
                    'data_transferencia' => $dados['data_transferencia'],
                    'descricao' => $dados['descricao'] ?? null,
                    'status' => StatusTransferencia::Confirmada->value,
                    'criado_por' => $ator->id,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'hash_payload' => $chaveIdempotencia !== null ? $hash : null,
                ]);
                $transferencia->refresh();

                // Sem a descrição livre: dado potencialmente pessoal não vai para o log imutável.
                $this->auditoria->registrar(
                    acao: 'created',
                    modulo: 'transferencias',
                    registroId: $transferencia->id,
                    dadosNovos: $this->snapshot($transferencia) + ['saldo_negativo_confirmado' => $negativo],
                    usuario: $ator,
                );

                return [$transferencia, false];
            });
        } catch (QueryException $e) {
            // Corrida: outra requisição com a mesma chave gravou primeiro; a transação foi desfeita.
            if ($chaveIdempotencia !== null && $this->violou($e, 'transferencias_idempotencia_unica')) {
                $existente = $this->buscarPorChave($ator, $chaveIdempotencia);
                if ($existente !== null) {
                    return [$this->replay($existente, $hash), true];
                }
            }

            throw $e;
        }
    }

    /**
     * Estorna uma transferência criando uma NOVA transferência em sentido inverso (destino → origem,
     * mesmo valor, MESMA data da original), vinculada a ela. A original passa a `estornada`.
     * Segue as regras normais de saldo: a conta que perde dinheiro no estorno é a destino original.
     */
    public function estornar(int $id, string $justificativa, bool $confirmarSaldoNegativo, User $ator): Transferencia
    {
        try {
            return DB::transaction(function () use ($id, $justificativa, $confirmarSaldoNegativo, $ator) {
                // 1) documento
                $original = Transferencia::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if ($original->ehEstorno()) {
                    throw new RegraNegocioException('Uma transferência de estorno não pode ser estornada.', 'TRANSFERENCIA_NAO_ESTORNAVEL');
                }
                if ($original->status === StatusTransferencia::Estornada) {
                    throw new RegraNegocioException('Esta transferência já foi estornada.', 'TRANSFERENCIA_JA_ESTORNADA');
                }

                // 2) as duas contas, em ordem crescente de id
                $contas = $this->saldos->travarContas([$original->conta_origem_id, $original->conta_destino_id]);
                $origemOriginal = $contas->get($original->conta_origem_id);
                $destinoOriginal = $contas->get($original->conta_destino_id);
                if ($origemOriginal === null || $destinoOriginal === null) {
                    throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
                }

                // 3) leituras comuns depois das travas
                $this->exigirContasAtivas($origemOriginal, $destinoOriginal);
                $this->periodos->garantirAberto($original->data_transferencia);
                // No estorno o dinheiro volta: quem perde é a destino da original.
                $negativo = $this->exigirSaldo($destinoOriginal, (string) $original->valor, $confirmarSaldoNegativo);

                $estorno = Transferencia::create([
                    'conta_origem_id' => $original->conta_destino_id,
                    'conta_destino_id' => $original->conta_origem_id,
                    'valor' => $original->valor,
                    'data_transferencia' => $original->data_transferencia->format('Y-m-d'),
                    'status' => StatusTransferencia::Confirmada->value,
                    'transferencia_estornada_id' => $original->id,
                    'motivo_estorno' => $justificativa,
                    'criado_por' => $ator->id,
                ]);
                $estorno->refresh();

                $original->status = StatusTransferencia::Estornada;
                $original->save();

                $this->auditoria->registrar(
                    acao: 'reversed',
                    modulo: 'transferencias',
                    registroId: $original->id,
                    dadosAnteriores: ['status' => StatusTransferencia::Confirmada->value],
                    dadosNovos: $this->snapshot($original) + ['estorno_id' => $estorno->id, 'saldo_negativo_confirmado' => $negativo],
                    justificativa: $justificativa,
                    usuario: $ator,
                );

                return $estorno;
            });
        } catch (QueryException $e) {
            // Rede de segurança do UNIQUE(transferencia_estornada_id): normalmente barrado pela trava acima.
            if ($this->violou($e, 'transferencias_estorno_unico')) {
                throw new RegraNegocioException('Esta transferência já foi estornada.', 'TRANSFERENCIA_JA_ESTORNADA');
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------ apoio

    private function exigirContasAtivas(Conta $origem, Conta $destino): void
    {
        if (! $origem->ativa || ! $destino->ativa) {
            throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
        }
    }

    /**
     * A conta que PERDE dinheiro (origem) não pode ficar negativa se for caixa (409 SALDO_INSUFICIENTE);
     * se for banco e ficar negativa, exige confirmação explícita. A destino só ganha: nunca é checada.
     * Deve ser chamado com a conta já travada. Retorna se o saldo resultante ficou negativo.
     */
    private function exigirSaldo(Conta $conta, string $valor, bool $confirmar): bool
    {
        $novoSaldo = bcsub($this->saldos->saldoAtual($conta), $valor, 2);
        $negativo = bccomp($novoSaldo, '0', 2) < 0;

        if ($negativo && $conta->tipo === TipoConta::Caixa) {
            throw new RegraNegocioException('Saldo insuficiente no caixa de origem para esta transferência.', 'SALDO_INSUFICIENTE');
        }
        if ($negativo && ! $confirmar) {
            throw new RegraNegocioException(
                'Esta operação deixará o saldo da conta bancária negativo. Confirme para prosseguir.',
                'SALDO_NEGATIVO_REQUER_CONFIRMACAO'
            );
        }

        return $negativo;
    }

    private function buscarPorChave(User $ator, string $chave): ?Transferencia
    {
        return Transferencia::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
    }

    private function replay(Transferencia $existente, string $hash): Transferencia
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
            (int) $dados['conta_origem_id'],
            (int) $dados['conta_destino_id'],
            $dados['valor'],
            $dados['data_transferencia'],
            $dados['descricao'] ?? null,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function violou(QueryException $e, string $indice): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), $indice);
    }

    /** Campos auditados (sem a descrição livre). */
    private function snapshot(Transferencia $transferencia): array
    {
        return [
            'conta_origem_id' => $transferencia->conta_origem_id,
            'conta_destino_id' => $transferencia->conta_destino_id,
            'valor' => (string) $transferencia->valor,
            'data_transferencia' => $transferencia->data_transferencia->format('Y-m-d'),
            'status' => $transferencia->status->value,
        ];
    }
}
