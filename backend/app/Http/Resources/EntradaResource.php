<?php

namespace App\Http\Resources;

use App\Enums\StatusEntrada;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntradaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nome' => $this->categoria->nome,
            ]),
            'conta' => $this->whenLoaded('conta', fn () => [
                'id' => $this->conta->id,
                'nome' => $this->conta->nome,
                'tipo' => $this->conta->tipo->value,
            ]),
            'categoria_id' => $this->categoria_id,
            'conta_id' => $this->conta_id,
            // Valor sempre string decimal positiva; o estorno também é positivo.
            'valor' => $this->valor,
            'data_competencia' => $this->data_competencia->format('Y-m-d'),
            'descricao' => $this->descricao,
            'contribuinte_nome' => $this->contribuinte_nome,
            'status' => $this->status->value,
            'eh_estorno' => $this->entrada_estornada_id !== null,
            'entrada_estornada_id' => $this->entrada_estornada_id,
            'motivo_estorno' => $this->motivo_estorno,
            'estorno_id' => $this->whenLoaded('estorno', fn () => $this->estorno?->id),
            // Estado do registro (não é autorização): só originais ainda confirmadas podem ser estornadas.
            'estornavel' => $this->entrada_estornada_id === null && $this->status === StatusEntrada::Confirmada,
            'criado_por' => $this->whenLoaded('criadoPor', fn () => [
                'id' => $this->criadoPor->id,
                'name' => $this->criadoPor->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
