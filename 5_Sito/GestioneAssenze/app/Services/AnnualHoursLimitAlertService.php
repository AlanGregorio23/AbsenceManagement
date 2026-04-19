<?php

namespace App\Services;

use App\Mail\AnnualHoursLimitReachedGuardianMail;
use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\OperationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class AnnualHoursLimitAlertService
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRecipientResolver $recipients
    ) {}

    public function notifyIfReached(int $studentId): void
    {
        if ($studentId <= 0) {
            return;
        }

        $absenceSetting = AbsenceSetting::query()->first();
        if (! $absenceSetting) {
            return;
        }

        $maxAnnualHours = max((int) $absenceSetting->max_annual_hours, 0);
        if ($maxAnnualHours <= 0) {
            return;
        }

        $absenceHours = Absence::countHoursForStudent($studentId);
        $totalHours = $absenceHours;

        if ($totalHours < $maxAnnualHours) {
            return;
        }

        [$schoolYearLabel, $schoolYearStart, $schoolYearEnd] = $this->resolveSchoolYearWindow(Carbon::today());

        $alreadyNotified = OperationLog::query()
            ->where('action', 'annual_hours.limit_reached.notified')
            ->where('entity', 'user')
            ->where('entity_id', $studentId)
            ->whereBetween('created_at', [
                $schoolYearStart->toDateTimeString(),
                $schoolYearEnd->toDateTimeString(),
            ])
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $student = User::query()
            ->with('guardians')
            ->find($studentId);

        if (! $student) {
            return;
        }

        $studentName = trim($student->fullName());
        if ($studentName === '') {
            $studentName = 'Studente';
        }

        $this->dispatcher->notifyUser(
            $student,
            'student_annual_hours_limit_reached',
            [
                'title' => 'Limite ore annuali raggiunto',
                'body' => 'Hai raggiunto '.$totalHours.' ore su '.$maxAnnualHours.' ore annuali.',
                'url' => route('student.history'),
                'icon' => 'absence',
                'mail_subject' => 'Limite ore annuali raggiunto',
            ]
        );

        $teacherRecipients = $this->recipients->teachersForStudent($studentId);
        $this->dispatcher->notifyUsers(
            $teacherRecipients,
            'teacher_student_annual_hours_limit_reached',
            [
                'title' => 'Studente al limite ore annuali',
                'body' => $studentName.' ha raggiunto '.$totalHours.' ore su '.$maxAnnualHours.' ore annuali.',
                'url' => route('teacher.history'),
                'icon' => 'absence',
                'mail_subject' => 'Studente al limite ore annuali',
            ]
        );

        $guardianRecipients = $student->guardians
            ->filter(fn ($guardian) => filled($guardian->email))
            ->unique(fn ($guardian) => strtolower(trim((string) $guardian->email)))
            ->values();

        foreach ($guardianRecipients as $guardian) {
            Mail::to((string) $guardian->email)->send(
                new AnnualHoursLimitReachedGuardianMail(
                    guardianName: trim((string) ($guardian->name ?? 'Tutore')),
                    studentName: $studentName,
                    totalHours: $totalHours,
                    maxHours: $maxAnnualHours,
                    schoolYear: $schoolYearLabel
                )
            );
        }

        OperationLog::record(
            null,
            'annual_hours.limit_reached.notified',
            'user',
            $studentId,
            [
                'student_id' => $studentId,
                'student_name' => $studentName,
                'total_hours' => $totalHours,
                'max_annual_hours' => $maxAnnualHours,
                'school_year' => $schoolYearLabel,
                'teachers_notified' => $teacherRecipients->count(),
                'guardians_notified' => $guardianRecipients->count(),
            ],
            'INFO'
        );
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function resolveSchoolYearWindow(Carbon $today): array
    {
        $startYear = $today->month >= 8 ? $today->year : $today->year - 1;
        $endYear = $startYear + 1;

        $start = Carbon::create($startYear, 8, 1, 0, 0, 0);
        $end = Carbon::create($endYear, 7, 31, 23, 59, 59);

        return [$startYear.'/'.$endYear, $start, $end];
    }
}
