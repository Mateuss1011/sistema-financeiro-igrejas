<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('categoria'));
    }

    public function rules(): array
    {
        /** @var Categoria $categoria */
        $categoria = $this->route('categoria');

        return [
            // O tipo é definido na criação e nunca pode ser alterado.
            'tipo' => ['prohibited'],
            'nome' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('categorias', 'nome')
                    ->where('tipo', $categoria->tipo->value)
                    ->ignore($categoria->id),
            ],
            'ativa' => ['sometimes', 'boolean'],
        ];
    }
}
