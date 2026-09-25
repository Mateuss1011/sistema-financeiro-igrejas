<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SentidoAjuste;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAjusteSaldoRequest;
use App\Http\Resources\AjusteSaldoResource;
use App\Models\AjusteSaldo;
use App\Policies\AjusteSaldoPolicy;
use App\Services\AjusteSaldoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sem PUT/DELETE/GET individual: ajustes são imutáveis; a correção é outro ajuste de sentido oposto. */
class AjusteSaldoController extends Controller
{
    private const CAMPOS_ORDENACAO = ['data_ajuste', 'valor', 'created_at', 'id'];
    private const RELACOES = ['conta', 'criadoPor'];

    public function __construct(private AjusteSaldoService $ajustes)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', AjusteSaldo::class);

        $filtros = $request->validate([
            'data_de' => ['nullable', 'date_format:Y-m-d'],
            'data_ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de'],
            'conta_id' => ['nullable', 'integer', 'min:1'],
            'sentido' => ['nullable', Rule::enum(SentidoAjuste::class)],
            'ordenar' => ['bail', 'nullable', 'string', 'max:100', function (string $atributo, mixed $valor, \Closure $falhar) {
                foreach (explode(',', $valor) as $campo) {
                    if (! in_array(ltrim($campo, '-'), self::CAMPOS_ORDENACAO, true)) {
                        $falhar('Campo de ordenação inválido.');
                    }
                }
            }],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AjusteSaldo::query()
            ->with(self::RELACOES)
            ->when($filtros['data_de'] ?? null, fn ($q, $v) => $q->where('data_ajuste', '>=', $v))
            ->when($filtros['data_ate'] ?? null, fn ($q, $v) => $q->where('data_ajuste', '<=', $v))
            ->when($filtros['conta_id'] ?? null, fn ($q, $v) => $q->where('conta_id', $v))
            ->when($filtros['sentido'] ?? null, fn ($q, $v) => $q->where('sentido', $v));

        $this->aplicarOrdenacao($query, $filtros['ordenar'] ?? '-data_ajuste,-id');

        return AjusteSaldoResource::collection($query->paginate($filtros['por_pagina'] ?? 20))
            ->additional(['meta' => ['permissoes' => app(AjusteSaldoPolicy::class)->permissoes($request->user())]]);
    }

    public function store(StoreAjusteSaldoRequest $request)
    {
        [$ajuste, $replay] = $this->ajustes->criar(
            $request->dadosNormalizados(),
            $request->boolean('confirmar_saldo_negativo'),
            $request->user(),
            $request->chaveIdempotencia(),
        );

        $ajuste->load(self::RELACOES);

        $resposta = (new AjusteSaldoResource($ajuste))->response()->setStatusCode($replay ? 200 : 201);

        return $replay ? $resposta->header('Idempotent-Replayed', 'true') : $resposta;
    }

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
