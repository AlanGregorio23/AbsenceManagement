<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\Delay;
use App\Models\Leave;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\StudentStatusThresholdResolver;
use App\Support\SystemSettingsResolver;
use Carbon\Carbon;

class MonthlyReportDataService
{
    /**
     * @return array{summary:array<string,mixed>,class:?SchoolClass}
     */
    public function build(User $student, Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $absences = Absence::query()
            ->where('student_id', $student->id)
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->with([
                'medicalCertificates',
                'guardianConfirmations',
                'derivedFromLeave',
            ])
            ->get();

        $delays = Delay::query()
            ->where('student_id', $student->id)
            ->whereBetween('delay_datetime', [
                $monthStart->copy()->startOfDay(),
                $monthEnd->copy()->endOfDay(),
            ])
            ->with('guardianConfirmations')
            ->get();

        $leaves = Leave::query()
            ->where('student_id', $student->id)
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->get();

        $reasonRules = AbsenceReason::query()
            ->get()
            ->keyBy(fn (AbsenceReason $reason) => strtolower(trim((string) $reason->name)));
        $absenceSetting = SystemSettingsResolver::absenceSetting();

        $absenceHours = (int) $absences->sum('assigned_hours');
        $delayCount = (int) $delays->count();
        $leaveCount = (int) $leaves
            ->filter(fn (Leave $leave) => Leave::normalizeStatus($leave->status) !== Leave::STATUS_REJECTED)
            ->count();
        $leaveHours = (int) $leaves
            ->filter(fn (Leave $leave) => Leave::normalizeStatus($leave->status) !== Leave::STATUS_REJECTED)
            ->sum('requested_hours');

        $monthlyAbsence40Hours = (int) $absences
            ->filter(fn (Absence $absence) => $absence->resolveCounts40Hours($reasonRules))
            ->sum('assigned_hours');
        $monthly40Hours = $monthlyAbsence40Hours;

        $annual40Hours = Absence::countHoursForStudent((int) $student->id);
        $max40Hours = max((int) $absenceSetting->max_annual_hours, 0);
        $warning40Hours = max((int) $absenceSetting->warning_threshold_hours, 0);
        $remaining40Hours = max($max40Hours - $annual40Hours, 0);

        $requiredCertificates = $absences
            ->filter(fn (Absence $absence) => $absence->resolveMedicalCertificateRequired($absenceSetting))
            ->values();
        $uploadedCertificates = $requiredCertificates
            ->filter(function (Absence $absence) {
                if ($absence->medicalCertificates->isNotEmpty()) {
                    return true;
                }

                return filled(trim((string) ($absence->derivedFromLeave?->documentation_path ?? '')));
            })
            ->values();
        $missingCertificates = $requiredCertificates->count() - $uploadedCertificates->count();
        $missingCertificateCodes = $requiredCertificates
            ->filter(function (Absence $absence) {
                if ($absence->medicalCertificates->isNotEmpty()) {
                    return false;
                }

                return ! filled(trim((string) ($absence->derivedFromLeave?->documentation_path ?? '')));
            })
            ->map(fn (Absence $absence) => $this->absenceCode($absence->id))
            ->values()
            ->all();

        $unsignedAbsences = (int) $absences
            ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_REPORTED)
            ->filter(fn (Absence $absence) => ! $this->hasSignedAbsence($absence))
            ->count();

        $unsignedDelays = (int) $delays
            ->filter(fn (Delay $delay) => Delay::normalizeStatus($delay->status) === Delay::STATUS_REPORTED)
            ->filter(fn (Delay $delay) => ! $this->hasSignedDelay($delay))
            ->count();

