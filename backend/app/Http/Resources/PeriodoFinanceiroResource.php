<?php

namespace App\Http\Resources;

use App\Enums\StatusPeriodo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeriodoFinanceiroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // null para o mês corrente sintético (ainda sem linha no banco — nunca foi fechado).
            'id' => $this->id,
            'ano_mes' => $this->ano_mes,
            'status' => $this->status instanceof StatusPeriodo ? $this->status->value : $this->status,
            'fechado_por' => $this->fechadoPor ? ['id' => $this->fechadoPor->id, 'name' => $this->fechadoPor->name] : null,
            'fechado_em' => $this->fechado_em,
            'reaberto_por' => $this->reabertoPor ? ['id' => $this->reabertoPor->id, 'name' => $this->reabertoPor->name] : null,
            'reaberto_em' => $this->reaberto_em,
            'justificativa_reabertura' => $this->justificativa_reabertura,
        ];
    }
}
