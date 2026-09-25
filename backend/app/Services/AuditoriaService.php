<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditoriaService
{
    public function __construct(private Request $request)
    {
    }

    public function registrar(
        string $acao,
        string $modulo,
        ?int $registroId = null,
        ?array $dadosAnteriores = null,
        ?array $dadosNovos = null,
        ?string $justificativa = null,
        ?User $usuario = null,
    ): AuditLog {
        $usuario ??= $this->request->user();

        return AuditLog::create([
            'user_id' => $usuario?->id,
            'user_nome_congelado' => $usuario?->name,
            'user_perfil_congelado' => $usuario?->perfil?->slug?->value,
            'acao' => $acao,
            'modulo' => $modulo,
            'registro_id' => $registroId,
            'dados_anteriores' => $dadosAnteriores,
            'dados_novos' => $dadosNovos,
            'justificativa' => $justificativa,
            'ip' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }
}