        $arbitraryAbsences = (int) $absences
            ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_ARBITRARY)
            ->count();
        $arbitraryHours = (int) $absences
            ->filter(fn (Absence $absence) => Absence::normalizeStatus($absence->status) === Absence::STATUS_ARBITRARY)
            ->sum('assigned_hours');
        $registeredDelays = (int) $delays
            ->filter(fn (Delay $delay) => Delay::normalizeStatus($delay->status) === Delay::STATUS_REGISTERED)
            ->count();
        $statusRules = app(StudentStatusThresholdResolver::class)->teacherThresholds();
        $penalties = $this->buildDisciplinaryAlerts(
            $annual40Hours,
            $max40Hours,
            $warning40Hours,
            $registeredDelays,
            $arbitraryAbsences,
            (int) ($statusRules['warning_registered_delays'] ?? 0)
        );

        $class = $this->resolveClassForMonth($student, $monthStart, $monthEnd);

        return [
            'class' => $class,
            'summary' => [
                'month' => $monthStart->toDateString(),
                'month_label' => $this->monthLabel($monthStart),
                'absence_hours' => $absenceHours,
                'absence_count' => (int) $absences->count(),
                'delay_count' => $delayCount,
                'leave_count' => $leaveCount,
                'leave_hours' => $leaveHours,
                'hours_40' => [
                    'month_counted' => $monthly40Hours,
                    'annual_counted' => $annual40Hours,
                    'limit' => $max40Hours,
                    'remaining' => $remaining40Hours,
                ],
                'medical_certificates' => [
                    'required' => (int) $requiredCertificates->count(),
                    'uploaded' => (int) $uploadedCertificates->count(),
                    'missing' => (int) max($missingCertificates, 0),
                    'missing_absence_codes' => $missingCertificateCodes,
                ],
                'unsigned_absence_count' => $unsignedAbsences,
                'unsigned_delay_count' => $unsignedDelays,
                'arbitrary_hours' => $arbitraryHours,
                'penalties' => $penalties,
                'generated_at' => now()->toDateTimeString(),
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function buildDisciplinaryAlerts(
        int $annual40Hours,
        int $max40Hours,
        int $warning40Hours,
        int $registeredDelays,
        int $arbitraryAbsences,
        int $warningRegisteredDelays
    ): array {
        $alerts = [];

        if ($annual40Hours >= $max40Hours) {
            $alerts[] = 'Avviso automatico: superato il limite annuale ('.$annual40Hours.'/'.$max40Hours.' ore).';
        } elseif (
            $warning40Hours > 0
            && $annual40Hours >= min($warning40Hours, $max40Hours)
        ) {
            $alerts[] = 'Avviso automatico: studente vicino al limite annuale ('.$annual40Hours.'/'.$max40Hours.' ore).';
        }

        if ($arbitraryAbsences > 0) {
            $alerts[] = 'Possibile penalita: '.$arbitraryAbsences.' episodi di assenza arbitraria nel mese.';
        }

        if ($warningRegisteredDelays > 0 && $registeredDelays >= $warningRegisteredDelays) {
            $alerts[] = 'Possibile penalita: ritardi ripetuti nel mese ('.$registeredDelays.' registrazioni).';
        }

        if ($alerts === []) {
            $alerts[] = 'Nessuna segnalazione disciplinare automatica nel mese.';
        }

        return $alerts;
    }

    private function resolveClassForMonth(User $student, Carbon $monthStart, Carbon $monthEnd): ?SchoolClass
    {
        return SchoolClass::query()
            ->select('classes.*')
            ->join('class_user', 'class_user.class_id', '=', 'classes.id')
            ->where('class_user.user_id', $student->id)
            ->where(function ($query) use ($monthEnd) {
                $query
                    ->whereNull('class_user.start_date')
                    ->orWhereDate('class_user.start_date', '<=', $monthEnd->toDateString());
            })
            ->where(function ($query) use ($monthStart) {
                $query
                    ->whereNull('class_user.end_date')
                    ->orWhereDate('class_user.end_date', '>=', $monthStart->toDateString());
            })
            ->orderByDesc('class_user.start_date')
            ->first();
    }

    private function monthLabel(Carbon $monthStart): string
    {
        $months = [
            1 => 'Gennaio',
            2 => 'Febbraio',
            3 => 'Marzo',
            4 => 'Aprile',
            5 => 'Maggio',
            6 => 'Giugno',
            7 => 'Luglio',
            8 => 'Agosto',
            9 => 'Settembre',
            10 => 'Ottobre',
            11 => 'Novembre',
            12 => 'Dicembre',
        ];

        return ($months[(int) $monthStart->month] ?? $monthStart->format('m')).' '.$monthStart->year;
    }

    private function absenceCode(int $absenceId): string
    {
        return 'A-'.str_pad((string) $absenceId, 4, '0', STR_PAD_LEFT);
    }

    private function hasSignedAbsence(Absence $absence): bool
    {
        return $absence->guardianConfirmations
            ->contains(fn ($confirmation) => $this->isConfirmationSigned($confirmation));
    }

    private function hasSignedDelay(Delay $delay): bool
    {
        return $delay->guardianConfirmations
            ->contains(fn ($confirmation) => $this->isConfirmationSigned($confirmation));
    }

    private function isConfirmationSigned(object $confirmation): bool
    {
        $status = strtolower(trim((string) ($confirmation->status ?? '')));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }
}
