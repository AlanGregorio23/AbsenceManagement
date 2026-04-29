<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'absence' => ['required', 'array'],
            'absence.max_annual_hours' => ['required', 'integer', 'min:1'],
            'absence.warning_threshold_hours' => ['required', 'integer', 'min:0'],
            'absence.vice_director_email' => ['nullable', 'email:filter', 'max:255'],
            'absence.guardian_signature_required' => ['required', 'boolean'],
            'absence.medical_certificate_days' => ['required', 'integer', 'min:0'],
            'absence.medical_certificate_max_days' => ['required', 'integer', 'min:0'],
            'absence.absence_countdown_days' => ['required', 'integer', 'min:0'],
            'absence.pre_expiry_warning_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'absence.leave_request_notice_working_hours' => ['required', 'integer', 'min:0', 'max:240'],
            'reasons' => ['required', 'array'],
            'reasons.*.id' => ['nullable', 'integer', 'exists:absence_reasons,id'],
            'reasons.*.name' => ['required', 'string', 'max:255'],
            'reasons.*.counts_40_hours' => ['required', 'boolean'],
            'reasons.*.requires_management_consent' => ['sometimes', 'boolean'],
            'reasons.*.requires_document_on_leave_creation' => ['sometimes', 'boolean'],
            'reasons.*.management_consent_note' => ['nullable', 'string', 'max:2000'],
            'delay' => ['required', 'array'],
            'delay.minutes_threshold' => ['required', 'integer', 'min:0'],
            'delay.guardian_signature_required' => ['required', 'boolean'],
            'delay.deadline_active' => ['sometimes', 'boolean'],
            'delay.deadline_business_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'delay.justification_business_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'delay.pre_expiry_warning_business_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'delay.pre_expiry_warning_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'delay.first_semester_end_day' => ['required', 'integer', 'min:1', 'max:31'],
            'delay.first_semester_end_month' => ['required', 'integer', 'min:1', 'max:12'],
            'delay_rules' => ['required', 'array'],
            'delay_rules.*.id' => ['nullable', 'integer', 'exists:delay_rules,id'],
            'delay_rules.*.min_delays' => ['required', 'integer', 'min:0'],
            'delay_rules.*.max_delays' => ['nullable', 'integer', 'min:0'],
            'delay_rules.*.actions' => ['required', 'array'],
            'delay_rules.*.actions.*.type' => [
                'required',
                'string',
                'in:none,notify_student,notify_guardian,notify_teacher,extra_activity_notice,conduct_penalty',
            ],
            'delay_rules.*.actions.*.detail' => ['nullable', 'string', 'max:255'],
            'delay_rules.*.info_message' => ['nullable', 'string', 'max:255'],
            'logs' => ['required', 'array'],
            'logs.interaction_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'logs.error_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'login' => ['required', 'array'],
            'login.max_attempts' => ['required', 'integer', 'min:3', 'max:10'],
            'login.decay_seconds' => ['required', 'integer', 'min:60', 'max:1800'],
            'login.forgot_password_max_attempts' => ['required', 'integer', 'min:3', 'max:20'],
            'login.forgot_password_decay_seconds' => ['required', 'integer', 'min:60', 'max:1800'],
            'login.reset_password_max_attempts' => ['required', 'integer', 'min:3', 'max:20'],
            'login.reset_password_decay_seconds' => ['required', 'integer', 'min:60', 'max:1800'],
        ];
    }
}
