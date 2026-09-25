<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AjusteSaldoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conta' => $this->whenLoaded('conta', fn () => [
                'id' => $this->conta->id,
                'nome' => $this->conta->nome,
                'tipo' => $this->conta->tipo->value,
            ]),
            'conta_id' => $this->conta_id,
            // Sempre positivo; o efeito no saldo vem de `sentido`.
            'valor' => $this->valor,
            'sentido' => $this->sentido->value,
            'data_ajuste' => $this->data_ajuste->format('Y-m-d'),
            'justificativa' => $this->justificativa,
            'criado_por' => $this->whenLoaded('criadoPor', fn () => [
                'id' => $this->criadoPor->id,
                'name' => $this->criadoPor->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
