<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentStatusSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user
            && in_array((string) $user->role, ['teacher', 'laboratory_manager'], true);
    }

    public function rules(): array
    {
        return [
            'absence_warning_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'absence_critical_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'delay_warning_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'delay_critical_percent' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $absenceWarning = (int) $this->input('absence_warning_percent');
            $absenceCritical = (int) $this->input('absence_critical_percent');
            $delayWarning = (int) $this->input('delay_warning_percent');
            $delayCritical = (int) $this->input('delay_critical_percent');

            if ($absenceCritical < $absenceWarning) {
                $validator->errors()->add(
                    'absence_critical_percent',
                    'La soglia rossa assenze deve essere maggiore o uguale alla soglia gialla.'
                );
            }

            if ($delayCritical < $delayWarning) {
                $validator->errors()->add(
                    'delay_critical_percent',
                    'La soglia rossa ritardi deve essere maggiore o uguale alla soglia gialla.'
                );
            }
        });
    }
}
