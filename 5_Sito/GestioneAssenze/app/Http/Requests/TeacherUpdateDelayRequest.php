<?php

namespace App\Http\Requests;

use App\Models\Delay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherUpdateDelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('teacher');
    }

    public function rules(): array
    {
        return [
            'delay_date' => ['required', 'date', 'before_or_equal:today'],
            'delay_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'motivation' => ['required', 'string', 'max:255'],
            'status' => [
                'nullable',
                'string',
                Rule::in([Delay::STATUS_REPORTED, Delay::STATUS_JUSTIFIED, Delay::STATUS_REGISTERED]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'delay_date.before_or_equal' => 'I ritardi non possono avere una data futura.',
        ];
    }
}
