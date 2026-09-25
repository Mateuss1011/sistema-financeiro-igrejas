<?php

namespace App\Services;

use App\Enums\SentidoAjuste;
use App\Enums\TipoConta;
use App\Exceptions\RegraNegocioException;
use App\Models\AjusteSaldo;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de saldo (imutáveis; correção por outro ajuste de sentido oposto).
 *
 * Mesma disciplina de travas das demais operações que validam saldo: a conta é travada (FOR UPDATE)
 * ANTES de qualquer leitura comum — inclusive a consulta de idempotência —, para que o saldo lido
 * depois da trava nunca venha de um snapshot antigo do REPEATABLE READ.
 */
class AjusteSaldoService
{
    public function __construct(
        private AuditoriaService $auditoria,
        private SaldoService $saldos,
        private PeriodoFinanceiroService $periodos,
    ) {
    }

    /**
     * `$dados` normalizado: conta_id int, valor "10.00", sentido credito|debito,
     * data_ajuste "Y-m-d", justificativa string.
     *
     * @return array{0: AjusteSaldo, 1: bool} [ajuste, replay]
     */
    public function criar(array $dados, bool $confirmarSaldoNegativo, User $ator, ?string $chaveIdempotencia = null): array
    {
        $hash = $this->hashPayload($dados);

        try {
            return DB::transaction(function () use ($dados, $confirmarSaldoNegativo, $ator, $chaveIdempotencia, $hash) {
                // 2) conta travada ANTES de qualquer leitura comum
                $conta = $this->saldos->travarConta($dados['conta_id']);

                // 3) leituras comuns, só depois da trava
                if ($chaveIdempotencia !== null && ($existente = $this->buscarPorChave($ator, $chaveIdempotencia))) {
                    return [$this->replay($existente, $hash), true];
                }

                if ($conta === null) {
                    throw ValidationException::withMessages(['conta_id' => ['A conta selecionada é inválida.']]);
                }
                if (! $conta->ativa) {
                    throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
                }

                $this->periodos->garantirAberto($dados['data_ajuste']);

                $negativo = false;
                if ($dados['sentido'] === SentidoAjuste::Debito->value) {
                    // Débito reduz o saldo: caixa nunca fica negativo; banco negativo exige confirmação.
                    $novoSaldo = bcsub($this->saldos->saldoAtual($conta), $dados['valor'], 2);
                    $negativo = bccomp($novoSaldo, '0', 2) < 0;

                    if ($negativo && $conta->tipo === TipoConta::Caixa) {
                        throw new RegraNegocioException('Saldo insuficiente no caixa para este ajuste de débito.', 'SALDO_INSUFICIENTE');
                    }
                    if ($negativo && ! $confirmarSaldoNegativo) {
                        throw new RegraNegocioException(
                            'Este ajuste deixará o saldo da conta bancária negativo. Confirme para prosseguir.',
                            'SALDO_NEGATIVO_REQUER_CONFIRMACAO'
                        );
                    }
                }

                $ajuste = AjusteSaldo::create([
                    'conta_id' => $conta->id,
                    'valor' => $dados['valor'],
                    'sentido' => $dados['sentido'],
                    'data_ajuste' => $dados['data_ajuste'],
                    'justificativa' => $dados['justificativa'],
                    'criado_por' => $ator->id,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'hash_payload' => $chaveIdempotencia !== null ? $hash : null,
                ]);
                $ajuste->refresh();

                $this->auditoria->registrar(
                    acao: 'created',
                    modulo: 'ajustes_saldo',
                    registroId: $ajuste->id,
                    dadosNovos: [
                        'conta_id' => $ajuste->conta_id,
                        'valor' => (string) $ajuste->valor,
                        'sentido' => $ajuste->sentido->value,
                        'data_ajuste' => $ajuste->data_ajuste->format('Y-m-d'),
                        'saldo_negativo_confirmado' => $negativo,
                    ],
                    justificativa: $ajuste->justificativa,
                    usuario: $ator,
                );

                return [$ajuste, false];
            });
        } catch (QueryException $e) {
            if ($chaveIdempotencia !== null && ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'ajustes_idempotencia_unica')) {
                $existente = $this->buscarPorChave($ator, $chaveIdempotencia);
                if ($existente !== null) {
                    return [$this->replay($existente, $hash), true];
                }
            }

            throw $e;
        }
    }

    private function buscarPorChave(User $ator, string $chave): ?AjusteSaldo
    {
        return AjusteSaldo::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
    }

    private function replay(AjusteSaldo $existente, string $hash): AjusteSaldo
    {
        if ($existente->hash_payload !== $hash) {
            throw new RegraNegocioException(
                'Esta chave de idempotência já foi usada com dados diferentes.',
                'IDEMPOTENCY_KEY_REUTILIZADA'
            );
        }

        return $existente;
    }

    private function hashPayload(array $dados): string
    {
        return hash('sha256', json_encode([
            (int) $dados['conta_id'],
            $dados['valor'],
            $dados['sentido'],
            $dados['data_ajuste'],
            $dados['justificativa'],
        ], JSON_UNESCAPED_UNICODE));
    }
}
