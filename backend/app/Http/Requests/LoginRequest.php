<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Tetos de tamanho: o corpo de um login nunca precisa de mais que isso (evita hashear/processar lixo enorme).
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
