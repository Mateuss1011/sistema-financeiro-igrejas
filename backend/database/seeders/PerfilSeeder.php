<?php

namespace Database\Seeders;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use Illuminate\Database\Seeder;

class PerfilSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PerfilSlug::cases() as $slug) {
            Perfil::firstOrCreate(
                ['slug' => $slug->value],
                ['nome_exibicao' => $slug->nomeExibicao()]
            );
        }
    }
}
