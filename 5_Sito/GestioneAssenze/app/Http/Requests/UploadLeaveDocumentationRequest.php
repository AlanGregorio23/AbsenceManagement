<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadLeaveDocumentationRequest extends FormRequest
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
            'document.required' => 'Carica un documento prima di inviare.',
            'document.file' => 'Il file selezionato non e valido.',
            'document.mimes' => 'Il documento deve essere PDF o immagine (jpg/png).',
            'document.max' => 'Il documento supera la dimensione massima consentita.',
        ];
    }
}
