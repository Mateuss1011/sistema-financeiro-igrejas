<?php

namespace App\Services;

use App\Enums\StatusEntrada;
use App\Enums\TipoCategoria;
use App\Enums\TipoConta;
use App\Exceptions\RegraNegocioException;
use App\Models\Categoria;
use App\Models\Entrada;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntradaService
{
    public function __construct(
        private AuditoriaService $auditoria,
        private SaldoService $saldos,
        private PeriodoFinanceiroService $periodos,
    ) {
    }

    /**
     * Registra uma entrada. `$dados` já vem normalizado (ids int, valor "10.00", data "Y-m-d",
     * descricao/contribuinte_nome string|null).
     *
     * Idempotência (opcional, por usuário): mesma chave + mesmo payload → devolve a entrada
     * original (replay); mesma chave + payload diferente → 409 IDEMPOTENCY_KEY_REUTILIZADA.
     * O UNIQUE(criado_por, chave_idempotencia) garante isso no banco mesmo em requisições simultâneas.
     *
     * @return array{0: Entrada, 1: bool} [entrada, replay]
     */
    public function criar(array $dados, User $ator, ?string $chaveIdempotencia = null): array
    {
        try {
            return DB::transaction(function () use ($dados, $ator, $chaveIdempotencia) {
                if ($chaveIdempotencia !== null && ($existente = $this->buscarPorChave($ator, $chaveIdempotencia))) {
                    return [$this->replay($existente, $dados), true];
                }

                // Ordem fixa de travas (conta → categoria) para evitar deadlock.
                $conta = $this->saldos->travarConta($dados['conta_id']);
                if ($conta === null) {
                    throw ValidationException::withMessages(['conta_id' => ['A conta selecionada é inválida.']]);
                }
                if (! $conta->ativa) {
                    throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
                }

                $categoria = Categoria::query()->whereKey($dados['categoria_id'])->lockForUpdate()->first();
                if ($categoria === null || $categoria->tipo !== TipoCategoria::Entrada) {
                    throw ValidationException::withMessages(['categoria_id' => ['A categoria selecionada é inválida para entradas.']]);
                }
                if (! $categoria->ativa) {
                    throw new RegraNegocioException('Categoria inativa não pode ser usada em novos lançamentos.', 'CATEGORIA_INATIVA');
                }

                // Fase 9: trava a linha do período (LOCK IN SHARE MODE) para não disputar de forma
                // insegura com um fechamento concorrente — ver PeriodoFinanceiroService.
                $this->periodos->garantirAberto($dados['data_competencia']);

                $entrada = Entrada::create([
                    'categoria_id' => $dados['categoria_id'],
                    'conta_id' => $dados['conta_id'],
                    'valor' => $dados['valor'],
                    'data_competencia' => $dados['data_competencia'],
                    'descricao' => $dados['descricao'] ?? null,
                    'contribuinte_nome' => $dados['contribuinte_nome'] ?? null,
                    'status' => StatusEntrada::Confirmada->value,
                    'criado_por' => $ator->id,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);
                $entrada->refresh();

                // Sem contribuinte_nome/descricao: dado pessoal não vai para o log imutável.
                $this->auditoria->registrar(
                    acao: 'created',
                    modulo: 'entradas',
                    registroId: $entrada->id,
                    dadosNovos: $this->snapshot($entrada),
                    usuario: $ator,
                );

                return [$entrada, false];
            });
        } catch (QueryException $e) {
            // Corrida: outra requisição com a mesma chave gravou primeiro. A transação já foi
            // desfeita; relê a entrada vencedora e decide entre replay e conflito.
            if ($chaveIdempotencia !== null && $this->violouChaveIdempotencia($e)) {
                $existente = $this->buscarPorChave($ator, $chaveIdempotencia);
                if ($existente !== null) {
                    return [$this->replay($existente, $dados), true];
                }
            }

            throw $e;
        }
    }

    /**
     * Estorna uma entrada: cria a linha de estorno (valor positivo, vinculada à original) e
     * marca a original como estornada. Tudo em uma transação, com a conta travada (FOR UPDATE)
     * para que a validação de saldo seja segura contra concorrência.
     */
    public function estornar(int $entradaId, string $justificativa, bool $confirmarSaldoNegativo, User $ator): Entrada
    {
        try {
            return DB::transaction(function () use ($entradaId, $justificativa, $confirmarSaldoNegativo, $ator) {
                $original = Entrada::query()->whereKey($entradaId)->lockForUpdate()->firstOrFail();

                if ($original->ehEstorno()) {
                    throw new RegraNegocioException('Um estorno não pode ser estornado.', 'ESTORNO_NAO_ESTORNAVEL');
                }
                if ($original->status === StatusEntrada::Estornada) {
                    throw new RegraNegocioException('Esta entrada já foi estornada.', 'ENTRADA_JA_ESTORNADA');
                }

                $conta = $this->saldos->travarConta($original->conta_id);
                if ($conta === null || ! $conta->ativa) {
                    throw new RegraNegocioException('Conta inativa não pode receber lançamentos.', 'CONTA_INATIVA');
                }

                $this->periodos->garantirAberto($original->data_competencia);

                // O estorno reduz o saldo: valida com a conta já travada.
                $novoSaldo = bcsub($this->saldos->saldoAtual($conta), (string) $original->valor, 2);
                $ficariaNegativo = bccomp($novoSaldo, '0', 2) < 0;

                if ($ficariaNegativo && $conta->tipo === TipoConta::Caixa) {
                    throw new RegraNegocioException('Saldo insuficiente no caixa para estornar esta entrada.', 'SALDO_INSUFICIENTE');
                }
                if ($ficariaNegativo && ! $confirmarSaldoNegativo) {
                    throw new RegraNegocioException(
                        'Este estorno deixará o saldo da conta negativo. Confirme para prosseguir.',
                        'SALDO_NEGATIVO_REQUER_CONFIRMACAO'
                    );
                }

                $estorno = Entrada::create([
                    'categoria_id' => $original->categoria_id,
                    'conta_id' => $original->conta_id,
                    'valor' => $original->valor,
                    'data_competencia' => $original->data_competencia->format('Y-m-d'),
                    'status' => StatusEntrada::Confirmada->value,
                    'entrada_estornada_id' => $original->id,
                    'motivo_estorno' => $justificativa,
                    'criado_por' => $ator->id,
                ]);
                $estorno->refresh();

                $original->status = StatusEntrada::Estornada;
                $original->save();

                $this->auditoria->registrar(
                    acao: 'reversed',
                    modulo: 'entradas',
                    registroId: $original->id,
                    dadosAnteriores: ['status' => StatusEntrada::Confirmada->value],
                    dadosNovos: $this->snapshot($original) + [
                        'estorno_id' => $estorno->id,
                        'saldo_negativo_confirmado' => $ficariaNegativo,
                    ],
                    justificativa: $justificativa,
                    usuario: $ator,
                );

                return $estorno;
            });
        } catch (QueryException $e) {
            // Rede de segurança do UNIQUE(entrada_estornada_id): normalmente barrado pela trava acima.
            if ($this->violouEstornoUnico($e)) {
                throw new RegraNegocioException('Esta entrada já foi estornada.', 'ENTRADA_JA_ESTORNADA');
            }

            throw $e;
        }
    }

    private function buscarPorChave(User $ator, string $chave): ?Entrada
    {
        return Entrada::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
    }

    private function replay(Entrada $existente, array $dados): Entrada
    {
        if (! $this->mesmoPayload($existente, $dados)) {
            throw new RegraNegocioException(
                'Esta chave de idempotência já foi usada com dados diferentes.',
                'IDEMPOTENCY_KEY_REUTILIZADA'
            );
        }

        return $existente;
    }

    /** Compara valores normalizados; a entrada é imutável, então o gravado é o payload original. */
    private function mesmoPayload(Entrada $entrada, array $dados): bool
    {
        return (int) $entrada->categoria_id === (int) $dados['categoria_id']
            && (int) $entrada->conta_id === (int) $dados['conta_id']
            && bccomp((string) $entrada->valor, $dados['valor'], 2) === 0
            && $entrada->data_competencia->format('Y-m-d') === $dados['data_competencia']
            && ($entrada->descricao ?? null) === ($dados['descricao'] ?? null)
            && ($entrada->contribuinte_nome ?? null) === ($dados['contribuinte_nome'] ?? null);
    }

    private function violouChaveIdempotencia(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'entradas_idempotencia_unica');
    }

    private function violouEstornoUnico(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'entradas_estorno_unico');
    }

    /** Campos auditados (sem dados pessoais). */
    private function snapshot(Entrada $entrada): array
    {
        return [
            'categoria_id' => $entrada->categoria_id,
            'conta_id' => $entrada->conta_id,
            'valor' => (string) $entrada->valor,
            'data_competencia' => $entrada->data_competencia->format('Y-m-d'),
            'status' => $entrada->status->value,
        ];
    }
}
