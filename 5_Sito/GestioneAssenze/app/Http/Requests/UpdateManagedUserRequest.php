<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedUserRequest extends FormRequest
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
        $managedUser = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:120'],
            'surname' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', Rule::in(self::ALLOWED_ROLES)],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                Rule::unique(User::class)->ignore($managedUser?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Il nome e obbligatorio.',
            'surname.required' => 'Il cognome e obbligatorio.',
            'role.required' => 'Il ruolo e obbligatorio.',
            'role.in' => 'Il ruolo selezionato non e valido.',
            'birth_date.date' => 'La data di nascita non e valida.',
            'birth_date.before_or_equal' => 'La data di nascita non puo essere futura.',
            'email.required' => 'L email e obbligatoria.',
            'email.email' => 'Inserisci un email valida.',
            'email.unique' => 'Questa email e gia utilizzata da un altro utente.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'surname' => trim((string) $this->input('surname', '')),
            'role' => strtolower(trim((string) $this->input('role', ''))),
            'birth_date' => trim((string) $this->input('birth_date', '')) ?: null,
            'email' => strtolower(trim((string) $this->input('email', ''))),
        ]);
    }
}
