<?php

namespace App\Http\Resources;

use App\Services\SaldoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'tipo' => $this->tipo->value,
            'ativa' => $this->ativa,
            // Valores monetários sempre como string decimal (nunca número/float).
            'saldo_inicial' => $this->saldo_inicial,
            'saldo_atual' => $this->saldoCalculado ?? app(SaldoService::class)->saldoAtual($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
