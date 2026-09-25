<?php

namespace App\Models;

use App\Enums\TipoCategoria;
use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = ['nome', 'tipo', 'ativa'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCategoria::class,
            'ativa' => 'boolean',
        ];
    }
}
