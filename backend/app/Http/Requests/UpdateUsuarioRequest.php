<?php

namespace App\Http\Requests;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $alvo */
        $alvo = $this->route('usuario');

        if (! $this->user()->can('update', $alvo)) {
            return false;
        }

        // Quem edita também precisa poder atribuir o perfil de destino
        // (mesma regra de criação), evitando escalonamento de privilégio.
        $perfilId = $this->input('perfil_id');
        $perfilDestino = is_scalar($perfilId) ? Perfil::find($perfilId) : null;

        if ($perfilDestino && ! $this->user()->can('create', [User::class, $perfilDestino->slug])) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $alvo = $this->route('usuario');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($alvo?->id)],
            'perfil_id' => ['sometimes', 'integer', 'exists:perfis,id'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
