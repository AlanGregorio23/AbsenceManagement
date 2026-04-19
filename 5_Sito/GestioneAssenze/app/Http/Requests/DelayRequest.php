<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('student');
    }

    public function rules(): array
    {
        return [
            'delay_date' => ['required', 'date', 'before_or_equal:today'],
            'delay_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'motivation' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'delay_date.required' => 'La data e obbligatoria.',
            'delay_date.date' => 'La data non e valida.',
            'delay_date.before_or_equal' => 'I ritardi non possono avere una data futura.',
            'delay_minutes.required' => 'I minuti del ritardo sono obbligatori.',
            'delay_minutes.integer' => 'I minuti del ritardo devono essere un numero intero.',
            'delay_minutes.min' => 'Inserisci almeno 1 minuto di ritardo.',
            'delay_minutes.max' => 'Puoi inserire al massimo 480 minuti di ritardo.',
            'motivation.required' => 'Il commento e obbligatorio.',
            'motivation.max' => 'La motivazione e troppo lunga.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'delay_date' => $this->input('delay_date', $this->input('date')),
            'delay_minutes' => $this->input(
                'delay_minutes',
                $this->input('minutes', $this->input('delay_hours', $this->input('delay_count')))
            ),
            'motivation' => $this->input(
                'motivation',
                $this->input('reason', $this->input('motivo'))
            ),
        ]);

        if ($this->has('motivation')) {
            $this->merge([
                'motivation' => trim((string) $this->input('motivation')),
            ]);
        }

        if ($this->has('delay_minutes')) {
            $normalizedMinutes = str_replace(',', '.', (string) $this->input('delay_minutes'));
            $this->merge([
                'delay_minutes' => $normalizedMinutes,
            ]);
        }
    }
}
