<?php

namespace App\Models;

use App\Services\StudentDeadlineReminderService;
use App\Support\DeadlineWarningProgress;
use App\Support\DelaySemester;
use App\Support\SystemSettingsResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Delay extends Model
{
    use HasFactory;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_JUSTIFIED = 'justified';

    public const STATUS_REGISTERED = 'registered';

    protected $fillable = [
        'student_id',
        'recorded_by',
        'delay_datetime',
        'minutes',
        'justification_deadline',
        'notes',
        'teacher_comment',
        'status',
        'count_in_semester',
        'exclusion_comment',
        'global',
        'validated_at',
        'validated_by',
        'auto_arbitrary_at',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function emailNotifications()
    {
        return $this->hasMany(DelayEmailNotification::class);
    }

    public function confirmationTokens()
    {
        return $this->hasMany(DelayConfirmationToken::class);
    }

    public function guardianConfirmations()
    {
        return $this->hasMany(GuardianDelayConfirmation::class);
    }

    protected function casts(): array
    {
        return [
            'delay_datetime' => 'datetime',
            'justification_deadline' => 'date',
            'count_in_semester' => 'boolean',
            'global' => 'boolean',
            'validated_at' => 'datetime',
            'auto_arbitrary_at' => 'datetime',
        ];
    }

    public static function normalizeStatus(?string $status): string
    {
        return match ($status) {
            'pending', self::STATUS_REPORTED => self::STATUS_REPORTED,
            'approved', 'active', self::STATUS_JUSTIFIED => self::STATUS_JUSTIFIED,
            'rejected', 'excluded', 'arbitrary', self::STATUS_REGISTERED => self::STATUS_REGISTERED,
            default => self::STATUS_REPORTED,
        };
    }

    public static function openStatuses(): array
    {
        return [self::STATUS_REPORTED];
    }

    public static function resolveSemester(?Carbon $referenceDate = null): DelaySemester
    {
        $setting = static::resolveDelaySetting();

        return DelaySemester::fromDate(
            ($referenceDate ?? now())->copy()->startOfDay(),
            $setting->resolvedFirstSemesterEndMonth(),
            $setting->resolvedFirstSemesterEndDay()
        );
    }

    public static function countRegisteredInSemester(int $studentId, ?Carbon $referenceDate = null): int
    {
        $semester = self::resolveSemester($referenceDate);

        return (int) self::query()
            ->where('student_id', $studentId)
            ->where('count_in_semester', true)
            ->where('status', self::STATUS_REGISTERED)
            ->whereBetween('delay_datetime', [$semester->start, $semester->end])
            ->count();
    }

    public static function shouldCountInSemester(Delay $delay, ?DelaySemester $semester = null): bool
    {
        $statusCode = self::normalizeStatus($delay->status);

        if ($statusCode !== self::STATUS_REGISTERED || ! $delay->count_in_semester) {
            return false;
        }

        return self::occursInSemester($delay, $semester);
    }

    public static function occursInSemester(Delay $delay, ?DelaySemester $semester = null): bool
    {
        $resolvedSemester = $semester ?? self::resolveSemester();
        $delayDate = $delay->delay_datetime
            ? Carbon::parse($delay->delay_datetime)
            : null;

        if (! $delayDate) {
            return false;
        }

        return $delayDate->betweenIncluded($resolvedSemester->start, $resolvedSemester->end);
    }

    public static function addBusinessDays(Carbon $startDate, int $days): Carbon
    {
        if ($days <= 0) {
            return $startDate->copy();
        }

        $date = $startDate->copy();
        $added = 0;

        while ($added < $days) {
            $date->addDay();

            if ($date->isWeekend()) {
                continue;
            }

            $added++;
        }

        return $date;
    }

    public static function businessDaysUntil(Carbon $fromDate, Carbon $toDate): int
    {
        $from = $fromDate->copy()->startOfDay();
        $to = $toDate->copy()->startOfDay();

        if ($to->lt($from)) {
            return -static::businessDaysUntil($to, $from);
        }

        $days = 0;
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $cursor->addDay();

            if ($cursor->isWeekend()) {
                continue;
            }

            $days++;
        }

        return $days;
    }

    public static function justificationCountdownBusinessDays(?DelaySetting $setting = null): int
    {
        $setting ??= static::resolveDelaySetting();

        return max((int) $setting->justification_business_days, 0);
    }

    public static function preExpiryWarningBusinessDays(?DelaySetting $setting = null): int
    {
        $setting ??= static::resolveDelaySetting();

        return max((int) $setting->pre_expiry_warning_business_days, 0);
    }

    public static function preExpiryWarningPercent(?DelaySetting $setting = null): int
    {
        $setting ??= static::resolveDelaySetting();

        return DeadlineWarningProgress::normalizePercent(
            (int) ($setting->pre_expiry_warning_percent
                ?? DeadlineWarningProgress::DEFAULT_WARNING_PERCENT)
        );
    }

    public static function deadlineModeActive(?DelaySetting $setting = null): bool
    {
        $setting ??= static::resolveDelaySetting();

        return (bool) ($setting->deadline_active ?? false);
    }

    public static function deadlineBusinessDays(?DelaySetting $setting = null): int
    {
        $setting ??= static::resolveDelaySetting();

        return max((int) ($setting->deadline_business_days ?? 0), 0);
    }

    public static function calculateRegisteredDeadline(
        Carbon $referenceDate,
        ?DelaySetting $setting = null
    ): Carbon {
        $baseDate = $referenceDate->copy()->startOfDay();
        $countdownDays = static::deadlineBusinessDays($setting);

        return static::addBusinessDays($baseDate, $countdownDays);
    }

    public static function calculateJustificationDeadline(
        Carbon $referenceDate,
        ?DelaySetting $setting = null
    ): Carbon {
        $baseDate = $referenceDate->copy()->startOfDay();
        $countdownDays = static::justificationCountdownBusinessDays($setting);

        return static::addBusinessDays($baseDate, $countdownDays);
    }

    public function resolveJustificationDeadline(?DelaySetting $setting = null): Carbon
    {
        $setting ??= static::resolveDelaySetting();
        $statusCode = static::normalizeStatus((string) $this->status);
        $storedDeadline = $this->justification_deadline
            ? Carbon::parse($this->justification_deadline)->startOfDay()
            : null;

        if (static::deadlineModeActive($setting) && $statusCode === static::STATUS_REGISTERED) {
            if ($storedDeadline) {
                return $storedDeadline;
            }

            $baseDate = $this->validated_at
                ? Carbon::parse($this->validated_at)->startOfDay()
                : Carbon::today();
            $configuredDeadline = static::calculateRegisteredDeadline($baseDate, $setting);
        } else {
            $baseDate = $this->delay_datetime
                ? Carbon::parse($this->delay_datetime)->startOfDay()
                : Carbon::today();
            $configuredDeadline = static::calculateJustificationDeadline($baseDate, $setting);
        }

        if ($storedDeadline && $storedDeadline->gt($configuredDeadline)) {
            return $storedDeadline;
        }

        return $configuredDeadline;
    }

    public function syncJustificationDeadline(?DelaySetting $setting = null): Carbon
    {
        $effectiveDeadline = $this->resolveJustificationDeadline($setting);
        $storedDeadline = $this->justification_deadline
            ? Carbon::parse($this->justification_deadline)->startOfDay()
            : null;

        if (! $storedDeadline || $storedDeadline->ne($effectiveDeadline)) {
            $this->justification_deadline = $effectiveDeadline->toDateString();
            $this->save();
        }

        return $effectiveDeadline;
    }

    public function resolveWarningReferenceDate(?DelaySetting $setting = null): Carbon
    {
        $setting ??= static::resolveDelaySetting();
        $statusCode = static::normalizeStatus((string) $this->status);

        if (static::deadlineModeActive($setting) && $statusCode === static::STATUS_REGISTERED) {
            if ($this->validated_at) {
                return Carbon::parse($this->validated_at)->startOfDay();
            }

            return Carbon::parse($this->created_at ?? now())->startOfDay();
        }

        return $this->delay_datetime
            ? Carbon::parse($this->delay_datetime)->startOfDay()
            : Carbon::today()->startOfDay();
    }

    public function totalDeadlineBusinessDays(?DelaySetting $setting = null): int
    {
        $referenceDate = $this->resolveWarningReferenceDate($setting);
        $deadline = $this->resolveJustificationDeadline($setting);

        return max(static::businessDaysUntil($referenceDate, $deadline), 0);
    }

    public function shouldSendPreExpiryWarning(
        ?DelaySetting $setting = null,
        ?Carbon $today = null
    ): bool {
        $today ??= Carbon::today();
        $deadline = $this->resolveJustificationDeadline($setting);
        $remainingBusinessDays = static::businessDaysUntil($today, $deadline);
        $totalBusinessDays = $this->totalDeadlineBusinessDays($setting);

        return DeadlineWarningProgress::shouldNotify(
            $totalBusinessDays,
            $remainingBusinessDays,
            static::preExpiryWarningPercent($setting)
        );
    }

    public static function applyAutomaticArbitrary(): int
    {
        $today = Carbon::today();
        $setting = static::resolveDelaySetting();
        $warningPercent = static::preExpiryWarningPercent($setting);

        if (static::deadlineModeActive($setting)) {
            static::query()
                ->with('student.guardians')
                ->where('status', static::STATUS_REGISTERED)
                ->get()
                ->each(function (self $delay) use ($setting, $today, $warningPercent): void {
                    $effectiveDeadline = $delay->syncJustificationDeadline($setting);

                    if (! $delay->shouldSendPreExpiryWarning($setting, $today)) {
                        return;
                    }

                    app(StudentDeadlineReminderService::class)->sendDelayReminder(
                        $delay,
                        $effectiveDeadline,
                        $warningPercent
                    );
                });

            return 0;
        }

        if ((bool) $setting->guardian_signature_required) {
            return 0;
        }

        $openDelays = static::query()
            ->with('student.guardians')
            ->where('status', static::STATUS_REPORTED)
            ->get();

        $updated = 0;

        foreach ($openDelays as $delay) {
            $effectiveDeadline = $delay->syncJustificationDeadline($setting);
            if ($delay->shouldSendPreExpiryWarning($setting, $today)) {
                app(StudentDeadlineReminderService::class)->sendDelayReminder(
                    $delay,
                    $effectiveDeadline,
                    $warningPercent
                );
            }

            if (! $effectiveDeadline->lt($today)) {
                continue;
            }

            $delay->status = static::STATUS_REGISTERED;
            $delay->count_in_semester = true;
            $delay->auto_arbitrary_at = now();
            $delay->save();

            static::queueAutomaticRegistrationNotifications($delay);
            $updated++;
        }

        return $updated;
    }

    private static function queueAutomaticRegistrationNotifications(self $delay): void
    {
        $studentEmail = $delay->student?->email;
        if ($studentEmail) {
            static::queueEmailNotification(
                $delay,
                'auto_registered_student',
                $studentEmail,
                'Ritardo registrato automaticamente',
                'Il tuo ritardo '.$delay->id.' e stato registrato automaticamente per scadenza del termine.'
            );
        }

        $teacherEmails = User::query()
            ->select('users.email')
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->join('class_user', 'class_user.class_id', '=', 'class_teacher.class_id')
            ->where('class_user.user_id', $delay->student_id)
            ->whereNotNull('users.email')
            ->distinct()
            ->pluck('users.email');

        foreach ($teacherEmails as $teacherEmail) {
            static::queueEmailNotification(
                $delay,
                'auto_registered_teacher',
                $teacherEmail,
                'Ritardo studente registrato automaticamente',
                'Il ritardo '.$delay->id.' e stato registrato automaticamente per scadenza del termine.'
            );
        }
    }

    private static function queueEmailNotification(
        self $delay,
        string $type,
        string $recipientEmail,
        string $subject,
        string $body
    ): void {
        $alreadyQueued = DelayEmailNotification::query()
            ->where('delay_id', $delay->id)
            ->where('type', $type)
            ->where('recipient_email', $recipientEmail)
            ->exists();

        if ($alreadyQueued) {
            return;
        }

        DelayEmailNotification::create([
            'type' => $type,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'delay_id' => $delay->id,
            'status' => 'pending',
        ]);
    }

    public function getDelay(?User $user = null)
    {
        $query = Delay::query()->with([
            'student',
            'student.guardians',
            'guardianConfirmations.guardian',
        ]);
        $delaySetting = static::resolveDelaySetting();
        $guardianSignatureRequired = (bool) $delaySetting->guardian_signature_required;
        $deadlineModeActive = static::deadlineModeActive($delaySetting);

        if ($user) {
            if ($user->hasRole('student')) {
                $query->where('student_id', $user->id);
            } elseif ($user->hasRole('teacher')) {
                $query->whereIn('student_id', function ($subQuery) use ($user) {
                    $subQuery
                        ->select('class_user.user_id')
                        ->from('class_user')
                        ->join(
                            'class_teacher',
                            'class_teacher.class_id',
                            '=',
                            'class_user.class_id'
                        )
                        ->where('class_teacher.teacher_id', $user->id);
                });
            }
        }

        return $query
            ->get()
            ->map(function (Delay $ritardo) use (
                $delaySetting,
                $guardianSignatureRequired,
                $deadlineModeActive,
                $user
            ) {
                $statusCode = self::normalizeStatus($ritardo->status);
                $deadline = null;
                $daysToDeadline = null;
                $countdown = '-';
                $isReported = $statusCode === self::STATUS_REPORTED;
                $isRegistered = $statusCode === self::STATUS_REGISTERED;
                $showRegisteredDeadline = $deadlineModeActive && $isRegistered;
                $showReportedDeadline = ! $deadlineModeActive
                    && ! $guardianSignatureRequired
                    && $isReported;
                $showDeadline = $showRegisteredDeadline || $showReportedDeadline;

                if ($showDeadline) {
                    $deadline = $ritardo->resolveJustificationDeadline($delaySetting);
                    $daysToDeadline = self::businessDaysUntil(Carbon::today(), $deadline);
                    $countdown = match (true) {
                        $daysToDeadline > 1 => $daysToDeadline.' giorni lavorativi',
                        $daysToDeadline === 1 => '1 giorno lavorativo',
                        $daysToDeadline === 0 => 'Scade oggi',
                        default => 'Scaduta da '.abs($daysToDeadline).' giorni lavorativi',
                    };
                }

                $durationLabel = self::formatDelayDurationLabel((int) $ritardo->minutes);
                $guardianConfirmation = self::resolveFirstSignedGuardianConfirmation(
                    $ritardo->guardianConfirmations
                );
                $guardianSigned = $guardianConfirmation !== null;
                $guardianSignedAt = $guardianConfirmation?->confirmed_at ?? $guardianConfirmation?->signed_at;
                $guardianSignerName = null;

                if ($guardianConfirmation) {
                    $notes = json_decode((string) ($guardianConfirmation->notes ?? ''), true);
                    $signerNameFromNotes = is_array($notes)
                        ? trim((string) ($notes['signer_name'] ?? ''))
                        : '';
                    $guardianName = trim((string) ($guardianConfirmation->guardian?->name ?? ''));
                    $guardianSignerName = $signerNameFromNotes !== '' ? $signerNameFromNotes : $guardianName;
                    $guardianSignerName = $guardianSignerName !== '' ? $guardianSignerName : null;
                }

                $guardianSignatureLabel = 'Assente';
                if ($guardianSigned) {
                    $guardianSignatureLabel = $guardianSignerName
                        ? 'Firmato da '.$guardianSignerName
                        : 'Firma presente';

                    if ($guardianSignedAt) {
                        $guardianSignatureLabel .= ' ('.$guardianSignedAt->format('d M Y H:i').')';
                    }
                }
                $deadlineModeExpired = $isRegistered
                    && $deadlineModeActive
                    && $deadline !== null
                    && $deadline->lt(Carbon::today());
                $managedRegisteredDelay = $isRegistered
                    && $deadlineModeActive
                    && ! $deadlineModeExpired;

                if ($statusCode === self::STATUS_JUSTIFIED) {
                    $label = 'Giustificato';
                    $badge = 'bg-emerald-100 text-emerald-700';
                } elseif ($deadlineModeExpired) {
                    $label = 'Arbitrario';
                    $badge = 'bg-rose-100 text-rose-700';
                } elseif ($isReported) {
                    $label = 'Attesa docente';
                    $badge = 'bg-amber-100 text-amber-700';
                } elseif ($isRegistered && ! $deadlineModeActive) {
                    $label = 'Registrato';
                    $badge = 'bg-sky-100 text-sky-700';
                } elseif ($guardianSignatureRequired && $guardianSigned) {
                    $label = 'Firmato (attesa docente)';
                    $badge = 'bg-sky-100 text-sky-700';
                } elseif ($guardianSignatureRequired) {
                    $label = 'Attesa firma tutore';
                    $badge = 'bg-amber-100 text-amber-700';
                } else {
                    $label = 'Attesa docente';
                    $badge = 'bg-amber-100 text-amber-700';
                }

                $canApprove = $isReported || ($managedRegisteredDelay && $guardianSigned);
                $canApproveWithoutGuardian = $managedRegisteredDelay && ! $guardianSigned;
                $canReject = $isReported || $managedRegisteredDelay;
                $canExtendDeadline = $deadlineModeExpired;
                $canEditDelay = in_array(
                    $statusCode,
                    [self::STATUS_REPORTED, self::STATUS_JUSTIFIED, self::STATUS_REGISTERED],
                    true
                );
                $canDeleteDelay = $user?->hasRole('teacher') ?? false;
                $canResendGuardianEmail = $guardianSignatureRequired
                    && $managedRegisteredDelay
                    && ! $guardianSigned;

                $studentName = $ritardo->student
                    ? trim($ritardo->student->name.' '.$ritardo->student->surname)
                    : '-';

                return [
                    'id' => 'R-'.str_pad((string) $ritardo->id, 4, '0', STR_PAD_LEFT),
                    'delay_id' => $ritardo->id,
                    'student_id' => $ritardo->student_id,
                    'studente' => $studentName,
                    'stato_code' => $statusCode,
                    'date' => Carbon::parse($ritardo->delay_datetime)->toDateString(),
                    'minutes' => (int) $ritardo->minutes,
                    'durata' => $durationLabel,
                    'motivo' => $ritardo->notes ?? '-',
                    'data' => Carbon::parse($ritardo->delay_datetime)->format('d M Y'),
                    'scadenza' => $deadline?->format('d M Y') ?? '-',
                    'countdown' => $countdown,
                    'giorni_alla_scadenza' => $daysToDeadline,
                    'tipo' => 'Ritardo',
                    'stato' => $label,
                    'badge' => $badge,
                    'firma_tutore_richiesta' => $guardianSignatureRequired,
                    'firma_tutore_presente' => $guardianSigned,
                    'firma_tutore_data' => $guardianSignedAt?->format('d M Y H:i'),
                    'firma_tutore_nome' => $guardianSignerName,
                    'firma_tutore_label' => $guardianSignatureLabel,
                    'can_approve' => $canApprove,
                    'can_approve_without_guardian' => $canApproveWithoutGuardian,
                    'can_reject' => $canReject,
                    'can_extend_deadline' => $canExtendDeadline,
                    'can_edit_delay' => $canEditDelay,
                    'can_delete_delay' => $canDeleteDelay,
                    'can_resend_guardian_email' => $canResendGuardianEmail,
                    'commento_docente' => (string) $ritardo->teacher_comment,
                ];
            });
    }

    private static function formatDelayDurationLabel(int $minutes): string
    {
        $safeMinutes = max($minutes, 0);

        return $safeMinutes === 1
            ? '1 min'
            : $safeMinutes.' min';
    }

    private static function resolveDelaySetting(): DelaySetting
    {
        return SystemSettingsResolver::delaySetting();
    }

    private static function resolveFirstSignedGuardianConfirmation($confirmations): ?GuardianDelayConfirmation
    {
        return collect($confirmations)
            ->filter(fn (GuardianDelayConfirmation $confirmation) => self::isGuardianConfirmationSigned($confirmation))
            ->sortBy(function (GuardianDelayConfirmation $confirmation) {
                $signedAt = $confirmation->confirmed_at ?? $confirmation->signed_at;

                return $signedAt?->timestamp ?? PHP_INT_MAX;
            })
            ->first();
    }

    private static function isGuardianConfirmationSigned(GuardianDelayConfirmation $confirmation): bool
    {
        $status = strtolower(trim((string) $confirmation->status));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }
}
