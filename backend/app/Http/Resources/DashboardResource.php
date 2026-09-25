<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formato estável do dashboard. Os blocos `saldo` e `periodo` só existem no dashboard completo — no
 * parcial (Auxiliar) as chaves nem aparecem, em vez de virem nulas. Dinheiro sempre como string decimal.
 */
class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $painel = $this->resource;

        $resposta = [
            'ano_mes' => $painel['ano_mes'],
            'visao' => $painel['visao'],
            'escopo' => $painel['escopo'],
            'entradas' => ['total' => $painel['entradas']['total']],
            'despesas_pagas' => ['total' => $painel['despesas_pagas']['total']],
            'despesas_pendentes' => [
                'quantidade' => $painel['despesas_pendentes']['quantidade'],
                'valor' => $painel['despesas_pendentes']['valor'],
            ],
        ];

        if (isset($painel['saldo'])) {
            $resposta['saldo'] = $painel['saldo'];
            $resposta['periodo'] = $painel['periodo'];
        }

        return $resposta;
    }
}
