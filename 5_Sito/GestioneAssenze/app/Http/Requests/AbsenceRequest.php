<?php

namespace App\Http\Requests;

use App\Models\Absence;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AbsenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('student');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
            'hours' => ['required', 'integer', 'min:1'],
            'reason_choice' => ['required', 'string', 'max:255'],
            'motivation_custom' => ['nullable', 'string', 'max:255'],
            'motivation' => ['required', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'La data di inizio e obbligatoria.',
            'start_date.date' => 'La data di inizio non e valida.',
            'start_date.before_or_equal' => 'Le assenze non possono avere una data futura.',
            'end_date.date' => 'La data di fine non e valida.',
            'end_date.after_or_equal' => 'La data di fine deve essere successiva o uguale alla data di inizio.',
            'end_date.before_or_equal' => 'Le assenze non possono avere una data futura.',
            'hours.required' => 'Le ore richieste sono obbligatorie.',
            'hours.integer' => 'Le ore richieste devono essere un numero intero.',
            'hours.min' => 'Le ore richieste devono essere almeno 1.',
            'reason_choice.required' => 'La motivazione e obbligatoria.',
            'motivation.required' => 'La motivazione e obbligatoria.',
            'motivation_custom.max' => 'La motivazione custom e troppo lunga.',
            'motivation.max' => 'La motivazione e troppo lunga.',
            'document.file' => 'Il documento non e valido.',
            'document.mimes' => 'Il certificato deve essere PDF o immagine (jpg/png).',
            'document.max' => 'Il documento supera la dimensione massima consentita.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            if (! $user) {
                return;
            }

            $startDateRaw = (string) $this->input('start_date', '');
            if ($startDateRaw === '') {
                return;
            }

            $startDate = Carbon::parse($startDateRaw)->startOfDay();
            $endDateRaw = $this->input('end_date');
            $endDate = $endDateRaw
                ? Carbon::parse((string) $endDateRaw)->startOfDay()
                : $startDate->copy();

            $startDateString = $startDate->toDateString();
            $endDateString = $endDate->toDateString();
            $overlapExists = Absence::query()
                ->where('student_id', $user->id)
                ->whereDate('start_date', '<=', $endDateString)
                ->whereDate('end_date', '>=', $startDateString)
                ->exists();

            if (! $overlapExists) {
                return;
            }

            $validator->errors()->add(
                'start_date',
                'Hai gia una richiesta assenza su uno o piu giorni selezionati.'
            );
        });
    }

    protected function prepareForValidation(): void
    {
        $incomingMotivation = $this->input(
            'motivation',
            $this->input('reason', $this->input('motivo'))
        );
        $reasonChoice = trim((string) $this->input('reason_choice', ''));
        if ($reasonChoice === '') {
            $reasonChoice = trim((string) $incomingMotivation);
        }

        $motivationCustom = trim((string) $this->input('motivation_custom', ''));
        $motivation = trim((string) $incomingMotivation);
        if ($reasonChoice !== '' && strtolower($reasonChoice) === 'altro') {
            $motivation = $motivationCustom !== ''
                ? 'Altro - '.$motivationCustom
                : 'Altro';
        } elseif ($reasonChoice !== '' && ($motivation === '' || strtolower($motivation) === 'altro')) {
            $motivation = $reasonChoice;
        }

        $this->merge([
            'start_date' => $this->input('start_date', $this->input('startDate')),
            'end_date' => $this->input('end_date', $this->input('endDate')),
            'reason_choice' => $reasonChoice,
            'motivation_custom' => $motivationCustom,
            'motivation' => $motivation,
            'hours' => $this->input('hours', $this->input('assigned_hours')),
        ]);
    }
}
