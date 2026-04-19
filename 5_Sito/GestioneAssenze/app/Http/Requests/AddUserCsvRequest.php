<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddUserCsvRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'file' => 'required|mimes:csv,txt|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Il file e obbligatorio.',
            'file.mimes' => 'Il formato del file non e corretto.',
            'file.max' => 'Il file e troppo pesante.',
        ];
    }
}
