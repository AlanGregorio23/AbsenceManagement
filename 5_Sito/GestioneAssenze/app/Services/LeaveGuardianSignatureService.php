<?php

namespace App\Services;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Jobs\Mail\GuardianLeaveSignatureMail;
use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\GuardianLeaveConfirmation;
use App\Models\Leave;
use App\Models\LeaveConfirmationToken;
use App\Models\LeaveEmailNotification;
use App\Models\OperationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class LeaveGuardianSignatureService
{
    public function __construct(
        private readonly InactiveGuardianNotificationResolver $inactiveGuardianNotificationResolver
    ) {}

    /**
     * @return array{guardians:int,sent:int,failed:int,skipped:int}
     */
    public function sendConfirmationEmails(
        Leave $leave,
        ?User $actor = null,
        bool $forceResend = false,
        ?Request $request = null
    ): array {
        $guardians = $leave->student
            ? $this->inactiveGuardianNotificationResolver->resolveSigningGuardiansForStudent($leave->student)
            : collect();

        $result = [
            'guardians' => $guardians->count(),
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if ($guardians->isEmpty()) {
            OperationLog::record(
                $actor,
                'leave.guardian_confirmation_email.missing_guardian',
                'leave',
                $leave->id,
                [
                    'student_id' => $leave->student_id,
                ],
                'WARNING',
                $request
            );

            if (! $forceResend) {
                $this->sendInactiveGuardianInfoEmails($leave, $actor, $request, $guardians);
            }

            return $result;
        }

        if ($this->leaveAlreadySigned($leave->id)) {
            $result['skipped'] = $guardians->count();

            return $result;
        }

        foreach ($guardians as $guardian) {
            if (! $forceResend && $this->guardianAlreadySigned($leave->id, $guardian->id)) {
                $result['skipped']++;

                continue;
            }

            [$plainToken, $token] = $this->createToken($leave, $guardian->id, $forceResend);
            $signatureUrl = route('guardian.leaves.signature.show', ['token' => $plainToken]);
            $subject = $this->buildSubject($leave);

            try {
                Mail::to($guardian->email)
                    ->send(new GuardianLeaveSignatureMail($leave, $guardian, $signatureUrl, $token->expires_at));

                LeaveEmailNotification::create([
                    'type' => $forceResend ? 'guardian_signature_resend' : 'guardian_signature_request',
                    'recipient_email' => $guardian->email,
                    'subject' => $subject,
                    'body' => $this->buildNotificationBody($leave, $token->expires_at, $forceResend),
                    'leave_id' => $leave->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                OperationLog::record(
                    $actor,
                    $forceResend
                        ? 'leave.guardian_confirmation_email.resent'
                        : 'leave.guardian_confirmation_email.sent',
                    'leave',
                    $leave->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $guardian->email,
                        'token_id' => $token->id,
                        'expires_at' => $token->expires_at?->toIso8601String(),
                    ],
                    'INFO',
                    $request
                );

                $result['sent']++;
            } catch (Throwable $exception) {
                LeaveEmailNotification::create([
                    'type' => $forceResend ? 'guardian_signature_resend' : 'guardian_signature_request',
                    'recipient_email' => $guardian->email,
                    'subject' => $subject,
                    'body' => $this->buildNotificationBody($leave, $token->expires_at, $forceResend),
                    'leave_id' => $leave->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $actor,
                    'leave.guardian_confirmation_email.failed',
                    'leave',
                    $leave->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $guardian->email,
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR',
                    $request
                );

                report($exception);
                $result['failed']++;
            }
        }

        if (! $forceResend) {
            $this->sendInactiveGuardianInfoEmails($leave, $actor, $request, $guardians);
        }

        return $result;
    }

    /**
     * @return array{status:string,token:?LeaveConfirmationToken}
     */
    public function getTokenState(string $plainToken): array
    {
        $token = $this->findTokenByPlainValue($plainToken);

        if (! $token) {
            return ['status' => 'invalid', 'token' => null];
        }

        if ($this->leaveAlreadySigned($token->leave_id)) {
            return ['status' => 'already_signed', 'token' => $token];
        }

        if ($token->used_at) {
            return ['status' => 'used', 'token' => $token];
        }

        if (Carbon::parse($token->expires_at)->isPast()) {
            return ['status' => 'expired', 'token' => $token];
        }

        return ['status' => 'valid', 'token' => $token];
    }

    public function markTokenAsUsed(LeaveConfirmationToken $token): void
    {
        $usedAt = now();
        $token->used_at = $usedAt;
        $token->save();

        LeaveConfirmationToken::query()
            ->where('leave_id', $token->leave_id)
            ->whereNull('used_at')
            ->where('id', '!=', $token->id)
            ->update(['used_at' => $usedAt]);
    }

    public function resolveFirstSignedConfirmation(int $leaveId): ?GuardianLeaveConfirmation
    {
        return GuardianLeaveConfirmation::query()
            ->with('guardian')
            ->where('leave_id', $leaveId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->orderByRaw('COALESCE(confirmed_at, signed_at) asc')
            ->orderBy('id')
            ->first();
    }

    private function guardianAlreadySigned(int $leaveId, int $guardianId): bool
    {
        return GuardianLeaveConfirmation::query()
            ->where('leave_id', $leaveId)
            ->where('guardian_id', $guardianId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    private function leaveAlreadySigned(int $leaveId): bool
    {
        return GuardianLeaveConfirmation::query()
            ->where('leave_id', $leaveId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    /**
     * @return array{0:string,1:LeaveConfirmationToken}
     */
    private function createToken(Leave $leave, int $guardianId, bool $forceResend): array
    {
        if ($forceResend) {
            LeaveConfirmationToken::query()
                ->where('leave_id', $leave->id)
                ->where('guardian_id', $guardianId)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        }

        $plainToken = Str::random(80);
        $setting = AbsenceSetting::query()->firstOrFail();
        $countdownDays = max((int) $setting->absence_countdown_days, 1);
        $referenceDate = $leave->end_date
            ? Carbon::parse($leave->end_date)->startOfDay()
            : Carbon::today();
        $expiresAt = Absence::addBusinessDays($referenceDate->copy(), $countdownDays)->endOfDay();

        if ($expiresAt->lt(now()->addMinutes(15))) {
            $expiresAt = now()->addMinutes(15);
        }

        $token = LeaveConfirmationToken::create([
            'leave_id' => $leave->id,
            'guardian_id' => $guardianId,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
        ]);

        return [$plainToken, $token];
    }

    private function findTokenByPlainValue(string $plainToken): ?LeaveConfirmationToken
    {
        $hash = hash('sha256', trim($plainToken));

        return LeaveConfirmationToken::query()
            ->where('token_hash', $hash)
            ->with(['leave.student', 'guardian'])
            ->orderByDesc('id')
            ->first();
    }

    private function buildSubject(Leave $leave): string
    {
        $studentName = trim((string) ($leave->student?->name ?? '').' '.(string) ($leave->student?->surname ?? ''));

        return $studentName !== ''
            ? 'Conferma congedo - '.$studentName
            : 'Conferma congedo studente';
    }

    private function buildNotificationBody(Leave $leave, ?Carbon $expiresAt, bool $forceResend): string
    {
        $requestedLessonsLabel = Leave::formatRequestedLessonsLabel(
            Leave::normalizeRequestedLessonsPayload($leave->requested_lessons),
            $leave->start_date?->toDateString(),
            $leave->end_date?->toDateString()
        );
        $lines = [
            $forceResend
                ? 'Promemoria: e richiesta la firma del tutore per confermare il congedo.'
                : 'E richiesta la firma del tutore per confermare il congedo.',
            'Riferimento pratica: congedo #'.$leave->id.'.',
            'Ore richieste: '.max((int) ($leave->requested_hours ?? 0), 0).'.',
        ];
        if ($requestedLessonsLabel !== '') {
            $lines[] = 'Periodi scolastici: '.$requestedLessonsLabel.'.';
        }

        if ($expiresAt) {
            $lines[] = 'Scadenza link firma: '.$expiresAt->format('d/m/Y H:i').'.';
        }

        $lines[] = 'Grazie per la collaborazione.';

        return implode(PHP_EOL, $lines);
    }

    private function sendInactiveGuardianInfoEmails(
        Leave $leave,
        ?User $actor = null,
        ?Request $request = null,
        ?Collection $signingGuardians = null
    ): void {
        $student = $leave->student;
        if (! $student) {
            return;
        }

        $informativeGuardians = $this->inactiveGuardianNotificationResolver
            ->resolveForStudent($student, $signingGuardians);
        if ($informativeGuardians->isEmpty()) {
            return;
        }

        $studentName = trim((string) ($student->name ?? '').' '.(string) ($student->surname ?? ''));
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $periodStart = Carbon::parse($leave->start_date)->format('d/m/Y');
        $periodEnd = Carbon::parse($leave->end_date ?? $leave->start_date)->format('d/m/Y');
        $periodLabel = $periodStart === $periodEnd ? $periodStart : $periodStart.' - '.$periodEnd;
        $requestedLessonsLabel = Leave::formatRequestedLessonsLabel(
            Leave::normalizeRequestedLessonsPayload($leave->requested_lessons),
            $leave->start_date?->toDateString(),
            $leave->end_date?->toDateString()
        );
        $leaveCode = 'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT);
        $closing = 'Non devi firmare e non devi rispondere a questa email.';
        $subject = 'Notifica congedo studente maggiorenne - '.$studentName;
        $bodyLines = [
            'Lo studente maggiorenne ha inviato una richiesta di congedo. Questa email e solo informativa.',
            'Codice congedo: '.$leaveCode.'.',
            'Periodo: '.$periodLabel.'.',
            'Ore richieste: '.max((int) ($leave->requested_hours ?? 0), 0).'.',
            $closing,
        ];
        if ($requestedLessonsLabel !== '') {
            $bodyLines[] = 'Periodi scolastici: '.$requestedLessonsLabel.'.';
        }
        $body = implode(PHP_EOL, $bodyLines);

        foreach ($informativeGuardians as $guardian) {
            $recipientEmail = strtolower(trim((string) $guardian->email));
            if ($recipientEmail === '') {
                continue;
            }

            if ($this->hasInactiveGuardianInfoAlreadySent($leave->id, $recipientEmail)) {
                continue;
            }

            try {
                Mail::to($recipientEmail)->send(new AdultStudentGuardianInfoMail(
                    $subject,
                    'Notifica congedo (solo informativa)',
                    'Lo studente maggiorenne ha inviato una richiesta di congedo. Questa email e solo informativa.',
                    array_filter([
                        'Studente: '.$studentName,
                        'Codice congedo: '.$leaveCode,
                        'Periodo: '.$periodLabel,
                        'Ore richieste: '.max((int) ($leave->requested_hours ?? 0), 0),
                        $requestedLessonsLabel !== '' ? 'Periodi scolastici: '.$requestedLessonsLabel : null,
                    ]),
                    $closing
                ));

                LeaveEmailNotification::create([
                    'type' => 'inactive_guardian_info',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'leave_id' => $leave->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                OperationLog::record(
                    $actor,
                    'leave.inactive_guardian_info_email.sent',
                    'leave',
                    $leave->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                    ],
                    'INFO',
                    $request
                );
            } catch (Throwable $exception) {
                LeaveEmailNotification::create([
                    'type' => 'inactive_guardian_info',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'leave_id' => $leave->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $actor,
                    'leave.inactive_guardian_info_email.failed',
                    'leave',
                    $leave->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR',
                    $request
                );

                report($exception);
            }
        }
    }

    private function hasInactiveGuardianInfoAlreadySent(int $leaveId, string $recipientEmail): bool
    {
        return LeaveEmailNotification::query()
            ->where('leave_id', $leaveId)
            ->where('type', 'inactive_guardian_info')
            ->where('recipient_email', $recipientEmail)
            ->where('status', 'sent')
            ->exists();
    }
}
