<?php

namespace App\Models;

use App\Support\WorkingCalendar;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use HasFactory;

    public const STATUS_AWAITING_GUARDIAN_SIGNATURE = 'awaiting_guardian_signature';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_PRE_APPROVED = 'pre_approved';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DOCUMENTATION_REQUESTED = 'documentation_requested';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_FORWARDED_TO_MANAGEMENT = 'forwarded_to_management';

    public const DEFAULT_REQUEST_NOTICE_WORKING_HOURS = 24;

    private const LESSON_SLOTS = [
        1 => '08:20 - 09:05',
        2 => '09:05 - 09:50',
        3 => '10:05 - 10:50',
        4 => '10:50 - 11:35',
        5 => '11:35 - 12:20',
        6 => '12:30 - 13:15',
        7 => '13:15 - 14:00',
        8 => '14:00 - 14:45',
        9 => '15:00 - 15:45',
        10 => '15:45 - 16:30',
        11 => '16:30 - 17:15',
    ];

    protected $fillable = [
        'student_id',
        'created_by',
        'created_at_custom',
        'start_date',
        'end_date',
        'requested_hours',
        'hours_limit_exceeded_at_request',
        'hours_limit_value_at_request',
        'hours_limit_max_at_request',
        'requested_lessons',
        'reason',
        'destination',
        'status',
        'approved_without_guardian',
        'count_hours',
        'count_hours_comment',
        'workflow_comment',
        'documentation_request_comment',
        'documentation_path',
        'documentation_uploaded_at',
        'registered_at',
        'registered_by',
        'registered_absence_id',
        'hours_decision_at',
        'hours_decision_by',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function registeredAbsence()
    {
        return $this->belongsTo(Absence::class, 'registered_absence_id');
    }

    public function hoursDecider()
    {
        return $this->belongsTo(User::class, 'hours_decision_by');
    }

    public function confirmationTokens()
    {
        return $this->hasMany(LeaveConfirmationToken::class);
    }

    public function guardianConfirmations()
    {
        return $this->hasMany(GuardianLeaveConfirmation::class);
    }

    public function approvals()
    {
        return $this->hasMany(LeaveApproval::class);
    }

    public function emailNotifications()
    {
        return $this->hasMany(LeaveEmailNotification::class);
    }

    protected function casts(): array
    {
        return [
            'created_at_custom' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'requested_lessons' => 'array',
            'approved_without_guardian' => 'boolean',
            'count_hours' => 'boolean',
            'hours_limit_exceeded_at_request' => 'boolean',
            'documentation_uploaded_at' => 'datetime',
            'registered_at' => 'datetime',
            'hours_decision_at' => 'datetime',
        ];
    }

    public static function normalizeStatus(?string $status): string
    {
        return match ($status) {
            'pending', self::STATUS_AWAITING_GUARDIAN_SIGNATURE => self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
            'pre-approved', self::STATUS_PRE_APPROVED => self::STATUS_PRE_APPROVED,
            'documentation-requested', self::STATUS_DOCUMENTATION_REQUESTED => self::STATUS_DOCUMENTATION_REQUESTED,
            'in-review', self::STATUS_IN_REVIEW => self::STATUS_IN_REVIEW,
            'approved', self::STATUS_APPROVED => self::STATUS_APPROVED,
            'registered', self::STATUS_REGISTERED => self::STATUS_REGISTERED,
            'forwarded-to-management', self::STATUS_FORWARDED_TO_MANAGEMENT => self::STATUS_FORWARDED_TO_MANAGEMENT,
            'rejected', self::STATUS_REJECTED => self::STATUS_REJECTED,
            'signed', self::STATUS_SIGNED => self::STATUS_SIGNED,
            default => self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
        };
    }

    public static function openStatuses(): array
    {
        return [
            self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
            self::STATUS_SIGNED,
            self::STATUS_PRE_APPROVED,
            self::STATUS_APPROVED,
            self::STATUS_DOCUMENTATION_REQUESTED,
            self::STATUS_IN_REVIEW,
        ];
    }

    public static function statusLabel(string $statusCode): string
    {
        return match (self::normalizeStatus($statusCode)) {
            self::STATUS_AWAITING_GUARDIAN_SIGNATURE => 'In attesa firma tutore',
            self::STATUS_SIGNED => 'Firmata',
            self::STATUS_PRE_APPROVED => 'Override firma tutore',
            self::STATUS_APPROVED => 'Approvata',
            self::STATUS_DOCUMENTATION_REQUESTED => 'Documentazione richiesta',
            self::STATUS_IN_REVIEW => 'In valutazione',
            self::STATUS_FORWARDED_TO_MANAGEMENT => 'Inoltrata in direzione',
            self::STATUS_REJECTED => 'Rifiutata',
            self::STATUS_REGISTERED => 'Congedo registrato',
            default => 'In attesa firma tutore',
        };
    }

    public static function statusBadge(string $statusCode): string
    {
        return match (self::normalizeStatus($statusCode)) {
            self::STATUS_AWAITING_GUARDIAN_SIGNATURE => 'bg-amber-100 text-amber-700',
            self::STATUS_SIGNED => 'bg-sky-100 text-sky-700',
            self::STATUS_PRE_APPROVED => 'bg-yellow-100 text-yellow-700',
            self::STATUS_APPROVED => 'bg-lime-100 text-lime-700',
            self::STATUS_DOCUMENTATION_REQUESTED => 'bg-fuchsia-100 text-fuchsia-700',
            self::STATUS_IN_REVIEW => 'bg-indigo-100 text-indigo-700',
            self::STATUS_FORWARDED_TO_MANAGEMENT => 'bg-slate-200 text-slate-700',
            self::STATUS_REJECTED => 'bg-rose-100 text-rose-700',
            self::STATUS_REGISTERED => 'bg-emerald-100 text-emerald-700',
            default => 'bg-amber-100 text-amber-700',
        };
    }

    /**
     * @return array<int,string>
     */
    public static function lessonSlots(): array
    {
        return self::LESSON_SLOTS;
    }

    /**
     * @return array<int,int>
     */
    public static function normalizeLessonPeriods(mixed $lessons): array
    {
        $source = $lessons;
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

        $availablePeriods = array_keys(self::LESSON_SLOTS);
        $normalized = [];
        foreach ($source as $period) {
            $periodNumber = (int) $period;
            if (! in_array($periodNumber, $availablePeriods, true)) {
                continue;
            }

            $normalized[$periodNumber] = $periodNumber;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @return array{start:array<int,int>,end:array<int,int>}
     */
    public static function normalizeRequestedLessonsPayload(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return ['start' => [], 'end' => []];
        }

        if (array_is_list($value)) {
            $normalized = self::normalizeLessonPeriods($value);

            return ['start' => $normalized, 'end' => $normalized];
        }

        $start = self::normalizeLessonPeriods(
            $value['start']
                ?? $value['from']
                ?? $value['lessons_start']
                ?? []
        );
        $end = self::normalizeLessonPeriods(
            $value['end']
                ?? $value['to']
                ?? $value['lessons_end']
                ?? []
        );

        if ($start === [] && $end !== []) {
            $start = $end;
        }
        if ($end === []) {
            $end = $start;
        }

        return ['start' => $start, 'end' => $end];
    }

    public static function requestNoticeWorkingHours(?AbsenceSetting $absenceSetting = null): int
    {
        $absenceSetting ??= AbsenceSetting::query()->first();

        return max(
            (int) ($absenceSetting?->leave_request_notice_working_hours ?? self::DEFAULT_REQUEST_NOTICE_WORKING_HOURS),
            0
        );
    }

    /**
     * @param  array<int,int>  $startLessons
     */
    public static function resolveRequestedStartDateTime(
        Carbon $startDate,
        array $startLessons = []
    ): Carbon {
        $resolvedDateTime = $startDate->copy()->startOfDay();
        $normalizedLessons = self::normalizeLessonPeriods($startLessons);
        if ($normalizedLessons === []) {
            return $resolvedDateTime;
        }

        $firstPeriod = $normalizedLessons[0];
        $timeRange = self::LESSON_SLOTS[$firstPeriod] ?? '';
        if (! preg_match('/^(?<hour>\d{2}):(?<minute>\d{2})/', $timeRange, $matches)) {
            return $resolvedDateTime;
        }

        return $resolvedDateTime->setTime(
            (int) ($matches['hour'] ?? 0),
            (int) ($matches['minute'] ?? 0)
        );
    }

    public static function workingMinutesBetween(Carbon $fromDateTime, Carbon $toDateTime): int
    {
        return WorkingCalendar::workingMinutesBetween($fromDateTime, $toDateTime);
    }

    /**
     * @param  array<int,int>  $lessons
     */
    public static function formatLessonPeriods(array $lessons): string
    {
        $normalizedLessons = self::normalizeLessonPeriods($lessons);
        if ($normalizedLessons === []) {
            return '-';
        }

        $ranges = [];
        $rangeStart = $normalizedLessons[0];
        $rangeEnd = $normalizedLessons[0];

        for ($index = 1; $index < count($normalizedLessons); $index++) {
            $period = $normalizedLessons[$index];
            if ($period === $rangeEnd + 1) {
                $rangeEnd = $period;

                continue;
            }

            $ranges[] = [$rangeStart, $rangeEnd];
            $rangeStart = $period;
            $rangeEnd = $period;
        }
        $ranges[] = [$rangeStart, $rangeEnd];

        return collect($ranges)
            ->map(function (array $range): string {
                [$start, $end] = $range;

                return $start === $end
                    ? 'P'.$start
                    : 'P'.$start.'-P'.$end;
            })
            ->implode(', ');
    }

    public static function formatRequestedLessonsLabel(
        mixed $value,
        ?string $startDate = null,
        ?string $endDate = null
    ): string {
        $payload = self::normalizeRequestedLessonsPayload($value);
        $startLessons = $payload['start'];
        $endLessons = $payload['end'];

        if ($startLessons === [] && $endLessons === []) {
            return '';
        }

        if ($startLessons === []) {
            $startLessons = $endLessons;
        }
        if ($endLessons === []) {
            $endLessons = $startLessons;
        }

        $isSingleDay = $startDate !== null && $endDate !== null
            ? Carbon::parse($startDate)->isSameDay(Carbon::parse($endDate))
            : true;

        if ($isSingleDay || $startLessons === $endLessons) {
            return 'Periodi: '.self::formatLessonPeriods($startLessons);
        }

        return 'Dal: '.self::formatLessonPeriods($startLessons)
            .' | Al: '.self::formatLessonPeriods($endLessons);
    }

    public function hasGuardianSignature(): bool
    {
        if ($this->relationLoaded('guardianConfirmations')) {
            return $this->guardianConfirmations->contains(function (GuardianLeaveConfirmation $confirmation) {
                $status = strtolower(trim((string) ($confirmation->status ?? '')));

                return in_array($status, ['confirmed', 'approved', 'signed'], true)
                    || ! empty($confirmation->confirmed_at)
                    || ! empty($confirmation->signed_at);
            });
        }

        return $this->guardianConfirmations()
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    public function getLeave(?User $user = null)
    {
        $requiredNoticeWorkingHours = self::requestNoticeWorkingHours();
        $query = Leave::query()->with([
            'student',
            'guardianConfirmations.guardian',
        ]);

        if ($user) {
            if ($user->hasRole('student')) {
                $query->where('student_id', $user->id);
            } elseif ($user->hasRole('teacher')) {
                $query->whereIn('student_id', function ($subQuery) use ($user) {
                    $subQuery
                        ->select('class_user.user_id')
                        ->from('class_user')
                        ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                        ->where('class_teacher.teacher_id', $user->id);
                });
            }
        }

        return $query
            ->get()
            ->map(function (Leave $congedo) use ($user, $requiredNoticeWorkingHours) {
                $statusCode = self::normalizeStatus($congedo->status);
                $requestedLessons = self::normalizeRequestedLessonsPayload($congedo->requested_lessons);
                $requestedLessonsLabel = self::formatRequestedLessonsLabel(
                    $requestedLessons,
                    $congedo->start_date?->toDateString(),
                    $congedo->end_date?->toDateString()
                );
                $durationLabel = ((int) $congedo->requested_hours).' ore';
                $guardianConfirmation = $congedo->guardianConfirmations
                    ->filter(function (GuardianLeaveConfirmation $confirmation) {
                        $status = strtolower(trim((string) ($confirmation->status ?? '')));

                        return in_array($status, ['confirmed', 'approved', 'signed'], true)
                            || ! empty($confirmation->confirmed_at)
                            || ! empty($confirmation->signed_at);
                    })
                    ->sortBy(function (GuardianLeaveConfirmation $confirmation) {
                        $signedAt = $confirmation->confirmed_at ?? $confirmation->signed_at;

                        return $signedAt?->timestamp ?? PHP_INT_MAX;
                    })
                    ->first();
                $guardianSigned = $guardianConfirmation !== null;
                if ($statusCode === self::STATUS_AWAITING_GUARDIAN_SIGNATURE && $guardianSigned) {
                    $statusCode = self::STATUS_SIGNED;
                }

                $startDate = Carbon::parse($congedo->start_date);
                $endDate = Carbon::parse($congedo->end_date);
                $submittedAt = $congedo->created_at_custom ?? $congedo->created_at;
                $requestedStartDateTime = self::resolveRequestedStartDateTime($startDate, $requestedLessons['start']);
                $workingMinutesBeforeStart = $submittedAt
                    ? self::workingMinutesBetween($submittedAt, $requestedStartDateTime)
                    : 0;
                $lateSubmission = $submittedAt
                    && $requiredNoticeWorkingHours > 0
                    && $workingMinutesBeforeStart < ($requiredNoticeWorkingHours * 60);
                $periodo = $startDate->isSameDay($endDate)
                    ? $startDate->format('d M')
                    : $startDate->format('d M').'-'.$endDate->format('d M');
                $studentName = $congedo->student
                    ? trim($congedo->student->name.' '.$congedo->student->surname)
                    : '-';
                $guardianSignedAt = $guardianConfirmation?->confirmed_at ?? $guardianConfirmation?->signed_at;
                $guardianSignerName = trim((string) ($guardianConfirmation?->guardian?->name ?? ''));
                $guardianSignatureLabel = $guardianSigned
                    ? ($guardianSignerName !== ''
                        ? 'Firmato da '.$guardianSignerName
                        : 'Firma presente')
                    : 'Assente';
                if ($guardianSignedAt) {
                    $guardianSignatureLabel .= ' ('.$guardianSignedAt->format('d M Y H:i').')';
                }

                $canManage = in_array($statusCode, self::openStatuses(), true);
                $canEditWorkflow = $user ? $user->hasRole('laboratory_manager') : true;
                $hasDocumentation = ! empty($congedo->documentation_path);
                $canPreApprove = $canEditWorkflow
                    && ! $guardianSigned
                    && ! (bool) $congedo->approved_without_guardian
                    && in_array(
                        $statusCode,
                        [
                            self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
                            self::STATUS_DOCUMENTATION_REQUESTED,
                            self::STATUS_IN_REVIEW,
                        ],
                        true
                    );
                $canApprove = $canEditWorkflow
                    && (
                        $guardianSigned
                        || (bool) $congedo->approved_without_guardian
                    )
                    && in_array(
                        $statusCode,
                        [
                            self::STATUS_SIGNED,
                            self::STATUS_PRE_APPROVED,
                            self::STATUS_APPROVED,
                            self::STATUS_IN_REVIEW,
                            self::STATUS_DOCUMENTATION_REQUESTED,
                        ],
                        true
                    );
                $canReject = $canEditWorkflow && in_array($statusCode, self::openStatuses(), true);
                $canRequestDocumentation = $canEditWorkflow
                    && ! $hasDocumentation
                    && in_array(
                        $statusCode,
                        [
                            self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
                            self::STATUS_SIGNED,
                            self::STATUS_PRE_APPROVED,
                            self::STATUS_APPROVED,
                            self::STATUS_IN_REVIEW,
                            self::STATUS_DOCUMENTATION_REQUESTED,
                        ],
                        true
                    );
                $canRejectDocumentation = $canEditWorkflow
                    && $hasDocumentation
                    && in_array($statusCode, self::openStatuses(), true);
                $canResendGuardianEmail = $canEditWorkflow
                    && ! $guardianSigned && in_array(
                        $statusCode,
                        [
                            self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
                            self::STATUS_PRE_APPROVED,
                            self::STATUS_DOCUMENTATION_REQUESTED,
                            self::STATUS_IN_REVIEW,
                        ],
                        true
                    );
                $canToggle40Hours = $canEditWorkflow && in_array(
                    $statusCode,
                    [
                        self::STATUS_AWAITING_GUARDIAN_SIGNATURE,
                        self::STATUS_SIGNED,
                        self::STATUS_PRE_APPROVED,
                        self::STATUS_APPROVED,
                        self::STATUS_IN_REVIEW,
                        self::STATUS_DOCUMENTATION_REQUESTED,
                        self::STATUS_REGISTERED,
                    ],
                    true
                );
                $registeredAbsenceId = (int) ($congedo->registered_absence_id ?? 0);
                $canEdit = $canEditWorkflow;
                $canForwardToManagement = $canEditWorkflow
                    && in_array($statusCode, self::openStatuses(), true);
                $canDelete = $user
                    ? $user->hasRole('laboratory_manager')
                    : false;
                $canDownloadForwardingPdf = $statusCode === self::STATUS_FORWARDED_TO_MANAGEMENT;
                $forwardingPdfUrl = $canDownloadForwardingPdf
                    ? route('leaves.forwarding-pdf.download', ['leave' => $congedo->id])
                    : null;
                $countHoursLabel = $statusCode === self::STATUS_REJECTED
                    ? 'Non conteggiato (congedo rifiutato)'
                    : 'Da calcolare a pratica conclusa';

                return [
                    'id' => 'C-'.str_pad((string) $congedo->id, 4, '0', STR_PAD_LEFT),
                    'leave_id' => $congedo->id,
                    'student_id' => $congedo->student_id,
                    'studente' => $studentName,
                    'date' => Carbon::parse($congedo->start_date)->toDateString(),
                    'durata' => $durationLabel,
                    'ore' => (int) $congedo->requested_hours,
                    'requested_hours' => (int) $congedo->requested_hours,
                    'requested_lessons' => $requestedLessons,
                    'requested_lessons_label' => $requestedLessonsLabel !== ''
                        ? $requestedLessonsLabel
                        : null,
                    'motivo' => $congedo->reason ?? '-',
                    'destinazione' => (string) ($congedo->destination ?? ''),
                    'destination' => (string) ($congedo->destination ?? ''),
                    'data' => Carbon::parse($congedo->start_date)->format('d M Y'),
                    'periodo' => $periodo,
                    'tipo' => 'Congedo',
                    'stato_code' => $statusCode,
                    'stato' => self::statusLabel($statusCode),
                    'badge' => self::statusBadge($statusCode),
                    'firma_tutore_presente' => $guardianSigned,
                    'firma_tutore_data' => $guardianSignedAt?->format('d M Y H:i'),
                    'firma_tutore_nome' => $guardianSignerName !== '' ? $guardianSignerName : null,
                    'firma_tutore_label' => $guardianSignatureLabel,
                    'conteggio_40_ore' => (bool) $congedo->count_hours,
                    'conteggio_40_ore_label' => $countHoursLabel,
                    'conteggio_40_ore_commento' => (string) ($congedo->count_hours_comment ?? ''),
                    'commento_workflow' => (string) ($congedo->workflow_comment ?? ''),
                    'commento_documentazione' => (string) ($congedo->documentation_request_comment ?? ''),
                    'documentazione_presente' => ! empty($congedo->documentation_path),
                    'documentazione_data' => $congedo->documentation_uploaded_at?->format('d M Y H:i'),
                    'hours_limit_exceeded_at_request' => (bool) ($congedo->hours_limit_exceeded_at_request ?? false),
                    'hours_limit_value_at_request' => (int) ($congedo->hours_limit_value_at_request ?? 0),
                    'hours_limit_max_at_request' => (int) ($congedo->hours_limit_max_at_request ?? 0),
                    'richiesta_inviata_il' => $submittedAt?->format('d M Y H:i'),
                    'richiesta_tardiva' => (bool) $lateSubmission,
                    'richiesta_tardiva_label' => $lateSubmission
                        ? 'Inviata oltre il termine minimo di '.$requiredNoticeWorkingHours.' ore lavorative'
                        : null,
                    'notice_working_hours_required' => $requiredNoticeWorkingHours,
                    'notice_working_minutes_available' => $workingMinutesBeforeStart,
                    'registered_absence_id' => $registeredAbsenceId > 0 ? $registeredAbsenceId : null,
                    'can_manage' => $canManage,
                    'can_pre_approve' => $canPreApprove,
                    'can_approve' => $canApprove,
                    'can_reject' => $canReject,
                    'can_request_documentation' => $canRequestDocumentation,
                    'can_reject_documentation' => $canRejectDocumentation,
                    'can_resend_guardian_email' => $canResendGuardianEmail,
                    'can_toggle_40_hours' => $canToggle40Hours,
                    'can_edit' => $canEdit,
                    'can_forward_to_management' => $canForwardToManagement,
                    'can_delete' => $canDelete,
                    'can_download_forwarding_pdf' => $canDownloadForwardingPdf,
                    'forwarding_pdf_url' => $forwardingPdfUrl,
                ];
            });
    }

    public function getLeaveDocuments(?User $user = null)
    {
        $query = Leave::query();

        if ($user) {
            $query->where('student_id', $user->id);
        }

        return $query
            ->orderByDesc('created_at_custom')
            ->get()
            ->map(function (Leave $leave) {
                $statusCode = self::normalizeStatus($leave->status);
                $timestamp = Carbon::parse($leave->created_at_custom ?? $leave->created_at);

                return [
                    'id' => 'LC-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
                    'nome' => $leave->reason ?? 'Richiesta congedo',
                    'tipo' => 'Richiesta congedo',
                    'origine' => 'Congedo',
                    'stato' => self::statusLabel($statusCode),
                    'badge' => self::statusBadge($statusCode),
                    'data' => $timestamp->format('d M Y'),
                    'sort_date' => $timestamp->toDateTimeString(),
                    'congedo_id' => $leave->id,
                    'needs_documentation_upload' => $statusCode === self::STATUS_DOCUMENTATION_REQUESTED
                        && empty($leave->documentation_path),
                ];
            });
    }
}
