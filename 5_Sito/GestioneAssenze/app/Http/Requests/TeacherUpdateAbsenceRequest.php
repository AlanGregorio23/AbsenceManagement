<?php

namespace App\Http\Requests;

use App\Models\Absence;
use App\Support\AnnualHoursLimitLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherUpdateAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('teacher');
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
            'hours' => ['required', 'integer', 'min:1'],
            'motivation' => ['required', 'string', 'max:255'],
            'status' => [
                'nullable',
                'string',
                Rule::in([Absence::STATUS_REPORTED, Absence::STATUS_JUSTIFIED, Absence::STATUS_ARBITRARY]),
            ],
            'counts_40_hours' => ['required', 'boolean'],
            'counts_40_hours_comment' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(fn () => $this->boolean('counts_40_hours') === false),
            ],
            'comment' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'La data di inizio e obbligatoria.',
            'start_date.before_or_equal' => 'Le assenze non possono avere una data futura.',
            'end_date.after_or_equal' => 'La data di fine deve essere uguale o successiva alla data di inizio.',
            'end_date.before_or_equal' => 'Le assenze non possono avere una data futura.',
            'hours.required' => 'Le ore sono obbligatorie.',
            'hours.min' => 'Le ore devono essere almeno 1.',
            'motivation.required' => 'La motivazione e obbligatoria.',
            'status.in' => 'Lo stato selezionato non e valido.',
            'counts_40_hours.required' => 'Specifica se l assenza rientra nel '.AnnualHoursLimitLabels::limit().'.',
            'counts_40_hours_comment.required' => 'Serve un commento quando l assenza non rientra nel '.AnnualHoursLimitLabels::limit().'.',
            'comment.required' => 'Inserisci un commento obbligatorio per la modifica.',
        ];
    }
}
