<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'La password attuale è obbligatoria.',
            'current_password.current_password' => 'La password attuale non è corretta.',
            'password.required' => 'La nuova password è obbligatoria.',
            'password.confirmed' => 'La conferma della nuova password non corrisponde.',
        ];
    }
}
