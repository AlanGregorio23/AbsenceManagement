<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitDerivedLeaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('student');
    }

    public function rules(): array
    {
        return [
            'hours' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'hours.required' => 'Le ore richieste sono obbligatorie.',
            'hours.integer' => 'Le ore devono essere un numero intero.',
            'hours.min' => 'Le ore devono essere almeno 1.',
            'hours.max' => 'Le ore superano il limite consentito.',
        ];
    }
}
