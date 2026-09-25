<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusEntrada;
use App\Http\Controllers\Controller;
use App\Http\Requests\EstornarEntradaRequest;
use App\Http\Requests\StoreEntradaRequest;
use App\Http\Resources\EntradaResource;
use App\Models\Entrada;
use App\Services\EntradaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sem update/destroy/show: entradas são imutáveis; correção só por estorno. */
class EntradaController extends Controller
{
    private const CAMPOS_ORDENACAO = ['data_competencia', 'valor', 'created_at', 'id'];

    public function __construct(private EntradaService $entradas)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Entrada::class);

        $filtros = $request->validate([
            'data_de' => ['nullable', 'date_format:Y-m-d'],
            'data_ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de'],
            'categoria_id' => ['nullable', 'integer', 'min:1'],
            'conta_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(StatusEntrada::class)],
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

        $ator = $request->user();

        $query = Entrada::query()
            ->with(['categoria', 'conta', 'criadoPor', 'estorno'])
            // Restrição do Auxiliar no BACKEND: só as entradas que ele mesmo criou.
            ->unless($ator->can('viewAll', Entrada::class), fn ($q) => $q->where('criado_por', $ator->id))
            ->when($filtros['data_de'] ?? null, fn ($q, $v) => $q->where('data_competencia', '>=', $v))
            ->when($filtros['data_ate'] ?? null, fn ($q, $v) => $q->where('data_competencia', '<=', $v))
            ->when($filtros['categoria_id'] ?? null, fn ($q, $v) => $q->where('categoria_id', $v))
            ->when($filtros['conta_id'] ?? null, fn ($q, $v) => $q->where('conta_id', $v))
            ->when($filtros['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filtros['estorno']), function ($q) use ($filtros) {
                filter_var($filtros['estorno'], FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNotNull('entrada_estornada_id')
                    : $q->whereNull('entrada_estornada_id');
            });

        $this->aplicarOrdenacao($query, $filtros['ordenar'] ?? '-data_competencia,-id');

        return EntradaResource::collection($query->paginate($filtros['por_pagina'] ?? 20))
            ->additional(['meta' => ['permissoes' => [
                'criar' => $ator->can('create', Entrada::class),
                'estornar' => $ator->can('reverse', Entrada::class),
            ]]]);
    }

    public function store(StoreEntradaRequest $request)
    {
        [$entrada, $replay] = $this->entradas->criar(
            $request->dadosNormalizados(),
            $request->user(),
            $request->chaveIdempotencia(),
        );

        $entrada->load(['categoria', 'conta', 'criadoPor', 'estorno']);

        $resposta = (new EntradaResource($entrada))->response()->setStatusCode($replay ? 200 : 201);

        return $replay ? $resposta->header('Idempotent-Replayed', 'true') : $resposta;
    }

    public function estornar(EstornarEntradaRequest $request, int $entrada)
    {
        $estorno = $this->entradas->estornar(
            $entrada,
            $request->validated('justificativa'),
            $request->boolean('confirmar_saldo_negativo'),
            $request->user(),
        );

        $estorno->load(['categoria', 'conta', 'criadoPor', 'estorno']);

        return (new EntradaResource($estorno))->response()->setStatusCode(201);
    }

    /** Ordenação por campos permitidos; `id` desempata sempre (na direção do primeiro campo). */
    private function aplicarOrdenacao($query, string $ordenar): void
    {
        $campos = array_filter(explode(',', $ordenar), fn ($campo) => ltrim($campo, '-') !== 'id');
        $direcaoPadrao = 'asc';

        foreach (array_values($campos) as $i => $campo) {
            $direcao = str_starts_with($campo, '-') ? 'desc' : 'asc';
            if ($i === 0) {
                $direcaoPadrao = $direcao;
            }
            $query->orderBy(ltrim($campo, '-'), $direcao);
        }

        $query->orderBy('id', $direcaoPadrao);
    }
}
