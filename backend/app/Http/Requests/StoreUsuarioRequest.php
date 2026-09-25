<?php

namespace App\Http\Requests;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $perfilId = $this->input('perfil_id');
        $perfil = is_scalar($perfilId) ? Perfil::find($perfilId) : null;

        // Sem perfil de destino válido, vale o de MENOR privilégio: quem nem esse pode criar (Tesoureiro, Auxiliar,
        // Secretário) recebe 403 já aqui — nunca um 422 que revelaria regras de validação e e-mails já cadastrados.
        return $this->user()->can('create', [\App\Models\User::class, $perfil?->slug ?? PerfilSlug::Secretario]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            // max:255 igual ao do login: uma senha maior nunca conseguiria autenticar.
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'perfil_id' => ['required', 'integer', 'exists:perfis,id'],
        ];
    }
}
