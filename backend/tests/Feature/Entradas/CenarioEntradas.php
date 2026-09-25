<?php

namespace Tests\Feature\Entradas;

use App\Enums\PerfilSlug;
use App\Enums\PermissaoExcecaoChave;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Entrada;
use App\Models\PermissaoExcecao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Helpers compartilhados pelos testes de entradas. */
trait CenarioEntradas
{
    protected function como(PerfilSlug $perfil): User
    {
        return User::factory()->comPerfil($perfil)->create();
    }

    protected function administradorOperador(): User
    {
        $admin = $this->como(PerfilSlug::Administrador);
        $pastor = $this->como(PerfilSlug::Pastor);
        PermissaoExcecao::create([
            'user_id' => $admin->id,
            'permissao' => PermissaoExcecaoChave::EntradasOperar->value,
            'concedida_por' => $pastor->id,
        ]);

        return $admin->refresh();
    }

    protected function conta(string $nome = 'Banco Itaú', string $tipo = 'banco', string $saldo = '0.00', bool $ativa = true): Conta
    {
        return Conta::create(['nome' => $nome, 'tipo' => $tipo, 'saldo_inicial' => $saldo, 'ativa' => $ativa]);
    }

    protected function categoria(string $nome = 'Dízimo X', bool $ativa = true, string $tipo = 'entrada'): Categoria
    {
        return Categoria::create(['nome' => $nome, 'tipo' => $tipo, 'ativa' => $ativa]);
    }

    protected function hoje(): string
    {
        return Carbon::now('America/Sao_Paulo')->toDateString();
    }

    protected function payload(Conta $conta, Categoria $categoria, array $sobrescrever = []): array
    {
        return array_merge([
            'categoria_id' => $categoria->id,
            'conta_id' => $conta->id,
            'valor' => '100.00',
            'data_competencia' => $this->hoje(),
        ], $sobrescrever);
    }

    /** Cria uma entrada direto no banco (setup), sem passar pela API. */
    protected function entrada(Conta $conta, Categoria $categoria, User $autor, string $valor = '100.00', ?string $data = null, array $extra = []): Entrada
    {
        return Entrada::create(array_merge([
            'categoria_id' => $categoria->id,
            'conta_id' => $conta->id,
            'valor' => $valor,
            'data_competencia' => $data ?? $this->hoje(),
            'status' => 'confirmada',
            'criado_por' => $autor->id,
        ], $extra));
    }

    protected function fecharPeriodo(string $anoMes, User $por): void
    {
        DB::table('periodos_financeiros')->insert([
            'ano_mes' => $anoMes,
            'status' => 'fechado',
            'fechado_por' => $por->id,
            'fechado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function saldoNaApi(User $ator, Conta $conta): string
    {
        $linhas = $this->actingAs($ator)->getJson('/api/v1/contas?por_pagina=100')->assertOk()->json('data');

        return collect($linhas)->firstWhere('id', $conta->id)['saldo_atual'];
    }
}
