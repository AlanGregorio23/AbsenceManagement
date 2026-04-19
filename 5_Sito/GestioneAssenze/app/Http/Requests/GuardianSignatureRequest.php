<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardianSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'consent' => ['accepted'],
            'signature_data' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Inserisci nome e cognome del firmatario.',
            'consent.accepted' => 'Devi confermare la dichiarazione prima di firmare.',
            'signature_data.required' => 'Firma grafica obbligatoria.',
        ];
    }
}
