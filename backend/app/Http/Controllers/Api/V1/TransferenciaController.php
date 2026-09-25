<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusTransferencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\EstornarTransferenciaRequest;
use App\Http\Requests\StoreTransferenciaRequest;
use App\Http\Resources\TransferenciaResource;
use App\Models\Transferencia;
use App\Policies\TransferenciaPolicy;
use App\Services\TransferenciaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sem PUT/DELETE/GET individual: transferências são imutáveis; correção só por estorno. */
class TransferenciaController extends Controller
{
    private const CAMPOS_ORDENACAO = ['data_transferencia', 'valor', 'created_at', 'id'];
    private const RELACOES = ['origem', 'destino', 'criadoPor', 'estorno'];

    public function __construct(private TransferenciaService $transferencias)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Transferencia::class);

        $filtros = $request->validate([
            'data_de' => ['nullable', 'date_format:Y-m-d'],
            'data_ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de'],
            'conta_id' => ['nullable', 'integer', 'min:1'],
            'conta_origem_id' => ['nullable', 'integer', 'min:1'],
            'conta_destino_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(StatusTransferencia::class)],
            'estorno' => ['nullable', 'in:true,false,1,0'],
            'ordenar' => ['bail', 'nullable', 'string', 'max:100', function (string $atributo, mixed $valor, \Closure $falhar) {
                foreach (explode(',', $valor) as $campo) {
                    if (! in_array(ltrim($campo, '-'), self::CAMPOS_ORDENACAO, true)) {
                        $falhar('Campo de ordenação inválido.');
                    }
                }
            }],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $permissoes = app(TransferenciaPolicy::class)->permissoes($request->user());
        $request->attributes->set('transferencia_permissoes', $permissoes);

        $query = Transferencia::query()
            ->with(self::RELACOES)
            ->when($filtros['data_de'] ?? null, fn ($q, $v) => $q->where('data_transferencia', '>=', $v))
            ->when($filtros['data_ate'] ?? null, fn ($q, $v) => $q->where('data_transferencia', '<=', $v))
            // "conta_id" casa com a conta em QUALQUER dos lados (origem ou destino).
            ->when($filtros['conta_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('conta_origem_id', $v)->orWhere('conta_destino_id', $v)))
            ->when($filtros['conta_origem_id'] ?? null, fn ($q, $v) => $q->where('conta_origem_id', $v))
            ->when($filtros['conta_destino_id'] ?? null, fn ($q, $v) => $q->where('conta_destino_id', $v))
            ->when($filtros['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filtros['estorno']), function ($q) use ($filtros) {
                filter_var($filtros['estorno'], FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNotNull('transferencia_estornada_id')
                    : $q->whereNull('transferencia_estornada_id');
            });

        $this->aplicarOrdenacao($query, $filtros['ordenar'] ?? '-data_transferencia,-id');

        return TransferenciaResource::collection($query->paginate($filtros['por_pagina'] ?? 20))
            ->additional(['meta' => ['permissoes' => $permissoes]]);
    }

    public function store(StoreTransferenciaRequest $request)
    {
        [$transferencia, $replay] = $this->transferencias->criar(
            $request->dadosNormalizados(),
            $request->boolean('confirmar_saldo_negativo'),
            $request->user(),
            $request->chaveIdempotencia(),
        );

        $transferencia->load(self::RELACOES);

        $resposta = (new TransferenciaResource($transferencia))->response()->setStatusCode($replay ? 200 : 201);

        return $replay ? $resposta->header('Idempotent-Replayed', 'true') : $resposta;
    }

    public function estornar(EstornarTransferenciaRequest $request, int $transferencia)
    {
        $estorno = $this->transferencias->estornar(
            $transferencia,
            $request->validated('justificativa'),
            $request->boolean('confirmar_saldo_negativo'),
            $request->user(),
        );

        $estorno->load(self::RELACOES);

        return (new TransferenciaResource($estorno))->response()->setStatusCode(201);
    }

    /** Ordenação por campos permitidos; `id` desempata sempre (na direção do primeiro campo). */
    private function aplicarOrdenacao($query, string $ordenar): void
    {
        $campos = array_values(array_filter(explode(',', $ordenar), fn ($campo) => ltrim($campo, '-') !== 'id'));
        $direcaoPadrao = 'asc';

        foreach ($campos as $i => $campo) {
            $direcao = str_starts_with($campo, '-') ? 'desc' : 'asc';
            if ($i === 0) {
                $direcaoPadrao = $direcao;
            }
            $query->orderBy(ltrim($campo, '-'), $direcao);
        }

        $query->orderBy('id', $direcaoPadrao);
    }
}
