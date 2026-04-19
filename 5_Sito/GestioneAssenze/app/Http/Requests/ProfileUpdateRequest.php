<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Il nome e obbligatorio.',
            'email.email' => 'L email non e valida.',
            'avatar.image' => 'Il file avatar deve essere un immagine valida.',
            'avatar.mimes' => 'Avatar supportato: JPG, PNG o WEBP.',
            'avatar.max' => 'L avatar non puo superare 2 MB.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $submittedEmail = trim((string) $this->input('email', ''));
            $currentEmail = strtolower(trim((string) $this->user()?->email));

            if ($submittedEmail !== '' && $submittedEmail !== $currentEmail) {
                $validator->errors()->add(
                    'email',
                    'Non puoi cambiare la tua email. Deve farlo un admin.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $currentUser = $this->user();

        $this->merge([
            'name' => trim((string) $this->input('name', (string) ($currentUser?->name ?? ''))),
            'email' => strtolower(trim((string) $this->input('email', (string) ($currentUser?->email ?? '')))),
            'remove_avatar' => filter_var($this->input('remove_avatar', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
