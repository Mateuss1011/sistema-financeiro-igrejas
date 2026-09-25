<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PerfilSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'perfil_id',
        'ativo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
            'ultimo_login_em' => 'datetime',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    public function permissoesExcecao(): HasMany
    {
        return $this->hasMany(PermissaoExcecao::class);
    }

    public function temExcecao(string $permissao): bool
    {
        return $this->permissoesExcecao()->where('permissao', $permissao)->exists();
    }

    public function ehPerfil(PerfilSlug $slug): bool
    {
        return $this->perfil?->slug === $slug;
    }

    public function scopePastoresAtivos($query)
    {
        return $query->whereHas('perfil', fn ($q) => $q->where('slug', PerfilSlug::Pastor->value))
            ->where('ativo', true);
    }
}
