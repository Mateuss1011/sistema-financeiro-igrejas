<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusDespesa;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelarDespesaRequest;
use App\Http\Requests\EstornarDespesaRequest;
use App\Http\Requests\PagarDespesaRequest;
use App\Http\Requests\StoreDespesaRequest;
use App\Http\Requests\UpdateDespesaRequest;
use App\Http\Resources\DespesaResource;
use App\Models\Despesa;
use App\Policies\DespesaPolicy;
use App\Services\DespesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sem GET individual: as linhas da listagem trazem todos os campos. Regras vivem no DespesaService. */
class DespesaController extends Controller
{
    private const CAMPOS_ORDENACAO = ['data_competencia', 'valor', 'data_pagamento', 'created_at', 'id'];
    private const RELACOES = ['categoria', 'conta', 'criadoPor', 'pagoPor', 'estorno'];

    public function __construct(private DespesaService $despesas)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Despesa::class);

        $filtros = $request->validate([
            'data_de' => ['nullable', 'date_format:Y-m-d'],
            'data_ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de'],
            'categoria_id' => ['nullable', 'integer', 'min:1'],
            'conta_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(StatusDespesa::class)],
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
        $politica = app(DespesaPolicy::class);

        $query = Despesa::query()
            ->with(self::RELACOES)
            // Restrição do Auxiliar no BACKEND: só as despesas que ele mesmo criou.
            ->unless($ator->can('viewAll', Despesa::class), fn ($q) => $q->where('criado_por', $ator->id))
            ->when($filtros['data_de'] ?? null, fn ($q, $v) => $q->where('data_competencia', '>=', $v))
            ->when($filtros['data_ate'] ?? null, fn ($q, $v) => $q->where('data_competencia', '<=', $v))
            ->when($filtros['categoria_id'] ?? null, fn ($q, $v) => $q->where('categoria_id', $v))
            ->when($filtros['conta_id'] ?? null, fn ($q, $v) => $q->where('conta_id', $v))
            ->when($filtros['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filtros['estorno']), function ($q) use ($filtros) {
                filter_var($filtros['estorno'], FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNotNull('despesa_estornada_id')
                    : $q->whereNull('despesa_estornada_id');
            });

        $this->aplicarOrdenacao($query, $filtros['ordenar'] ?? '-data_competencia,-id');

        $permissoes = $politica->permissoes($ator);
        $request->attributes->set('despesa_permissoes', $permissoes);

        return DespesaResource::collection($query->paginate($filtros['por_pagina'] ?? 20))
            ->additional(['meta' => ['permissoes' => $permissoes]]);
    }

    public function store(StoreDespesaRequest $request)
    {
        [$despesa, $replay] = $this->despesas->criar(
            $request->dadosNormalizados(),
            $request->user(),
            $request->chaveIdempotencia(),
        );

        return $this->responder($despesa, $replay ? 200 : 201, $replay);
    }

    public function update(UpdateDespesaRequest $request, int $despesa)
    {
        return $this->responder($this->despesas->atualizar($despesa, $request->dadosNormalizados(), $request->user()), 200);
    }

    public function pagar(PagarDespesaRequest $request, int $despesa)
    {
        [$paga, $replay] = $this->despesas->pagar(
            $despesa,
            ['conta_id' => (int) $request->validated('conta_id'), 'data_pagamento' => $request->validated('data_pagamento')],
            $request->boolean('confirmar_saldo_negativo'),
            $request->user(),
        );

        return $this->responder($paga, 200, $replay);
    }

    public function cancelar(CancelarDespesaRequest $request, int $despesa)
    {
        return $this->responder($this->despesas->cancelar($despesa, $request->validated('justificativa'), $request->user()), 200);
    }

    public function estornar(EstornarDespesaRequest $request, int $despesa)
    {
        return $this->responder($this->despesas->estornar($despesa, $request->validated('justificativa'), $request->user()), 201);
    }

    public function destroy(Request $request, int $despesa)
    {
        $this->authorize('delete', Despesa::query()->findOrFail($despesa));

        $this->despesas->excluir($despesa, $request->user());

        return response()->json(['data' => ['message' => 'Despesa excluída.']]);
    }

    private function responder(Despesa $despesa, int $status, bool $replay = false): JsonResponse
    {
        $despesa->load(self::RELACOES);

        $resposta = (new DespesaResource($despesa))->response()->setStatusCode($status);

        return $replay ? $resposta->header('Idempotent-Replayed', 'true') : $resposta;
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
