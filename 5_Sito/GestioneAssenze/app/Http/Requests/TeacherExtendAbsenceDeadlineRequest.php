<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeacherExtendAbsenceDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('teacher');
    }

    public function rules(): array
    {
        return [
            'extension_days' => ['required', 'integer', 'min:1'],
            'comment' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'extension_days.required' => 'Specifica il numero di giorni di proroga.',
            'extension_days.min' => 'La proroga minima e di 1 giorno.',
            'comment.required' => 'Inserisci un commento per la proroga.',
        ];
    }
}
