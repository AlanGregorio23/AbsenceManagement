<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'string', 'max:10'],
            'section' => ['nullable', 'string', 'max:10'],
            'teacher_ids' => ['nullable', 'array'],
            'teacher_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Il nome della classe e obbligatorio.',
            'teacher_ids.array' => 'L elenco docenti non e valido.',
            'teacher_ids.*.exists' => 'Uno dei docenti selezionati non esiste.',
            'student_ids.array' => 'L elenco studenti non e valido.',
            'student_ids.*.exists' => 'Uno degli studenti selezionati non esiste.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $teacherIds = $this->input('teacher_ids', []);
        $studentIds = $this->input('student_ids', []);

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'year' => trim((string) $this->input('year', '')),
            'section' => strtoupper(trim((string) $this->input('section', ''))),
            'teacher_ids' => is_array($teacherIds) ? $teacherIds : [],
            'student_ids' => is_array($studentIds) ? $studentIds : [],
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $teacherIds = collect($this->input('teacher_ids', []))->map(fn ($id) => (int) $id)->unique()->values();
            $studentIds = collect($this->input('student_ids', []))->map(fn ($id) => (int) $id)->unique()->values();

            if ($teacherIds->isNotEmpty()) {
                $teacherCount = User::query()
                    ->whereIn('id', $teacherIds->all())
                    ->where('role', 'teacher')
                    ->count();

                if ($teacherCount !== $teacherIds->count()) {
                    $validator->errors()->add('teacher_ids', 'Puoi assegnare solo utenti con ruolo docente.');
                }
            }

            if ($studentIds->isNotEmpty()) {
                $studentCount = User::query()
                    ->whereIn('id', $studentIds->all())
                    ->where('role', 'student')
                    ->count();

                if ($studentCount !== $studentIds->count()) {
                    $validator->errors()->add('student_ids', 'Puoi assegnare solo utenti con ruolo studente.');
                }
            }
        });
    }
}
