<?php

namespace App\Http\Requests;

use App\Models\Delay;
use Illuminate\Foundation\Http\FormRequest;

class TeacherRejectDelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('teacher');
    }

    public function rules(): array
    {
        $delay = $this->route('delay');
        $statusCode = $delay instanceof Delay ? Delay::normalizeStatus($delay->status) : null;

        return [
            'comment' => [
                $statusCode === Delay::STATUS_REPORTED ? 'nullable' : 'required',
                'string',
                'max:1000',
            ],
        ];
    }
}
