<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Leave;
use App\Models\OperationLog;
use App\Models\User;
use App\Support\AnnualHoursLimitLabels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveAbsenceDraftService
{
    public function shouldRegisterNow(Leave $leave): bool
    {
        $startDate = $leave->start_date
            ? Carbon::parse($leave->start_date)->startOfDay()
            : Carbon::today()->startOfDay();

        return ! $startDate->isFuture();
    }

    public function registrationAvailableFromLabel(Leave $leave): string
    {
        $startDate = $leave->start_date
            ? Carbon::parse($leave->start_date)->startOfDay()
            : Carbon::today()->startOfDay();

        return $startDate->format('d/m/Y');
    }

    public function registerFromLeave(
        Leave $leave,
        ?User $actor = null,
        ?Request $request = null
    ): Absence {
        $status = Absence::STATUS_DRAFT;
        $countHoursComment = (bool) $leave->count_hours
            ? null
            : ($this->normalizeComment($leave->count_hours_comment) ?: AnnualHoursLimitLabels::leaveExceptionComment());
        $leaveCode = $this->formatLeaveCode($leave->id);
        $teacherComment = 'Generata da congedo '.$leaveCode.'. '
            .'Bozza in attesa di invio studente come assenza ufficiale.';
        $medicalDeadlineDate = ($leave->end_date ?? $leave->start_date)
            ? Carbon::parse($leave->end_date ?? $leave->start_date)->startOfDay()
            : Carbon::today()->startOfDay();
        $medicalDeadline = Absence::calculateMedicalCertificateDeadline($medicalDeadlineDate)->toDateString();

        $absence = $leave->registered_absence_id
            ? Absence::query()->find($leave->registered_absence_id)
            : null;

        if ($absence) {
            $absence->update([
                'derived_from_leave_id' => $leave->id,
                'start_date' => $leave->start_date?->toDateString(),
                'end_date' => $leave->end_date?->toDateString(),
                'reason' => (string) $leave->reason,
                'status' => $status,
                'assigned_hours' => (int) $leave->requested_hours,
                'counts_40_hours' => (bool) $leave->count_hours,
                'counts_40_hours_comment' => $countHoursComment,
                'approved_without_guardian' => false,
                'teacher_comment' => $teacherComment,
                'medical_certificate_required' => false,
                'medical_certificate_deadline' => $medicalDeadline,
                'hours_decided_at' => null,
                'hours_decided_by' => null,
            ]);
        } else {
            $absence = Absence::create([
                'student_id' => $leave->student_id,
                'derived_from_leave_id' => $leave->id,
                'start_date' => $leave->start_date?->toDateString(),
                'end_date' => $leave->end_date?->toDateString(),
                'reason' => (string) $leave->reason,
                'status' => $status,
                'assigned_hours' => (int) $leave->requested_hours,
                'counts_40_hours' => (bool) $leave->count_hours,
                'counts_40_hours_comment' => $countHoursComment,
                'approved_without_guardian' => false,
                'teacher_comment' => $teacherComment,
                'medical_certificate_required' => false,
                'medical_certificate_deadline' => $medicalDeadline,
                'hours_decided_at' => null,
                'hours_decided_by' => null,
            ]);
        }

        OperationLog::record(
            $actor,
            'leave.registered_as_absence',
            'leave',
            $leave->id,
            [
                'registered_absence_id' => $absence->id,
                'status' => $absence->status,
                'count_hours' => (bool) $leave->count_hours,
            ],
            'INFO',
            $request
        );

        return $absence;
    }

    public function registerDueLeaves(?int $studentId = null): int
    {
        $today = Carbon::today()->toDateString();

        $query = Leave::query()
            ->where('status', Leave::STATUS_REGISTERED)
            ->whereNull('registered_absence_id')
            ->whereDate('start_date', '<=', $today)
            ->orderBy('start_date')
            ->orderBy('id');

        if ($studentId !== null) {
            $query->where('student_id', $studentId);
        }

        $leaveIds = $query->pluck('id')->all();
        $registered = 0;

        foreach ($leaveIds as $leaveId) {
            $wasRegistered = DB::transaction(function () use ($leaveId): bool {
                $leave = Leave::query()
                    ->whereKey($leaveId)
                    ->lockForUpdate()
                    ->first();

                if (! $leave) {
                    return false;
                }

                if (Leave::normalizeStatus((string) $leave->status) !== Leave::STATUS_REGISTERED) {
                    return false;
                }

                if ($leave->registered_absence_id) {
                    return false;
                }

                if (! $this->shouldRegisterNow($leave)) {
                    return false;
                }

                $absence = $this->registerFromLeave($leave);
                $leave->update([
                    'registered_absence_id' => $absence->id,
                    'registered_at' => $leave->registered_at ?? now(),
                ]);

                OperationLog::record(
                    null,
                    'leave.registered',
                    'leave',
                    $leave->id,
                    [
                        'status' => $leave->status,
                        'registered_absence_id' => $absence->id,
                        'source' => 'automatic_due_registration',
                    ],
                    'INFO'
                );

                return true;
            });

            if ($wasRegistered) {
                $registered++;
            }
        }

        return $registered;
    }

    private function normalizeComment(mixed $value): string
    {
        return trim((string) $value);
    }

    private function formatLeaveCode(int $leaveId): string
    {
        return 'C-'.str_pad((string) $leaveId, 4, '0', STR_PAD_LEFT);
    }
}
