<?php

namespace App\Http\Requests;

use App\Support\AnnualHoursLimitLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('laboratory_manager');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('comment')) {
            $this->merge([
                'comment' => trim((string) $this->input('comment')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'hours' => ['required', 'integer', 'min:1'],
            'motivation' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'count_hours' => ['required', 'boolean'],
            'count_hours_comment' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(fn () => $this->boolean('count_hours') === false),
            ],
            'comment' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'count_hours_comment.required' => 'Commento obbligatorio quando il congedo non rientra nel '.AnnualHoursLimitLabels::limit().'.',
            'comment.required' => 'Inserisci un commento obbligatorio per la modifica.',
        ];
    }
}
