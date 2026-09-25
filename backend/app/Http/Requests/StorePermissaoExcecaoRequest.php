<?php

namespace App\Http\Requests;

use App\Enums\PermissaoExcecaoChave;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissaoExcecaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gerenciarExcecoes', User::class);
    }

    public function rules(): array
    {
        return [
            'permissao' => ['required', 'string', 'max:100', Rule::in(PermissaoExcecaoChave::valores())],
        ];
    }
}
