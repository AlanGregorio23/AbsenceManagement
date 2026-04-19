<?php

namespace App\Http\Requests;

use App\Support\AnnualHoursLimitLabels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherApproveWithoutGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('teacher');
    }

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:1000'],
            'counts_40_hours' => ['required', 'boolean'],
            'counts_40_hours_comment' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(fn () => $this->boolean('counts_40_hours') === false),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required' => 'Inserisci un commento per approvare senza firma del tutore.',
            'counts_40_hours.required' => 'Specifica se l assenza rientra nel '.AnnualHoursLimitLabels::limit().'.',
            'counts_40_hours_comment.required' => 'Serve un commento quando l assenza non rientra nel '.AnnualHoursLimitLabels::limit().'.',
        ];
    }
}
