<?php

namespace App\Http\Requests;

use App\Support\NotificationTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*.web_enabled' => ['required', 'boolean'],
            'preferences.*.email_enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'preferences.required' => 'Le preferenze notifiche sono obbligatorie.',
            'preferences.array' => 'Le preferenze notifiche non sono valide.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $allowedKeys = NotificationTypeRegistry::eventKeysForRole($this->user()?->role);
            $preferences = $this->input('preferences', []);

            foreach (array_keys(is_array($preferences) ? $preferences : []) as $eventKey) {
                if (! in_array($eventKey, $allowedKeys, true)) {
                    $validator->errors()->add(
                        'preferences',
                        'Preferenza notifica non valida per il tuo ruolo.'
                    );

                    break;
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $rawPreferences = $this->input('preferences', []);
        $preferences = collect(is_array($rawPreferences) ? $rawPreferences : [])
            ->map(function ($value) {
                if (is_array($value)) {
                    return [
                        'web_enabled' => filter_var(
                            $value['web_enabled'] ?? true,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        'email_enabled' => filter_var(
                            $value['email_enabled'] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                    ];
                }

                return [
                    'web_enabled' => true,
                    'email_enabled' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                ];
            })
            ->all();

        $this->merge([
            'preferences' => $preferences,
        ]);
    }
}
