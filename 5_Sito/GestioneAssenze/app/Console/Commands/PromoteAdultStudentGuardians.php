<?php

namespace App\Console\Commands;

use App\Jobs\Mail\AdultGuardianTransitionMail;
use App\Models\Guardian;
use App\Models\OperationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PromoteAdultStudentGuardians extends Command
{
    protected $signature = 'students:promote-adult-guardian {--date= : Data di riferimento (YYYY-MM-DD)}';

    protected $description = 'Al compimento dei 18 anni assegna allo studente se stesso come tutore e invia notifiche';

    public function handle(): int
    {
        $referenceDate = $this->resolveReferenceDate();
        if (! $referenceDate) {
            return self::FAILURE;
        }

        $targetBirthDate = $referenceDate->copy()->subYears(18)->toDateString();

        $students = User::query()
            ->where('role', 'student')
            ->whereNotNull('birth_date')
            ->whereDate('birth_date', '<=', $targetBirthDate)
            ->with('guardians')
            ->get();

        if ($students->isEmpty()) {
            $this->info('Nessuno studente da aggiornare per la data '.$referenceDate->toDateString().'.');

            return self::SUCCESS;
        }

        $summary = [
            'processed' => 0,
            'skipped' => 0,
            'emails_sent' => 0,
            'emails_failed' => 0,
        ];

        foreach ($students as $student) {
            $studentEmail = strtolower(trim((string) $student->email));

            if ($studentEmail === '') {
                $summary['skipped']++;

                continue;
            }

            if ($this->alreadyHasSelfGuardianOnly($student, $studentEmail)) {
                $summary['skipped']++;

                continue;
            }

            $previousGuardianEmails = $student->guardians
                ->pluck('email')
                ->map(fn ($email) => strtolower(trim((string) $email)))
                ->filter(fn (string $email) => $email !== '')
                ->reject(fn (string $email) => $email === $studentEmail)
                ->unique()
                ->values()
                ->all();

            $selfGuardian = $this->assignSelfGuardian($student, $studentEmail);

            OperationLog::record(
                null,
                'student.guardian.self_assigned',
                'student',
                $student->id,
                [
                    'previous_guardian_emails' => $previousGuardianEmails,
                    'new_guardian_email' => $studentEmail,
                    'guardian_id' => $selfGuardian->id,
                    'effective_date' => $referenceDate->toDateString(),
                ],
                'INFO'
            );

            $teacherEmails = $this->resolveTeacherEmails($student->id, $referenceDate);
            $this->sendTransitionEmails(
                $student,
                $previousGuardianEmails,
                $teacherEmails,
                $referenceDate,
                $summary
            );

            $summary['processed']++;
        }

        $this->info('Studenti aggiornati: '.$summary['processed']);
        $this->line('Studenti saltati: '.$summary['skipped']);
        $this->line('Email inviate: '.$summary['emails_sent']);
        $this->line('Email fallite: '.$summary['emails_failed']);

        return self::SUCCESS;
    }

    private function resolveReferenceDate(): ?Carbon
    {
        $option = trim((string) $this->option('date'));
        if ($option === '') {
            return Carbon::today();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $option)->startOfDay();
        } catch (Throwable) {
            $this->error('Formato data non valido. Usa YYYY-MM-DD.');

            return null;
        }
    }

    private function alreadyHasSelfGuardianOnly(User $student, string $studentEmail): bool
    {
        $guardianEmails = $student->guardians
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();

        return $guardianEmails->count() === 1
            && $guardianEmails->first() === $studentEmail;
    }

    private function assignSelfGuardian(User $student, string $studentEmail): Guardian
    {
        return DB::transaction(function () use ($student, $studentEmail) {
            $now = now();
            $selfGuardian = Guardian::query()->firstOrNew([
                'email' => $studentEmail,
            ]);

            $studentName = trim((string) $student->name.' '.(string) $student->surname);
            $selfGuardian->name = $studentName !== '' ? $studentName : $studentEmail;
            $selfGuardian->save();

            DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', '!=', $selfGuardian->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'is_primary' => false,
                    'deactivated_at' => $now,
                    'updated_at' => $now,
                ]);

            $existingSelfPivot = DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $selfGuardian->id)
                ->first();

            if ($existingSelfPivot) {
                DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', $selfGuardian->id)
                    ->update([
                        'relationship' => 'Se stesso',
                        'is_primary' => true,
                        'is_active' => true,
                        'deactivated_at' => null,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('guardian_student')->insert([
                    'guardian_id' => $selfGuardian->id,
                    'student_id' => $student->id,
                    'relationship' => 'Se stesso',
                    'is_primary' => true,
                    'is_active' => true,
                    'deactivated_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $selfGuardian;
        });
    }

    /**
     * @param  array<int,string>  $previousGuardianEmails
     * @param  array<int,string>  $teacherEmails
     * @param  array{processed:int,skipped:int,emails_sent:int,emails_failed:int}  $summary
     */
    private function sendTransitionEmails(
        User $student,
        array $previousGuardianEmails,
        array $teacherEmails,
        Carbon $referenceDate,
        array &$summary
    ): void {
        $studentEmail = strtolower(trim((string) $student->email));
        $guardianRecipients = collect($previousGuardianEmails)
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter(fn (string $email) => $email !== '')
            ->values();
        $teacherRecipients = collect($teacherEmails)
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter(fn (string $email) => $email !== '')
            ->values();

        $recipientEmails = $guardianRecipients
            ->merge($teacherRecipients)
            ->push($studentEmail)
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();

        foreach ($recipientEmails as $recipientEmail) {
            $recipientRoles = $this->resolveRecipientRoles(
                $recipientEmail,
                $studentEmail,
                $guardianRecipients,
                $teacherRecipients
            );

            try {
                Mail::to($recipientEmail)->send(new AdultGuardianTransitionMail(
                    $student,
                    $guardianRecipients->all(),
                    $referenceDate
                ));

                OperationLog::record(
                    null,
                    'student.guardian.self_assignment_email.sent',
                    'student',
                    $student->id,
                    [
                        'recipient_email' => $recipientEmail,
                        'recipient_roles' => $recipientRoles,
                        'effective_date' => $referenceDate->toDateString(),
                    ],
                    'INFO'
                );

                $summary['emails_sent']++;
            } catch (Throwable $exception) {
                OperationLog::record(
                    null,
                    'student.guardian.self_assignment_email.failed',
                    'student',
                    $student->id,
                    [
                        'recipient_email' => $recipientEmail,
                        'recipient_roles' => $recipientRoles,
                        'effective_date' => $referenceDate->toDateString(),
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR'
                );

                report($exception);
                $summary['emails_failed']++;
            }
        }
    }

    /**
     * @param  Collection<int,string>  $guardianRecipients
     * @param  Collection<int,string>  $teacherRecipients
     * @return array<int,string>
     */
    private function resolveRecipientRoles(
        string $recipientEmail,
        string $studentEmail,
        Collection $guardianRecipients,
        Collection $teacherRecipients
    ): array {
        $roles = [];

        if ($recipientEmail === $studentEmail) {
            $roles[] = 'student';
        }

        if ($guardianRecipients->contains($recipientEmail)) {
            $roles[] = 'previous_guardian';
        }

        if ($teacherRecipients->contains($recipientEmail)) {
            $roles[] = 'teacher';
        }

        return empty($roles) ? ['other'] : $roles;
    }

    /**
     * @return array<int,string>
     */
    private function resolveTeacherEmails(int $studentId, Carbon $referenceDate): array
    {
        $date = $referenceDate->toDateString();

        return User::query()
            ->select('users.email')
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->join('class_user', 'class_user.class_id', '=', 'class_teacher.class_id')
            ->where('class_user.user_id', $studentId)
            ->whereNotNull('users.email')
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('class_user.start_date')
                    ->orWhereDate('class_user.start_date', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('class_user.end_date')
                    ->orWhereDate('class_user.end_date', '>=', $date);
            })
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('class_teacher.start_date')
                    ->orWhereDate('class_teacher.start_date', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('class_teacher.end_date')
                    ->orWhereDate('class_teacher.end_date', '>=', $date);
            })
            ->distinct()
            ->pluck('users.email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => $email !== '')
            ->values()
            ->all();
    }
}
