<?php

namespace Tests\Feature\Transferencias;

use App\Models\AjusteSaldo;
use App\Models\Conta;
use App\Models\Transferencia;
use App\Models\User;
use App\Services\SaldoService;
use Tests\Feature\Despesas\CenarioDespesas;

/** Helpers dos testes da Fase 8 (reaproveita como/conta/hoje/fecharPeriodo/comExcecoes/saldoNaApi etc.). */
trait CenarioTransferencias
{
    use CenarioDespesas;

    protected function corpoTransferencia(Conta $origem, Conta $destino, array $sobrescrever = []): array
    {
        return array_merge([
            'conta_origem_id' => $origem->id,
            'conta_destino_id' => $destino->id,
            'valor' => '100.00',
            'data_transferencia' => $this->hoje(),
        ], $sobrescrever);
    }

    protected function transferir(User $ator, Conta $origem, Conta $destino, array $sobrescrever = [], ?string $chave = null)
    {
        return $this->actingAs($ator)->postJson(
            '/api/v1/transferencias',
            $this->corpoTransferencia($origem, $destino, $sobrescrever),
            $chave === null ? [] : ['Idempotency-Key' => $chave]
        );
    }

    protected function estornarTransferencia(User $ator, Transferencia|int $transferencia, array $corpo = ['justificativa' => 'Transferência lançada por engano'])
    {
        $id = $transferencia instanceof Transferencia ? $transferencia->id : $transferencia;

        return $this->actingAs($ator)->postJson("/api/v1/transferencias/{$id}/estornar", $corpo);
    }

    /** Transferência criada direto no banco (setup), sem passar pela API. */
    protected function transferenciaDireta(Conta $origem, Conta $destino, User $autor, string $valor = '100.00', ?string $data = null, array $extra = []): Transferencia
    {
        return Transferencia::create(array_merge([
            'conta_origem_id' => $origem->id,
            'conta_destino_id' => $destino->id,
            'valor' => $valor,
            'data_transferencia' => $data ?? $this->hoje(),
            'status' => 'confirmada',
            'criado_por' => $autor->id,
        ], $extra));
    }

    protected function corpoAjuste(Conta $conta, array $sobrescrever = []): array
    {
        return array_merge([
            'conta_id' => $conta->id,
            'valor' => '50.00',
            'sentido' => 'credito',
            'data_ajuste' => $this->hoje(),
            'justificativa' => 'Diferença encontrada na conciliação',
        ], $sobrescrever);
    }

    protected function ajustar(User $ator, Conta $conta, array $sobrescrever = [], ?string $chave = null)
    {
        return $this->actingAs($ator)->postJson(
            '/api/v1/ajustes',
            $this->corpoAjuste($conta, $sobrescrever),
            $chave === null ? [] : ['Idempotency-Key' => $chave]
        );
    }

    protected function ajusteDireto(Conta $conta, User $autor, string $valor = '50.00', string $sentido = 'credito', array $extra = []): AjusteSaldo
    {
        return AjusteSaldo::create(array_merge([
            'conta_id' => $conta->id,
            'valor' => $valor,
            'sentido' => $sentido,
            'data_ajuste' => $this->hoje(),
            'justificativa' => 'Ajuste de teste',
            'criado_por' => $autor->id,
        ], $extra));
    }

    protected function saldoDe(Conta $conta): string
    {
        return app(SaldoService::class)->saldoAtual($conta->fresh());
    }
}
