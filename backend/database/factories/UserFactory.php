<?php

namespace Database\Factories;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'perfil_id' => Perfil::firstOrCreate(
                ['slug' => PerfilSlug::Secretario->value],
                ['nome_exibicao' => PerfilSlug::Secretario->nomeExibicao()]
            )->id,
            'ativo' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function comPerfil(PerfilSlug $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'perfil_id' => Perfil::firstOrCreate(
                ['slug' => $slug->value],
                ['nome_exibicao' => $slug->nomeExibicao()]
            )->id,
        ]);
    }

    public function inativo(): static
    {
        return $this->state(fn (array $attributes) => ['ativo' => false]);
    }
}
