<?php

namespace Database\Seeders;

use App\Enums\TipoCategoria;
use App\Models\Categoria;
use Illuminate\Database\Seeder;

/**
 * Categorias padrão aprovadas. Idempotente: só cria o que não existe (por tipo + nome)
 * e nunca altera categorias já cadastradas (ex.: uma que foi inativada continua inativa).
 */
class CategoriaSeeder extends Seeder
{
    private const PADRAO = [
        'entrada' => [
            'Dízimo',
            'Oferta',
            'Oferta de Missões',
            'Doação',
            'Evento/Campanha',
            'Outras receitas',
        ],
        'despesa' => [
            'Aluguel/Manutenção do templo',
            'Água/Luz/Internet',
            'Salários/Ajuda de custo',
            'Material de limpeza/escritório',
            'Eventos',
            'Missões/Ação social',
            'Outras despesas',
        ],
    ];

    public function run(): void
    {
        foreach (self::PADRAO as $tipo => $nomes) {
            foreach ($nomes as $nome) {
                Categoria::firstOrCreate(
                    ['tipo' => TipoCategoria::from($tipo)->value, 'nome' => $nome],
                    ['ativa' => true]
                );
            }
        }
    }
}
