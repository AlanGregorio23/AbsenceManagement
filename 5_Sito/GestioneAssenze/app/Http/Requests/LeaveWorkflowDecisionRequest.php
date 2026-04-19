<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveWorkflowDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('laboratory_manager');
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
            'count_hours' => ['nullable', 'boolean'],
            'count_hours_comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
