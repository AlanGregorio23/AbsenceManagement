<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeacherExtendDelayDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('teacher');
    }

    public function rules(): array
    {
        return [
            'extension_days' => ['required', 'integer', 'min:1', 'max:30'],
            'comment' => ['required', 'string', 'max:1000'],
        ];
    }
}
