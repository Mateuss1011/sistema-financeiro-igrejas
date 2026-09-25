<?php

namespace Tests\Feature\Despesas;

use App\Enums\PerfilSlug;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\PermissaoExcecao;
use App\Models\User;
use Tests\Feature\Entradas\CenarioEntradas;

/** Helpers dos testes de despesas (reaproveita como/conta/categoria/hoje/fecharPeriodo/saldoNaApi de entradas). */
trait CenarioDespesas
{
    use CenarioEntradas;

    protected function categoriaDespesa(string $nome = 'Energia X', bool $ativa = true): Categoria
    {
        return $this->categoria($nome, $ativa, 'despesa');
    }

    /** Usuário do perfil com as chaves de exceção concedidas por um Pastor. */
    protected function comExcecoes(PerfilSlug $perfil, array $chaves): User
    {
        $usuario = $this->como($perfil);
        $pastor = $this->como(PerfilSlug::Pastor);
        foreach ($chaves as $chave) {
            PermissaoExcecao::create(['user_id' => $usuario->id, 'permissao' => $chave, 'concedida_por' => $pastor->id]);
        }

        return $usuario->refresh();
    }

    protected function payloadDespesa(Categoria $categoria, array $sobrescrever = []): array
    {
        return array_merge([
            'categoria_id' => $categoria->id,
            'valor' => '100.00',
            'data_competencia' => $this->hoje(),
            'descricao' => 'Conta de luz',
        ], $sobrescrever);
    }

    /** Despesa Pendente criada direto no banco (setup). */
    protected function despesaPendente(Categoria $categoria, User $autor, array $extra = []): Despesa
    {
        return Despesa::create(array_merge([
            'categoria_id' => $categoria->id,
            'valor' => '100.00',
            'data_competencia' => $this->hoje(),
            'descricao' => 'Despesa pendente',
            'status' => 'pendente',
            'criado_por' => $autor->id,
        ], $extra));
    }

    /** Despesa Paga (dinheiro movido) criada direto no banco (setup). */
    protected function despesaPaga(Conta $conta, Categoria $categoria, User $autor, array $extra = []): Despesa
    {
        return Despesa::create(array_merge([
            'categoria_id' => $categoria->id,
            'conta_id' => $conta->id,
            'valor' => '100.00',
            'data_competencia' => $this->hoje(),
            'data_pagamento' => $this->hoje(),
            'descricao' => 'Despesa paga',
            'status' => 'paga',
            'criado_por' => $autor->id,
            'pago_por' => $autor->id,
            'pago_em' => now(),
        ], $extra));
    }

    protected function despesaCancelada(Categoria $categoria, User $autor, array $extra = []): Despesa
    {
        return $this->despesaPendente($categoria, $autor, array_merge(['status' => 'cancelada', 'motivo_cancelamento' => 'sem uso'], $extra));
    }

    /** Linha de estorno + original marcada como estornada (como o service faz). */
    protected function despesaEstornada(Conta $conta, Categoria $categoria, User $autor): Despesa
    {
        $original = $this->despesaPaga($conta, $categoria, $autor, ['status' => 'estornada']);
        Despesa::create([
            'categoria_id' => $categoria->id, 'conta_id' => $conta->id, 'valor' => $original->valor,
            'data_competencia' => $original->data_competencia->format('Y-m-d'), 'data_pagamento' => $original->data_pagamento->format('Y-m-d'),
            'status' => 'paga', 'despesa_estornada_id' => $original->id, 'motivo_estorno' => 'setup', 'criado_por' => $autor->id,
        ]);

        return $original;
    }

    protected function corpoPagamento(Conta $conta, array $sobrescrever = []): array
    {
        return array_merge(['conta_id' => $conta->id, 'data_pagamento' => $this->hoje()], $sobrescrever);
    }

    protected function pagar(User $ator, Despesa|int $despesa, array $corpo)
    {
        $id = $despesa instanceof Despesa ? $despesa->id : $despesa;

        return $this->actingAs($ator)->postJson("/api/v1/despesas/{$id}/pagar", $corpo);
    }

    protected function cancelar(User $ator, Despesa|int $despesa, array $corpo = ['justificativa' => 'Não será mais paga'])
    {
        $id = $despesa instanceof Despesa ? $despesa->id : $despesa;

        return $this->actingAs($ator)->postJson("/api/v1/despesas/{$id}/cancelar", $corpo);
    }

    protected function estornarDespesa(User $ator, Despesa|int $despesa, array $corpo = ['justificativa' => 'Pagamento em duplicidade'])
    {
        $id = $despesa instanceof Despesa ? $despesa->id : $despesa;

        return $this->actingAs($ator)->postJson("/api/v1/despesas/{$id}/estornar", $corpo);
    }

    protected function statusDe(Despesa $despesa): string
    {
        return $despesa->fresh()->status->value;
    }
}
