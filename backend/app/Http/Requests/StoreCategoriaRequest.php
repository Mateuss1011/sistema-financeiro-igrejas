<?php

namespace App\Http\Requests;

use App\Enums\TipoCategoria;
use App\Models\Categoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Categoria::class);
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required', 'string', 'max:100',
                Rule::unique('categorias', 'nome')->where('tipo', $this->input('tipo')),
            ],
            'tipo' => ['required', Rule::enum(TipoCategoria::class)],
        ];
    }
}
