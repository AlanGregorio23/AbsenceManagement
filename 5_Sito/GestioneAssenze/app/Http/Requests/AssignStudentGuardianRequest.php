<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignStudentGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'guardian_name' => ['required', 'string', 'max:120'],
            'guardian_email' => ['required', 'string', 'email', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'guardian_name.required' => 'Il nome del tutore e obbligatorio.',
            'guardian_email.required' => 'L email del tutore e obbligatoria.',
            'guardian_email.email' => 'Inserisci un email valida per il tutore.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'guardian_name' => trim((string) $this->input('guardian_name', '')),
            'guardian_email' => strtolower(trim((string) $this->input('guardian_email', ''))),
            'relationship' => trim((string) $this->input('relationship', '')),
        ]);
    }
}
