<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportSchoolHolidaysPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'calendar_pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ];
    }

    public function messages(): array
    {
        return [
            'calendar_pdf.required' => 'Seleziona un PDF calendario prima dell import.',
            'calendar_pdf.file' => 'Il file calendario non e valido.',
            'calendar_pdf.mimes' => 'Il calendario deve essere in formato PDF.',
            'calendar_pdf.max' => 'Il PDF calendario supera la dimensione massima consentita.',
        ];
    }
}
