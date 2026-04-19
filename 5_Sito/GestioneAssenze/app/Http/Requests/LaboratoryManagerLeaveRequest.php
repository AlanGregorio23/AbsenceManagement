<?php

namespace App\Http\Requests;

use App\Models\AbsenceReason;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LaboratoryManagerLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('laboratory_manager');
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', 'student')
                    ->where('active', true)),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'hours' => ['nullable', 'integer', 'min:1'],
            'lessons_start' => ['nullable', 'array'],
            'lessons_start.*' => ['integer', 'between:1,11', 'distinct'],
            'lessons_end' => ['nullable', 'array'],
            'lessons_end.*' => ['integer', 'between:1,11', 'distinct'],
            'reason_choice' => ['required', 'string', 'max:255'],
            'motivation_custom' => ['nullable', 'string', 'max:255'],
            'motivation' => ['required', 'string', 'max:255'],
            'management_consent_confirmed' => ['nullable', 'boolean'],
            'destination' => ['required', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'Seleziona lo studente per cui creare il congedo.',
            'student_id.exists' => 'Lo studente selezionato non e valido.',
            'start_date.required' => 'La data di inizio e obbligatoria.',
            'end_date.after_or_equal' => 'La data di fine deve essere uguale o successiva alla data di inizio.',
            'hours.min' => 'Le ore devono essere almeno 1.',
            'lessons_start.array' => 'I periodi del giorno iniziale non sono validi.',
            'lessons_start.*.between' => 'I periodi scolastici devono essere compresi tra 1 e 11.',
            'lessons_end.array' => 'I periodi del giorno finale non sono validi.',
            'lessons_end.*.between' => 'I periodi scolastici devono essere compresi tra 1 e 11.',
            'reason_choice.required' => 'La motivazione e obbligatoria.',
            'motivation_custom.max' => 'La motivazione custom e troppo lunga.',
            'motivation.required' => 'La motivazione e obbligatoria.',
            'management_consent_confirmed.boolean' => 'Conferma consenso direzione non valida.',
            'destination.required' => 'La destinazione e obbligatoria.',
            'document.file' => 'Il documento allegato non e valido.',
            'document.mimes' => 'Il documento deve essere PDF o immagine (jpg/png).',
            'document.max' => 'Il documento supera la dimensione massima consentita.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $studentId = (int) $this->input('student_id');
            if ($studentId <= 0) {
                return;
            }

            $startDate = Carbon::parse((string) $this->input('start_date'))->startOfDay();
            $endDateRaw = $this->input('end_date');
            $endDate = $endDateRaw
                ? Carbon::parse((string) $endDateRaw)->startOfDay()
                : $startDate->copy();

            $startDateString = $startDate->toDateString();
            $endDateString = $endDate->toDateString();
            $overlappingLeaves = Leave::query()
                ->where('student_id', $studentId)
                ->whereNotIn('status', [
                    Leave::STATUS_REJECTED,
                    Leave::STATUS_FORWARDED_TO_MANAGEMENT,
                ])
                ->whereDate('start_date', '<=', $endDateString)
                ->whereDate('end_date', '>=', $startDateString)
                ->exists();

            if ($overlappingLeaves) {
                $validator->errors()->add(
                    'start_date',
                    'Lo studente ha gia una richiesta congedo su uno o piu giorni selezionati.'
                );
            }

            $lessonsStart = $this->normalizeLessonPeriods($this->input('lessons_start', []));
            $lessonsEnd = $this->normalizeLessonPeriods($this->input('lessons_end', []));
            $rawHours = trim((string) $this->input('hours', ''));

            if ($rawHours === '') {
                if ($lessonsStart === [] && $lessonsEnd !== []) {
                    $lessonsStart = $lessonsEnd;
                }

                if ($lessonsStart === []) {
                    $validator->errors()->add(
                        'lessons_start',
                        'Seleziona almeno un periodo scolastico per il giorno iniziale.'
                    );
                }

                if (! $startDate->isSameDay($endDate) && $lessonsEnd === []) {
                    $validator->errors()->add(
                        'lessons_end',
                        'Seleziona almeno un periodo scolastico per il giorno finale.'
                    );
                }
            }

            $reasonChoice = trim((string) $this->input('reason_choice'));
            if (strtolower($reasonChoice) === 'altro') {
                $customReason = trim((string) $this->input('motivation_custom', ''));
                if ($customReason === '') {
                    $validator->errors()->add(
                        'motivation_custom',
                        'La motivazione custom e obbligatoria quando selezioni Altro.'
                    );
                }
            }

            if ($reasonChoice === '' || strtolower($reasonChoice) === 'altro') {
                return;
            }

            $reasonRule = AbsenceReason::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($reasonChoice)])
                ->first();

            if ($reasonRule && (bool) $reasonRule->requires_management_consent && ! $this->boolean('management_consent_confirmed')) {
                $validator->errors()->add(
                    'management_consent_confirmed',
                    'Per questa motivazione devi prima ottenere il consenso della direzione.'
                );
            }

            if ($reasonRule && (bool) $reasonRule->requires_document_on_leave_creation && ! $this->hasFile('document')) {
                $validator->errors()->add(
                    'document',
                    'Per questa motivazione devi allegare il documento prima di inviare il congedo.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $incomingMotivation = $this->input(
            'motivation',
            $this->input('reason', $this->input('motivo'))
        );
        $reasonChoice = trim((string) $this->input('reason_choice', $incomingMotivation));
        if ($reasonChoice === '') {
            $reasonChoice = 'Altro';
        }

        $motivationCustom = trim((string) $this->input('motivation_custom', ''));
        $motivation = trim((string) $incomingMotivation);
        if (strtolower($reasonChoice) === 'altro') {
            $motivation = $motivationCustom !== ''
                ? 'Altro - '.$motivationCustom
                : 'Altro';
        } elseif ($motivation === '' || strtolower($motivation) === 'altro') {
            $motivation = $reasonChoice;
        }

        $this->merge([
            'student_id' => $this->input('student_id', $this->input('student')),
            'start_date' => $this->input('start_date', $this->input('startDate')),
            'end_date' => $this->input('end_date', $this->input('endDate')),
            'reason_choice' => $reasonChoice,
            'motivation_custom' => $motivationCustom,
            'motivation' => $motivation,
            'management_consent_confirmed' => $this->boolean('management_consent_confirmed'),
            'destination' => $this->input(
                'destination',
                $this->input('luogo', $this->input('destinazione'))
            ),
            'hours' => $this->input('hours', $this->input('requested_hours')),
            'lessons_start' => $this->normalizeLessonPeriods(
                $this->input('lessons_start', $this->input('requested_lessons_start', []))
            ),
            'lessons_end' => $this->normalizeLessonPeriods(
                $this->input('lessons_end', $this->input('requested_lessons_end', []))
            ),
        ]);
    }

    /**
     * @return array<int,int>
     */
    private function normalizeLessonPeriods(mixed $value): array
    {
        $source = $value;
        if (is_string($source)) {
            $decoded = json_decode($source, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $source = $decoded;
            } else {
                $source = explode(',', $source);
            }
        }

        if (! is_array($source)) {
            return [];
        }

        $normalized = [];
        foreach ($source as $period) {
            $periodNumber = (int) $period;
            if ($periodNumber < 1 || $periodNumber > 11) {
                continue;
            }

            $normalized[$periodNumber] = $periodNumber;
        }

        ksort($normalized);

        return array_values($normalized);
    }
}
