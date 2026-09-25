<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'ativo' => $this->ativo,
            'perfil' => $this->whenLoaded('perfil', fn () => [
                'id' => $this->perfil->id,
                'slug' => $this->perfil->slug->value,
                'nome_exibicao' => $this->perfil->nome_exibicao,
            ]),
            // Só presente quando o controller carrega a relação (apenas para quem
            // pode gerenciar exceções) — nunca vaza para os demais perfis.
            'permissoes_excecao' => $this->whenLoaded(
                'permissoesExcecao',
                fn () => $this->permissoesExcecao->pluck('permissao')->values()
            ),
            'ultimo_login_em' => $this->ultimo_login_em,
            'created_at' => $this->created_at,
        ];
    }
}
