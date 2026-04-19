<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMonthlyReportSignedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:6144'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'Carica il report firmato prima di continuare.',
            'document.mimes' => 'Formato non valido. Usa PDF, JPG, JPEG o PNG.',
            'document.max' => 'Il file supera la dimensione massima consentita (6 MB).',
        ];
    }
}
