<?php

namespace App\Models;

use App\Services\StudentDeadlineReminderService;
use App\Support\AnnualHoursLimitLabels;
use App\Support\DeadlineWarningProgress;
use App\Support\WorkingCalendar;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Absence extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_JUSTIFIED = 'justified';

    public const STATUS_ARBITRARY = 'arbitrary';

    protected $fillable = [
        'student_id',
        'derived_from_leave_id',
        'start_date',
        'end_date',
        'reason',
        'status',
        'assigned_hours',
        'counts_40_hours',
        'counts_40_hours_comment',
        'medical_certificate_deadline',
        'medical_certificate_required',
        'approved_without_guardian',
        'teacher_comment',
        'certificate_rejection_comment',
        'deadline_extension_comment',
        'deadline_extended_at',
        'deadline_extended_by',
        'auto_arbitrary_at',
        'hours_decided_at',
        'hours_decided_by',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function derivedFromLeave()
    {
        return $this->belongsTo(Leave::class, 'derived_from_leave_id');
    }

    public function hoursDecider()
    {
        return $this->belongsTo(User::class, 'hours_decided_by');
    }

    public function deadlineExtender()
    {
        return $this->belongsTo(User::class, 'deadline_extended_by');
    }

    public function medicalCertificates()
    {
        return $this->hasMany(MedicalCertificate::class);
    }

    public function confirmationTokens()
    {
        return $this->hasMany(AbsenceConfirmationToken::class);
    }

    public function guardianConfirmations()
    {
        return $this->hasMany(GuardianAbsenceConfirmation::class);
    }

    public function emailNotifications()
    {
        return $this->hasMany(AbsenceEmailNotification::class);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'counts_40_hours' => 'boolean',
            'medical_certificate_deadline' => 'date',
            'medical_certificate_required' => 'boolean',
            'approved_without_guardian' => 'boolean',
            'deadline_extended_at' => 'datetime',
            'auto_arbitrary_at' => 'datetime',
            'hours_decided_at' => 'datetime',
        ];
    }

    public static function normalizeStatus(?string $status): string
    {
        return match ($status) {
            'draft', 'draft_from_leave', self::STATUS_DRAFT => self::STATUS_DRAFT,
            'pending', self::STATUS_REPORTED => self::STATUS_REPORTED,
            'approved', self::STATUS_JUSTIFIED => self::STATUS_JUSTIFIED,
            'rejected', self::STATUS_ARBITRARY => self::STATUS_ARBITRARY,
            default => self::STATUS_REPORTED,
        };
    }

    public static function openStatuses(): array
    {
        return [self::STATUS_REPORTED, 'pending'];
    }

    public static function addBusinessDays(Carbon $startDate, int $days): Carbon
    {
        return WorkingCalendar::addBusinessDays($startDate, $days);
    }

    public static function businessDaysUntil(Carbon $fromDate, Carbon $toDate): int
    {
        return WorkingCalendar::businessDaysUntil($fromDate, $toDate);
    }

    public static function certificateCountdownDays(?AbsenceSetting $absenceSetting = null): int
    {
        $absenceSetting ??= AbsenceSetting::query()->firstOrFail();

        return max((int) $absenceSetting->absence_countdown_days, 0);
    }

    public static function preExpiryWarningPercent(?AbsenceSetting $absenceSetting = null): int
    {
        $absenceSetting ??= AbsenceSetting::query()->firstOrFail();

        return DeadlineWarningProgress::normalizePercent(
            (int) ($absenceSetting->pre_expiry_warning_percent
                ?? DeadlineWarningProgress::DEFAULT_WARNING_PERCENT)
        );
    }

    public static function calculateMedicalCertificateDeadline(
        Carbon $referenceDate,
        ?AbsenceSetting $absenceSetting = null
    ): Carbon {
        $baseDate = $referenceDate->copy()->startOfDay();
        $countdownDays = static::certificateCountdownDays($absenceSetting);

        return static::addBusinessDays($baseDate, $countdownDays);
    }

    public function resolveMedicalCertificateDeadline(?AbsenceSetting $absenceSetting = null): Carbon
    {
        $baseDate = $this->end_date
            ? Carbon::parse($this->end_date)->startOfDay()
            : Carbon::parse($this->start_date)->startOfDay();

        $configuredDeadline = static::calculateMedicalCertificateDeadline(
            $baseDate,
            $absenceSetting
        );
        $storedDeadline = $this->medical_certificate_deadline
            ? Carbon::parse($this->medical_certificate_deadline)->startOfDay()
            : null;

        if (
            $storedDeadline
            && $this->hasManualDeadlineExtension()
            && $storedDeadline->gt($configuredDeadline)
        ) {
            return $storedDeadline;
        }

        return $configuredDeadline;
    }

    public function hasManualDeadlineExtension(): bool
    {
        return ! is_null($this->deadline_extended_at);
    }

    public function syncMedicalCertificateDeadline(?AbsenceSetting $absenceSetting = null): Carbon
    {
        $effectiveDeadline = $this->resolveMedicalCertificateDeadline($absenceSetting);
        $storedDeadline = $this->medical_certificate_deadline
            ? Carbon::parse($this->medical_certificate_deadline)->startOfDay()
            : null;

        if (! $storedDeadline || $storedDeadline->ne($effectiveDeadline)) {
            $this->medical_certificate_deadline = $effectiveDeadline->toDateString();
            $this->save();
        }

        return $effectiveDeadline;
    }

    public function totalDeadlineBusinessDays(?AbsenceSetting $absenceSetting = null): int
    {
        $baseDate = $this->end_date
            ? Carbon::parse($this->end_date)->startOfDay()
            : Carbon::parse($this->start_date)->startOfDay();
        $deadline = $this->resolveMedicalCertificateDeadline($absenceSetting);

        return max(self::businessDaysUntil($baseDate, $deadline), 0);
    }

    public function shouldSendPreExpiryWarning(
        ?AbsenceSetting $absenceSetting = null,
        ?Carbon $today = null
    ): bool {
        $today ??= Carbon::today();
        $deadline = $this->resolveMedicalCertificateDeadline($absenceSetting);
        $remainingBusinessDays = self::businessDaysUntil($today, $deadline);
        $totalBusinessDays = $this->totalDeadlineBusinessDays($absenceSetting);

        return DeadlineWarningProgress::shouldNotify(
            $totalBusinessDays,
            $remainingBusinessDays,
            self::preExpiryWarningPercent($absenceSetting)
        );
    }

    public static function countConsecutiveFullAbsenceDays(
        Carbon $startDate,
        Carbon $endDate
    ): int {
        return static::countPeriodDays($startDate, $endDate);
    }

    public static function countPeriodDays(Carbon $startDate, Carbon $endDate): int
    {
        $from = $startDate->copy()->startOfDay();
        $to = $endDate->copy()->startOfDay();

        if ($to->lt($from)) {
            $to = $from;
        }

        return $from->diffInDays($to) + 1;
    }

    public static function needsMedicalCertificate(
        Carbon $startDate,
        Carbon $endDate,
        int $minimumDays
    ): bool {
        if ($minimumDays <= 0) {
            return false;
        }

        $days = static::countConsecutiveFullAbsenceDays($startDate, $endDate);

        return $days >= $minimumDays;
    }

    public static function countOpenLeaveHoursForStudent(
        int $studentId,
        ?Carbon $upToDate = null
    ): int {
        $query = Leave::query()
            ->where('student_id', $studentId)
            ->where('count_hours', true)
            ->whereNotIn('status', [
                Leave::STATUS_REJECTED,
                Leave::STATUS_REGISTERED,
                Leave::STATUS_FORWARDED_TO_MANAGEMENT,
            ]);

        if ($upToDate) {
            $query->whereDate('start_date', '<=', $upToDate->toDateString());
        }

        return (int) $query->sum('requested_hours');
    }

    public function exceedsMaxAnnualHours(?AbsenceSetting $absenceSetting = null): bool
    {
        if (! is_null($this->derived_from_leave_id)) {
            return false;
        }

        $studentId = (int) ($this->student_id ?? 0);
        if ($studentId <= 0) {
            return false;
        }

        $absenceSetting ??= AbsenceSetting::query()->firstOrFail();
        $maxAnnualHours = max((int) $absenceSetting->max_annual_hours, 0);
        if ($maxAnnualHours <= 0) {
            return false;
        }

        $reasonRules = AbsenceReason::query()
            ->get()
            ->keyBy(fn (AbsenceReason $reason) => strtolower(trim((string) $reason->name)));
        $hoursForThisAbsence = max((int) $this->assigned_hours, 0);
        $statusForCurrentAbsence = self::normalizeStatus($this->status) === self::STATUS_REPORTED
            ? self::STATUS_JUSTIFIED
            : null;
        $currentAbsenceCounts40 = $this->resolveCounts40Hours($reasonRules, $statusForCurrentAbsence);
        if (! $currentAbsenceCounts40) {
            return false;
        }

        $absenceHours = (int) static::query()
            ->where('student_id', $studentId)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->id))
            ->with('medicalCertificates')
            ->get()
            ->filter(fn (Absence $absence) => $absence->resolveCounts40Hours($reasonRules))
            ->sum('assigned_hours');
        $hoursAfterCurrentAbsence = $absenceHours + $hoursForThisAbsence;

        return $hoursAfterCurrentAbsence > $maxAnnualHours;
    }

    public function resolveMedicalCertificateRequired(?AbsenceSetting $absenceSetting = null): bool
    {
        if (! is_null($this->derived_from_leave_id)) {
            return false;
        }

        $absenceSetting ??= AbsenceSetting::query()->firstOrFail();
        $minimumDays = (int) $absenceSetting->medical_certificate_days;
        $startDate = Carbon::parse($this->start_date)->startOfDay();
        $endDate = $this->end_date
            ? Carbon::parse($this->end_date)->startOfDay()
            : $startDate->copy();

        $requiredByDays = static::needsMedicalCertificate(
            $startDate,
            $endDate,
            $minimumDays
        );

        return $requiredByDays || $this->exceedsMaxAnnualHours($absenceSetting);
    }

    public function syncMedicalCertificateRequired(?AbsenceSetting $absenceSetting = null): bool
    {
        $resolvedRequired = $this->resolveMedicalCertificateRequired($absenceSetting);

        if ((bool) $this->medical_certificate_required !== $resolvedRequired) {
            $this->medical_certificate_required = $resolvedRequired;
            $this->save();
        }

        return $resolvedRequired;
    }

    public static function buildCertificateRequirementStatus(
        bool $required,
        bool $certificateUploaded,
        bool $certificateValidated,
        ?int $daysToDeadline = null,
        ?string $absenceStatus = null
    ): array {
        if (! $required) {
            return [
                'code' => 'not_required',
                'label' => 'Certificato non obbligatorio',
                'short_label' => 'Non richiesto',
                'badge' => 'bg-slate-100 text-slate-700',
                'urgent' => false,
            ];
        }

        if ($certificateValidated) {
            return [
                'code' => 'required_done',
                'label' => 'Obbligo certificato assolto',
                'short_label' => 'Assolto',
                'badge' => 'bg-emerald-100 text-emerald-700',
                'urgent' => false,
            ];
        }

        $isOverdue = $daysToDeadline !== null && $daysToDeadline < 0;

        if ($isOverdue) {
            return [
                'code' => 'required_overdue',
                'label' => 'Certificato scaduto',
                'short_label' => 'Scaduto',
                'badge' => 'bg-rose-100 text-rose-700',
                'urgent' => true,
            ];
        }

        if ($certificateUploaded) {
            return [
                'code' => 'required_uploaded',
                'label' => 'Certificato inviato (in verifica)',
                'short_label' => 'In verifica',
                'badge' => 'bg-sky-100 text-sky-700',
                'urgent' => false,
            ];
        }

        return [
            'code' => 'required_pending',
            'label' => 'Certificato necessario',
            'short_label' => 'Necessario',
            'badge' => 'bg-amber-100 text-amber-700',
            'urgent' => true,
        ];
    }

    public function resolveCertificateRequirementStatus(
        ?AbsenceSetting $absenceSetting = null
    ): array {
        $deadline = $this->resolveMedicalCertificateDeadline($absenceSetting);
        $daysToDeadline = self::businessDaysUntil(Carbon::today(), $deadline);
        $certificateUploaded = $this->relationLoaded('medicalCertificates')
            ? $this->medicalCertificates->isNotEmpty()
            : $this->medicalCertificates()->exists();
        $certificateValidated = $this->relationLoaded('medicalCertificates')
            ? $this->medicalCertificates->contains(
                fn (MedicalCertificate $certificate) => $certificate->valid
            )
            : $this->medicalCertificates()->where('valid', true)->exists();
        $certificateRequired = $this->resolveMedicalCertificateRequired($absenceSetting);

        return self::buildCertificateRequirementStatus(
            $certificateRequired,
            $certificateUploaded,
            $certificateValidated,
            $daysToDeadline,
            $this->status
        );
    }

    public static function applyAutomaticArbitrary(): int
    {
        $today = Carbon::today();
        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $trackedStatuses = array_values(array_unique(array_merge(
            static::openStatuses(),
            [self::STATUS_JUSTIFIED, 'approved']
        )));

        $openAbsences = static::query()
            ->with([
                'student.guardians',
                'medicalCertificates',
                'guardianConfirmations',
            ])
            ->whereIn('status', $trackedStatuses)
            ->get();

        $updated = 0;
        $guardianSignatureRequired = (bool) $absenceSetting->guardian_signature_required;

        foreach ($openAbsences as $absence) {
            $statusCode = self::normalizeStatus($absence->status);
            $absence->syncMedicalCertificateRequired($absenceSetting);
            $effectiveDeadline = $absence->syncMedicalCertificateDeadline($absenceSetting);
            $daysToDeadline = self::businessDaysUntil($today, $effectiveDeadline);
            $certificateRequired = (bool) $absence->medical_certificate_required;
            $certificateValidated = $absence->medicalCertificates->contains(
                fn (MedicalCertificate $certificate) => $certificate->valid
            );
            $guardianSigned = self::hasSignedGuardianConfirmation($absence);
            $missingRequiredGuardianSignature = $guardianSignatureRequired
                && ! $guardianSigned
                && ! (bool) $absence->approved_without_guardian;
            $shouldExpireForDeadline = $statusCode === self::STATUS_REPORTED
                || ($statusCode === self::STATUS_JUSTIFIED
                    && (
                        ($certificateRequired && ! $certificateValidated)
                        || $missingRequiredGuardianSignature
                    ));

            if (! $shouldExpireForDeadline) {
                continue;
            }

            if ($absence->shouldSendPreExpiryWarning($absenceSetting, $today)) {
                app(StudentDeadlineReminderService::class)->sendAbsenceReminder(
                    $absence,
                    $effectiveDeadline,
                    static::preExpiryWarningPercent($absenceSetting)
                );
            }

            if (! $effectiveDeadline->lt($today)) {
                continue;
            }

            if ($absence->markArbitraryForExpiredDeadlineIfNeeded($absenceSetting, $today)) {
                $updated++;
            }
        }

        return $updated;
    }

    public function markArbitraryForExpiredDeadlineIfNeeded(
        ?AbsenceSetting $absenceSetting = null,
        ?Carbon $today = null
    ): bool {
        $today ??= Carbon::today();
        $absenceSetting ??= AbsenceSetting::query()->firstOrFail();
        $this->loadMissing([
            'student.guardians',
            'medicalCertificates',
            'guardianConfirmations',
        ]);

        $statusCode = self::normalizeStatus($this->status);
        if (! in_array($statusCode, [self::STATUS_REPORTED, self::STATUS_JUSTIFIED], true)) {
            return false;
        }

        $this->syncMedicalCertificateRequired($absenceSetting);
        $effectiveDeadline = $this->syncMedicalCertificateDeadline($absenceSetting);
        if (! $effectiveDeadline->lt($today)) {
            return false;
        }

        $certificateRequired = (bool) $this->medical_certificate_required;
        $certificateValidated = $this->medicalCertificates->contains(
            fn (MedicalCertificate $certificate) => $certificate->valid
        );
        $guardianSigned = self::hasSignedGuardianConfirmation($this);
        $missingRequiredGuardianSignature = (bool) $absenceSetting->guardian_signature_required
            && ! $guardianSigned
            && ! (bool) $this->approved_without_guardian;
        $shouldExpireForDeadline = $statusCode === self::STATUS_REPORTED
            || ($statusCode === self::STATUS_JUSTIFIED
                && (
                    ($certificateRequired && ! $certificateValidated)
                    || $missingRequiredGuardianSignature
                ));

        if (! $shouldExpireForDeadline) {
            return false;
        }

        $this->status = self::STATUS_ARBITRARY;
        $this->auto_arbitrary_at = now();
        $this->counts_40_hours = true;
        $this->counts_40_hours_comment = 'Assenza impostata come arbitraria per scadenza del termine.';
        $this->hours_decided_at = null;
        $this->hours_decided_by = null;
        $this->save();

        static::queueAutoArbitraryNotifications($this);

        return true;
    }

    private static function queueAutoArbitraryNotifications(self $absence): void
    {
        $studentEmail = $absence->student?->email;
        if ($studentEmail) {
            static::queueEmailNotification(
                $absence,
                'auto_arbitrary_student',
                $studentEmail,
                'Assenza impostata come arbitraria',
                'La tua assenza '.$absence->id.' e stata impostata come arbitraria per scadenza del termine.'
            );
        }

        $teacherEmails = User::query()
            ->select('users.email')
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->join('class_user', 'class_user.class_id', '=', 'class_teacher.class_id')
            ->where('class_user.user_id', $absence->student_id)
            ->whereNotNull('users.email')
            ->distinct()
            ->pluck('users.email');

        foreach ($teacherEmails as $teacherEmail) {
            static::queueEmailNotification(
                $absence,
                'auto_arbitrary_teacher',
                $teacherEmail,
                'Assenza studente impostata come arbitraria',
                'L assenza '.$absence->id.' e stata impostata come arbitraria per scadenza del termine.'
            );
        }
    }

    private static function queueEmailNotification(
        self $absence,
        string $type,
        string $recipientEmail,
        string $subject,
        string $body
    ): void {
        $alreadyQueued = AbsenceEmailNotification::query()
            ->where('absence_id', $absence->id)
            ->where('type', $type)
            ->where('recipient_email', $recipientEmail)
            ->exists();

        if ($alreadyQueued) {
            return;
        }

        AbsenceEmailNotification::create([
            'type' => $type,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'absence_id' => $absence->id,
            'status' => 'pending',
        ]);
    }

    private static function hasSignedGuardianConfirmation(self $absence): bool
    {
        return $absence->guardianConfirmations->contains(
            fn ($confirmation) => self::isGuardianConfirmationSigned($confirmation)
        );
    }

    private static function isGuardianConfirmationSigned(object $confirmation): bool
    {
        $status = strtolower(trim((string) ($confirmation->status ?? '')));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }

    public function resolveCounts40Hours(
        ?Collection $reasonRules = null,
        ?string $statusOverride = null
    ): bool {
        $statusCode = self::normalizeStatus($statusOverride ?? $this->status);

        if ($statusCode === self::STATUS_DRAFT) {
            return false;
        }

        if ($statusCode === self::STATUS_REPORTED) {
            return false;
        }

        if ($statusCode === self::STATUS_ARBITRARY) {
            return true;
        }

        $hasValidatedCertificate = $this->relationLoaded('medicalCertificates')
            ? $this->medicalCertificates->contains(
                fn (MedicalCertificate $certificate) => $certificate->valid
            )
            : $this->medicalCertificates()->where('valid', true)->exists();

        if ($hasValidatedCertificate) {
            return false;
        }

        // Teacher manual decision has priority over status/reason defaults.
        if ($this->hours_decided_by !== null) {
            return (bool) $this->counts_40_hours;
        }

        $reasonKey = strtolower(trim((string) ($this->reason ?? '')));
        if ($reasonKey === '') {
            return true;
        }
        if (str_starts_with($reasonKey, 'altro')) {
            return true;
        }

        $rules = $reasonRules;
        if ($rules === null) {
            $rules = AbsenceReason::query()
                ->get()
                ->keyBy(fn (AbsenceReason $reason) => strtolower(trim($reason->name)));
        }

        $rule = $rules->get($reasonKey);

        return $rule ? (bool) $rule->counts_40_hours : true;
    }

    public static function countHoursForStudent(
        int $studentId,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null
    ): int {
        $query = static::query()
            ->with('medicalCertificates')
            ->where('student_id', $studentId);

        if ($fromDate && $toDate) {
            $query->whereBetween('start_date', [
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ]);
        }

        $reasonRules = AbsenceReason::query()
            ->get()
            ->keyBy(fn (AbsenceReason $reason) => strtolower(trim($reason->name)));

        return (int) $query
            ->get()
            ->filter(fn (Absence $absence) => $absence->resolveCounts40Hours($reasonRules))
            ->sum('assigned_hours');
    }

    public function getAbsence(?User $user = null)
    {
        $query = Absence::query()->with([
            'student',
            'medicalCertificates',
            'guardianConfirmations.guardian',
            'derivedFromLeave',
        ]);
        $reasonRules = AbsenceReason::query()
            ->get()
            ->keyBy(fn (AbsenceReason $reason) => strtolower(trim($reason->name)));
        $absenceSetting = AbsenceSetting::query()->firstOrFail();

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
                })->where('status', '!=', self::STATUS_DRAFT);
            }
        }

        $assenze = $query
            ->get()
            ->map(function (Absence $assenza) use ($reasonRules, $absenceSetting, $user) {
                $statusCode = self::normalizeStatus($assenza->status);
                $assignedHours = max((int) $assenza->assigned_hours, 0);
                $ore = $assignedHours === 1
                    ? '1 ora'
                    : $assignedHours.' ore';
                $startDate = Carbon::parse($assenza->start_date)->startOfDay();

                $deadline = $assenza->resolveMedicalCertificateDeadline($absenceSetting);
                $daysToDeadline = self::businessDaysUntil(Carbon::today(), $deadline);
                $countdown = '-';

                $countdown = match (true) {
                    $daysToDeadline > 1 => $daysToDeadline.' giorni lavorativi',
                    $daysToDeadline === 1 => '1 giorno lavorativo',
                    $daysToDeadline === 0 => 'Scade oggi',
                    default => 'Scaduta da '.abs($daysToDeadline).' giorni lavorativi',
                };

                $studentName = $assenza->student
                    ? trim($assenza->student->name.' '.$assenza->student->surname)
                    : '-';
                $derivedLeave = $assenza->derivedFromLeave;
                $certificateUploaded = $assenza->medicalCertificates->isNotEmpty();
                $certificateValidated = $assenza->medicalCertificates->contains(
                    fn (MedicalCertificate $certificate) => $certificate->valid
                );
                $certificateRequired = $assenza->resolveMedicalCertificateRequired($absenceSetting);
                $certificateRequirement = self::buildCertificateRequirementStatus(
                    $certificateRequired,
                    $certificateUploaded,
                    $certificateValidated,
                    $daysToDeadline,
                    $statusCode
                );
                $guardianConfirmation = $assenza->guardianConfirmations
                    ->filter(function ($confirmation) {
                        $status = strtolower(trim((string) ($confirmation->status ?? '')));

                        return in_array($status, ['confirmed', 'approved', 'signed'], true)
                            || ! empty($confirmation->confirmed_at)
                            || ! empty($confirmation->signed_at);
                    })
                    ->sortBy(function ($confirmation) {
                        $signedAt = $confirmation->confirmed_at ?? $confirmation->signed_at;

                        return $signedAt?->timestamp ?? PHP_INT_MAX;
                    })
                    ->first();
                $guardianSigned = ! is_null($guardianConfirmation);
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

                if ($statusCode === self::STATUS_DRAFT) {
                    $label = 'Bozza congedo';
                    $badge = 'bg-indigo-100 text-indigo-700';
                } elseif ($statusCode === self::STATUS_JUSTIFIED) {
                    $label = 'Giustificata';
                    $badge = 'bg-emerald-100 text-emerald-700';
                } elseif ($statusCode === self::STATUS_ARBITRARY) {
                    $label = 'Arbitraria';
                    $badge = 'bg-rose-100 text-rose-700';
                } elseif ($guardianSigned) {
                    $label = 'Firmata (attesa docente)';
                    $badge = 'bg-sky-100 text-sky-700';
                } elseif ((bool) $absenceSetting->guardian_signature_required) {
                    $label = 'Attesa firma tutore';
                    $badge = 'bg-amber-100 text-amber-700';
                } else {
                    $label = 'Attesa docente';
                    $badge = 'bg-amber-100 text-amber-700';
                }

                $counts40Hours = $assenza->resolveCounts40Hours($reasonRules);
                $count40Label = in_array($statusCode, [self::STATUS_DRAFT, self::STATUS_REPORTED], true)
                    ? 'Da calcolare a pratica conclusa'
                    : ($counts40Hours
                        ? AnnualHoursLimitLabels::included($absenceSetting)
                        : AnnualHoursLimitLabels::excluded($absenceSetting));
                $canApprove = $statusCode === self::STATUS_REPORTED && $guardianSigned;
                $canApproveWithoutGuardian = $statusCode === self::STATUS_REPORTED && ! $guardianSigned;
                $canReject = $statusCode === self::STATUS_REPORTED;
                $canExtendDeadline = $statusCode === self::STATUS_ARBITRARY;
                $canResendGuardianEmail = in_array(
                    $statusCode,
                    [self::STATUS_REPORTED, self::STATUS_ARBITRARY],
                    true
                ) && ! $guardianSigned;
                $isDerivedFromLeave = ! is_null($assenza->derived_from_leave_id);
                $canAcceptCertificate = in_array($statusCode, [self::STATUS_REPORTED, self::STATUS_JUSTIFIED], true)
                    && $certificateUploaded
                    && ! $certificateValidated;
                $canRejectCertificate = in_array($statusCode, [self::STATUS_REPORTED, self::STATUS_JUSTIFIED], true)
                    && $certificateUploaded
                    && ! $certificateValidated;
                $derivedLeaveCode = $isDerivedFromLeave
                    ? 'C-'.str_pad((string) $assenza->derived_from_leave_id, 4, '0', STR_PAD_LEFT)
                    : null;
                $canEditAbsence = in_array(
                    $statusCode,
                    [self::STATUS_REPORTED, self::STATUS_ARBITRARY, self::STATUS_JUSTIFIED],
                    true
                );
                $canDeleteAbsence = $user
                    ? ($user->hasRole('teacher') || $user->hasRole('laboratory_manager'))
                    : false;
                $canSubmitDraft = $isDerivedFromLeave
                    && $statusCode === self::STATUS_DRAFT
                    && $startDate->lte(Carbon::today()->startOfDay())
                    && $user?->hasRole('student');
                $draftEditUrl = $canSubmitDraft
                    ? route('student.absences.derived-draft.edit', ['absence' => $assenza->id])
                    : null;

                return [
                    'id' => 'A-'.str_pad((string) $assenza->id, 4, '0', STR_PAD_LEFT),
                    'absence_id' => $assenza->id,
                    'student_id' => $assenza->student_id,
                    'derived_from_leave_id' => $assenza->derived_from_leave_id,
                    'derived_leave_code' => $derivedLeaveCode,
                    'studente' => $studentName,
                    'date' => Carbon::parse($assenza->start_date)->toDateString(),
                    'start_date' => $startDate->toDateString(),
                    'end_date' => Carbon::parse($assenza->end_date ?? $assenza->start_date)
                        ->toDateString(),
                    'hours' => $assignedHours,
                    'durata' => $ore,
                    'motivo' => $assenza->reason ?? '-',
                    'data' => Carbon::parse($assenza->start_date)->format('d M Y'),
                    'scadenza' => $deadline->format('d M Y'),
                    'countdown' => $countdown,
                    'giorni_alla_scadenza' => $daysToDeadline,
                    'tipo' => 'Assenza',
                    'stato_code' => $statusCode,
                    'stato' => $label,
                    'badge' => $badge,
                    'conteggio_40_ore' => $counts40Hours,
                    'conteggio_40_ore_label' => $count40Label,
                    'certificato_richiesto' => $certificateRequired,
                    'certificato_caricato' => $certificateUploaded,
                    'certificato_validato' => $certificateValidated,
                    'certificato_obbligo_code' => $certificateRequirement['code'],
                    'certificato_obbligo' => $certificateRequirement['label'],
                    'certificato_obbligo_short' => $certificateRequirement['short_label'],
                    'certificato_obbligo_badge' => $certificateRequirement['badge'],
                    'certificato_obbligo_urgente' => $certificateRequirement['urgent'],
                    'commento_docente' => (string) ($assenza->teacher_comment ?? ''),
                    'commento_rifiuto_certificato' => (string) ($assenza->certificate_rejection_comment ?? ''),
                    'firma_tutore_presente' => $guardianSigned,
                    'firma_tutore_data' => $guardianSignedAt?->format('d M Y H:i'),
                    'firma_tutore_nome' => $guardianSignerName,
                    'firma_tutore_label' => $guardianSignatureLabel,
                    'can_approve' => $canApprove,
                    'can_approve_without_guardian' => $canApproveWithoutGuardian,
                    'can_reject' => $canReject,
                    'can_extend_deadline' => $canExtendDeadline,
                    'can_resend_guardian_email' => $canResendGuardianEmail,
                    'can_accept_certificate' => $canAcceptCertificate,
                    'can_reject_certificate' => $canRejectCertificate,
                    'can_edit_absence' => $canEditAbsence,
                    'can_delete_absence' => $canDeleteAbsence,
                    'can_submit_draft' => $canSubmitDraft,
                    'draft_edit_url' => $draftEditUrl,
                    'can_update_effective_hours' => false,
                    'derivata_da_congedo' => $isDerivedFromLeave,
                ];
            });

        return $assenze;
    }
}
