<?php

namespace App\Http\Requests;

use App\Models\SchoolHoliday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchoolHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('admin');
    }

    public function rules(): array
    {
        $holiday = $this->route('holiday');
        $holidayId = $holiday instanceof SchoolHoliday ? $holiday->id : null;

        return [
            'holiday_date' => [
                'required',
                'date',
                Rule::unique('school_holidays', 'holiday_date')->ignore($holidayId),
            ],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'holiday_date.required' => 'La data vacanza e obbligatoria.',
            'holiday_date.date' => 'La data vacanza non e valida.',
            'holiday_date.unique' => 'Questa data vacanza e gia presente.',
            'label.max' => 'La descrizione vacanza e troppo lunga.',
        ];
    }
}
