<?php

namespace App\Models;

use App\Enums\PerfilSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Perfil extends Model
{
    protected $table = 'perfis';

    protected $fillable = ['slug', 'nome_exibicao'];

    protected $casts = [
        'slug' => PerfilSlug::class,
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
