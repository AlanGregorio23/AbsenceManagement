<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMedicalCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('student');
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'Carica un certificato medico.',
            'document.mimes' => 'Il certificato deve essere PDF o immagine (jpg/png).',
            'document.max' => 'Il file supera la dimensione massima consentita.',
        ];
    }
}
