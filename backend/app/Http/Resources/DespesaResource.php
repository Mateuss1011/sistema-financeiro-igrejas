<?php

namespace App\Http\Resources;

use App\Enums\PerfilSlug;
use App\Enums\StatusDespesa;
use App\Policies\DespesaPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DespesaResource extends JsonResource
{
    private const ATRIBUTO_PERMISSOES = 'despesa_permissoes';

    public function toArray(Request $request): array
    {
        $permissoes = $this->permissoesDoUsuario($request);
        $original = $this->despesa_estornada_id === null;
        $pendente = $original && $this->status === StatusDespesa::Pendente;
        $pastor = $request->user()?->ehPerfil(PerfilSlug::Pastor) ?? false;

        // Janela de 48h calculada só para a flag de exibição; a regra real está no DespesaService.
        $dentroDaJanela = $this->created_at !== null && now()->lessThanOrEqualTo($this->created_at->copy()->addHours(48));

        return [
            'id' => $this->id,
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nome' => $this->categoria->nome,
            ]),
            'conta' => $this->whenLoaded('conta', fn () => $this->conta ? [
                'id' => $this->conta->id,
                'nome' => $this->conta->nome,
                'tipo' => $this->conta->tipo->value,
            ] : null),
            'categoria_id' => $this->categoria_id,
            'conta_id' => $this->conta_id,
            // Valor sempre string decimal positiva; o estorno também é positivo.
            'valor' => $this->valor,
            'data_competencia' => $this->data_competencia->format('Y-m-d'),
            'data_pagamento' => $this->data_pagamento?->format('Y-m-d'),
            'descricao' => $this->descricao,
            'fornecedor_nome' => $this->fornecedor_nome,
            'status' => $this->status->value,
            'eh_estorno' => ! $original,
            'despesa_estornada_id' => $this->despesa_estornada_id,
            'motivo_estorno' => $this->motivo_estorno,
            'motivo_cancelamento' => $this->motivo_cancelamento,
            'estorno_id' => $this->whenLoaded('estorno', fn () => $this->estorno?->id),
            'criado_por' => $this->whenLoaded('criadoPor', fn () => [
                'id' => $this->criadoPor->id,
                'name' => $this->criadoPor->name,
            ]),
            'pago_por' => $this->whenLoaded('pagoPor', fn () => $this->pagoPor ? [
                'id' => $this->pagoPor->id,
                'name' => $this->pagoPor->name,
            ] : null),
            'pago_em' => $this->pago_em,
            'created_at' => $this->created_at,
            // Flags de exibição: ESTADO do registro + permissão do perfil (calculadas sem N+1).
            // Período fechado só é conhecido no clique (409 PERIODO_FECHADO).
            'editavel' => $pendente && $permissoes['editar'],
            'pagavel' => $pendente && $permissoes['pagar'],
            'cancelavel' => $pendente && $permissoes['cancelar'],
            'estornavel' => $original && $this->status === StatusDespesa::Paga && $permissoes['estornar'],
            'excluivel' => $pendente && $permissoes['excluir']
                && ($pastor || ($this->criado_por === $request->user()?->id && $dentroDaJanela)),
        ];
    }

    /** Uma única consulta de exceções por requisição (compartilhada por todas as linhas). */
    private function permissoesDoUsuario(Request $request): array
    {
        if (! $request->attributes->has(self::ATRIBUTO_PERMISSOES)) {
            $request->attributes->set(self::ATRIBUTO_PERMISSOES, app(DespesaPolicy::class)->permissoes($request->user()));
        }

        return $request->attributes->get(self::ATRIBUTO_PERMISSOES);
    }
}
