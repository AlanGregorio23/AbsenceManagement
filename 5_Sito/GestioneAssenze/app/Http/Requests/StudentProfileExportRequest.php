<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentProfileExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sections = [
            'all',
            'student',
            'guardians',
            'summary',
            'absences',
            'delays',
            'leaves',
        ];

        return [
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', Rule::in($sections)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function messages(): array
    {
        return [
            'sections.array' => 'Selezione export non valida.',
            'sections.*.in' => 'Sezione export non valida.',
            'date_from.date' => 'Data inizio non valida.',
            'date_to.date' => 'Data fine non valida.',
            'date_to.after_or_equal' => 'La data fine deve essere successiva o uguale alla data inizio.',
        ];
    }
}
