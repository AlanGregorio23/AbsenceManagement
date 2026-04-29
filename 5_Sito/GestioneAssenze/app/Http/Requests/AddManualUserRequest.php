<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddManualUserRequest extends FormRequest
{
    private const ALLOWED_ROLES = [
        'student',
        'teacher',
        'laboratory_manager',
        'admin',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'surname' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:255', Rule::unique(User::class)],
            'role' => ['required', 'string', Rule::in(self::ALLOWED_ROLES)],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Il nome e obbligatorio.',
            'surname.required' => 'Il cognome e obbligatorio.',
            'email.required' => 'L email e obbligatoria.',
            'email.email' => 'Inserisci un email valida.',
            'email.unique' => 'Questa email e gia utilizzata da un altro utente.',
            'role.required' => 'Il ruolo e obbligatorio.',
            'role.in' => 'Il ruolo selezionato non e valido.',
            'birth_date.date' => 'La data di nascita non e valida.',
            'birth_date.before_or_equal' => 'La data di nascita non puo essere futura.',
            'class_id.integer' => 'La classe selezionata non e valida.',
            'class_id.exists' => 'La classe selezionata non esiste.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $classId = $this->input('class_id');

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'surname' => trim((string) $this->input('surname', '')),
            'email' => strtolower(trim((string) $this->input('email', ''))),
            'role' => strtolower(trim((string) $this->input('role', ''))),
            'birth_date' => trim((string) $this->input('birth_date', '')) ?: null,
            'class_id' => ($classId === '' || $classId === null) ? null : $classId,
        ]);
    }
}
