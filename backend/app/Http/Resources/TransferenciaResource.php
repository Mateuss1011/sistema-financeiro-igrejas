<?php

namespace App\Http\Resources;

use App\Enums\StatusTransferencia;
use App\Policies\TransferenciaPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferenciaResource extends JsonResource
{
    private const ATRIBUTO_PERMISSOES = 'transferencia_permissoes';

    public function toArray(Request $request): array
    {
        $original = $this->transferencia_estornada_id === null;
        $permissoes = $this->permissoesDoUsuario($request);

        return [
            'id' => $this->id,
            'conta_origem' => $this->whenLoaded('origem', fn () => [
                'id' => $this->origem->id,
                'nome' => $this->origem->nome,
                'tipo' => $this->origem->tipo->value,
            ]),
            'conta_destino' => $this->whenLoaded('destino', fn () => [
                'id' => $this->destino->id,
                'nome' => $this->destino->nome,
                'tipo' => $this->destino->tipo->value,
            ]),
            'conta_origem_id' => $this->conta_origem_id,
            'conta_destino_id' => $this->conta_destino_id,
            // Valor sempre string decimal positiva; o estorno também é positivo (com origem/destino invertidos).
            'valor' => $this->valor,
            'data_transferencia' => $this->data_transferencia->format('Y-m-d'),
            'descricao' => $this->descricao,
            'status' => $this->status->value,
            'eh_estorno' => ! $original,
            'transferencia_estornada_id' => $this->transferencia_estornada_id,
            'motivo_estorno' => $this->motivo_estorno,
            'estorno_id' => $this->whenLoaded('estorno', fn () => $this->estorno?->id),
            'criado_por' => $this->whenLoaded('criadoPor', fn () => [
                'id' => $this->criadoPor->id,
                'name' => $this->criadoPor->name,
            ]),
            'created_at' => $this->created_at,
            // Flag de exibição: estado do registro + permissão (período fechado só é conhecido no clique: 409).
            'estornavel' => $original && $this->status === StatusTransferencia::Confirmada && $permissoes['estornar'],
        ];
    }

    private function permissoesDoUsuario(Request $request): array
    {
        if (! $request->attributes->has(self::ATRIBUTO_PERMISSOES)) {
            $request->attributes->set(self::ATRIBUTO_PERMISSOES, app(TransferenciaPolicy::class)->permissoes($request->user()));
        }

        return $request->attributes->get(self::ATRIBUTO_PERMISSOES);
    }
}
