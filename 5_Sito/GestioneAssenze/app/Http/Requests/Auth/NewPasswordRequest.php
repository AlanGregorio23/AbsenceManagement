<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

class NewPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'Il token e obbligatorio.',
            'email.required' => "L'email e obbligatoria.",
            'email.email' => "L'email deve essere valida.",
            'password.required' => 'La password e obbligatoria.',
            'password.confirmed' => 'La conferma della password non corrisponde.',
        ];
    }
}
