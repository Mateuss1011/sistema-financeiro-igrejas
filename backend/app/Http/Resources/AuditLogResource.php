<?php

namespace App\Http\Resources;

use App\Support\CatalogoAuditoria;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Registro de auditoria para consulta. O responsável vem do "congelado" no momento do evento (nome e perfil
 * na época), não do cadastro atual — o histórico não muda se o usuário for renomeado ou rebaixado depois.
 */
class AuditLogResource extends JsonResource
{
    /** Chaves que nunca devem sair por aqui, mesmo que algum dia entrem por engano em dados_*. */
    private const CHAVES_SENSIVEIS = '/senha|password|passwd|token|secret|remember/i';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at,
            'modulo' => $this->modulo,
            'modulo_rotulo' => CatalogoAuditoria::rotuloModulo($this->modulo),
            'acao' => $this->acao,
            'acao_rotulo' => CatalogoAuditoria::rotuloAcao($this->acao),
            'registro_id' => $this->registro_id,
            'usuario' => [
                'id' => $this->user_id,
                'nome' => $this->user_nome_congelado,
                'perfil' => $this->user_perfil_congelado,
            ],
            'justificativa' => $this->justificativa,
            'dados_anteriores' => $this->ocultarSensiveis($this->dados_anteriores),
            'dados_novos' => $this->ocultarSensiveis($this->dados_novos),
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
        ];
    }

    private function ocultarSensiveis(?array $dados): ?array
    {
        if ($dados === null) {
            return null;
        }

        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && preg_match(self::CHAVES_SENSIVEIS, $chave)) {
                $dados[$chave] = '[oculto]';
            } elseif (is_array($valor)) {
                $dados[$chave] = $this->ocultarSensiveis($valor);
            }
        }

        return $dados;
    }
}
