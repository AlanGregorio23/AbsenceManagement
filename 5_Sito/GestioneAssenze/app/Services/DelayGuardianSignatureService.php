<?php

namespace App\Services;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Jobs\Mail\GuardianDelaySignatureMail;
use App\Models\Delay;
use App\Models\DelayConfirmationToken;
use App\Models\DelayEmailNotification;
use App\Models\Guardian;
use App\Models\GuardianDelayConfirmation;
use App\Models\OperationLog;
use App\Models\User;
use App\Support\SystemSettingsResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class DelayGuardianSignatureService
{
    public function __construct(
        private readonly InactiveGuardianNotificationResolver $inactiveGuardianNotificationResolver
    ) {}

    /**
     * @return array{guardians:int,sent:int,failed:int,skipped:int}
     */
    public function sendConfirmationEmails(
        Delay $delay,
        ?User $actor = null,
        bool $forceResend = false,
        ?Request $request = null,
        array $guardianIds = []
    ): array {
        $guardians = $delay->student
            ? $this->inactiveGuardianNotificationResolver->resolveSigningGuardiansForStudent($delay->student)
            : collect();

        if ($guardianIds !== []) {
            $allowedGuardianIds = collect($guardianIds)->map(fn ($id) => (int) $id)->all();
            $guardians = $guardians
                ->whereIn('id', $allowedGuardianIds)
                ->values();
        }

        $result = [
            'guardians' => $guardians->count(),
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if ($guardians->isEmpty()) {
            OperationLog::record(
                $actor,
                'delay.guardian_confirmation_email.missing_guardian',
                'delay',
                $delay->id,
                [
                    'student_id' => $delay->student_id,
                ],
                'WARNING',
                $request
            );

            if (! $forceResend) {
                $this->sendInactiveGuardiansInfoEmails($delay, $actor, $request, 'signature', $guardians);
            }

            return $result;
        }

        if ($this->delayAlreadySigned($delay->id)) {
            $result['skipped'] = $guardians->count();

            return $result;
        }

        foreach ($guardians as $guardian) {
            if (! $forceResend && $this->guardianAlreadySigned($delay->id, $guardian->id)) {
                $result['skipped']++;

                continue;
            }

            [$plainToken, $token] = $this->createToken($delay, $guardian->id, $forceResend);
            $signatureUrl = route('guardian.delays.signature.show', ['token' => $plainToken]);
            $subject = $this->buildSubject($delay);

            try {
                Mail::to($guardian->email)
                    ->send(new GuardianDelaySignatureMail($delay, $guardian, $signatureUrl, $token->expires_at));

                DelayEmailNotification::create([
                    'type' => $forceResend ? 'guardian_signature_resend' : 'guardian_signature_request',
                    'recipient_email' => $guardian->email,
                    'subject' => $subject,
                    'body' => $this->buildNotificationBody($delay, $token->expires_at, $forceResend),
                    'delay_id' => $delay->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                OperationLog::record(
                    $actor,
                    $forceResend
                        ? 'delay.guardian_confirmation_email.resent'
                        : 'delay.guardian_confirmation_email.sent',
                    'delay',
                    $delay->id,
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
                DelayEmailNotification::create([
                    'type' => $forceResend ? 'guardian_signature_resend' : 'guardian_signature_request',
                    'recipient_email' => $guardian->email,
                    'subject' => $subject,
                    'body' => $this->buildNotificationBody($delay, $token->expires_at, $forceResend),
                    'delay_id' => $delay->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $actor,
                    'delay.guardian_confirmation_email.failed',
                    'delay',
                    $delay->id,
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
            $this->sendInactiveGuardiansInfoEmails($delay, $actor, $request, 'signature', $guardians);
        }

        return $result;
    }

    public function sendReportedInfoEmails(
        Delay $delay,
        ?User $actor = null,
        ?Request $request = null
    ): void {
        $this->sendInactiveGuardiansInfoEmails($delay, $actor, $request, 'reported');
    }

    /**
     * @return array{status:string,token:?DelayConfirmationToken}
     */
    public function getTokenState(string $plainToken): array
    {
        $token = $this->findTokenByPlainValue($plainToken);

        if (! $token) {
            return ['status' => 'invalid', 'token' => null];
        }

        if ($this->delayAlreadySigned($token->delay_id)) {
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

    public function markTokenAsUsed(DelayConfirmationToken $token): void
    {
        $usedAt = now();
        $token->used_at = $usedAt;
        $token->save();

        DelayConfirmationToken::query()
            ->where('delay_id', $token->delay_id)
            ->whereNull('used_at')
            ->where('id', '!=', $token->id)
            ->update(['used_at' => $usedAt]);
    }

    public function resolveFirstSignedConfirmation(int $delayId): ?GuardianDelayConfirmation
    {
        return GuardianDelayConfirmation::query()
            ->with('guardian')
            ->where('delay_id', $delayId)
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

    /**
     * @return array{delays:int,guardians:int,sent:int,failed:int,skipped:int}
     */
    public function resendExpiredTokensForOpenDelays(?User $actor = null, ?Request $request = null): array
    {
        $delaySetting = SystemSettingsResolver::delaySetting();
        if (! (bool) $delaySetting->guardian_signature_required) {
            return ['delays' => 0, 'guardians' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $openDelays = Delay::query()
            ->whereIn('status', [Delay::STATUS_REPORTED, Delay::STATUS_REGISTERED])
            ->with('student.guardians')
            ->get();

        $summary = ['delays' => 0, 'guardians' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($openDelays as $delay) {
            $summary['delays']++;

            if ($this->delayAlreadySigned($delay->id)) {
                $summary['skipped']++;

                continue;
            }

            $eligibleGuardianIds = $delay->student?->guardians
                ?->filter(fn (Guardian $guardian) => filled(trim((string) $guardian->email)))
                ->filter(fn (Guardian $guardian) => $this->shouldResendExpiredToken($delay->id, $guardian->id))
                ->pluck('id')
                ->map(fn ($guardianId) => (int) $guardianId)
                ->values()
                ->all()
                ?? [];

            $summary['guardians'] += count($eligibleGuardianIds);

            if ($eligibleGuardianIds === []) {
                $summary['skipped']++;

                continue;
            }

            $result = $this->sendConfirmationEmails(
                $delay,
                $actor,
                true,
                $request,
                $eligibleGuardianIds
            );

            $summary['sent'] += $result['sent'];
            $summary['failed'] += $result['failed'];
            $summary['skipped'] += $result['skipped'];
        }

        return $summary;
    }

    private function guardianAlreadySigned(int $delayId, int $guardianId): bool
    {
        return GuardianDelayConfirmation::query()
            ->where('delay_id', $delayId)
            ->where('guardian_id', $guardianId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    private function delayAlreadySigned(int $delayId): bool
    {
        return GuardianDelayConfirmation::query()
            ->where('delay_id', $delayId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    private function shouldResendExpiredToken(int $delayId, int $guardianId): bool
    {
        if ($this->guardianAlreadySigned($delayId, $guardianId)) {
            return false;
        }

        $latestToken = DelayConfirmationToken::query()
            ->where('delay_id', $delayId)
            ->where('guardian_id', $guardianId)
            ->orderByDesc('id')
            ->first();

        if (! $latestToken) {
            return true;
        }

        if ($latestToken->used_at) {
            return false;
        }

        return Carbon::parse($latestToken->expires_at)->isPast();
    }

    /**
     * @return array{0:string,1:DelayConfirmationToken}
     */
    private function createToken(Delay $delay, int $guardianId, bool $forceResend): array
    {
        if ($forceResend) {
            DelayConfirmationToken::query()
                ->where('delay_id', $delay->id)
                ->where('guardian_id', $guardianId)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        }

        $plainToken = Str::random(80);
        $delaySetting = SystemSettingsResolver::delaySetting();
        $expiresAt = now()
            ->addDays((int) $delaySetting->justification_business_days)
            ->endOfDay();

        if (Delay::deadlineModeActive($delaySetting)
            && Delay::normalizeStatus($delay->status) === Delay::STATUS_REGISTERED
        ) {
            $expiresAt = $delay->resolveJustificationDeadline($delaySetting)
                ->copy()
                ->endOfDay();
        }

        if ($expiresAt->lt(now()->addMinutes(15))) {
            $expiresAt = now()->addMinutes(15);
        }

        $token = DelayConfirmationToken::create([
            'delay_id' => $delay->id,
            'guardian_id' => $guardianId,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
        ]);

        return [$plainToken, $token];
    }

    private function findTokenByPlainValue(string $plainToken): ?DelayConfirmationToken
    {
        $hash = hash('sha256', trim($plainToken));

        return DelayConfirmationToken::query()
            ->where('token_hash', $hash)
            ->with(['delay.student', 'guardian'])
            ->orderByDesc('id')
            ->first();
    }

    private function buildSubject(Delay $delay): string
    {
        $studentName = trim((string) ($delay->student?->name ?? '').' '.(string) ($delay->student?->surname ?? ''));

        return $studentName !== ''
            ? 'Conferma ritardo - '.$studentName
            : 'Conferma ritardo studente';
    }

    private function buildNotificationBody(Delay $delay, ?Carbon $expiresAt, bool $forceResend): string
    {
        $lines = [
            $forceResend
                ? 'Promemoria: e richiesta la firma del tutore per confermare il ritardo.'
                : 'E richiesta la firma del tutore per confermare il ritardo.',
            'Riferimento pratica: ritardo #'.$delay->id.'.',
        ];

        if ($expiresAt) {
            $lines[] = 'Scadenza link firma: '.$expiresAt->format('d/m/Y H:i').'.';
        }

        $lines[] = 'Grazie per la collaborazione.';

        return implode(PHP_EOL, $lines);
    }

    private function sendInactiveGuardiansInfoEmails(
        Delay $delay,
        ?User $actor,
        ?Request $request,
        string $kind,
        ?Collection $signingGuardians = null
    ): void {
        $student = $delay->student;
        if (! $student) {
            return;
        }

        $informativeGuardians = $this->inactiveGuardianNotificationResolver
            ->resolveForStudent($student, $signingGuardians);
        if ($informativeGuardians->isEmpty()) {
            return;
        }

        $type = $kind === 'signature'
            ? 'inactive_guardian_signature_info'
            : 'inactive_guardian_reported_info';
        $title = $kind === 'signature'
            ? 'Aggiornamento ritardo con firma richiesta (solo informativa)'
            : 'Notifica ritardo (solo informativa)';
        $subjectPrefix = $kind === 'signature'
            ? 'Notifica firma ritardo studente maggiorenne'
            : 'Notifica ritardo studente maggiorenne';
        $intro = $kind === 'signature'
            ? 'E presente una richiesta di conferma ritardo per lo studente maggiorenne. Questa email e solo informativa.'
            : 'Lo studente maggiorenne ha segnalato un ritardo. Questa email e solo informativa.';
        $closing = 'Non devi firmare e non devi rispondere a questa email.';

        $studentName = trim((string) ($student->name ?? '').' '.(string) ($student->surname ?? ''));
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $subject = $subjectPrefix.' - '.$studentName;
        $delayDateLabel = Carbon::parse($delay->delay_datetime)->format('d/m/Y H:i');
        $delayCode = 'R-'.str_pad((string) $delay->id, 4, '0', STR_PAD_LEFT);
        $body = implode(PHP_EOL, [
            $intro,
            'Studente: '.$studentName.'.',
            'Codice ritardo: '.$delayCode.'.',
            'Data/ora: '.$delayDateLabel.'.',
            'Minuti: '.max((int) ($delay->minutes ?? 0), 0).'.',
            $closing,
        ]);

        foreach ($informativeGuardians as $guardian) {
            $recipientEmail = strtolower(trim((string) $guardian->email));
            if ($recipientEmail === '') {
                continue;
            }

            if ($this->hasInactiveGuardianInfoAlreadySent($delay->id, $type, $recipientEmail)) {
                continue;
            }

            try {
                Mail::to($recipientEmail)->send(new AdultStudentGuardianInfoMail(
                    $subject,
                    $title,
                    $intro,
                    [
                        'Studente: '.$studentName,
                        'Codice ritardo: '.$delayCode,
                        'Data/ora: '.$delayDateLabel,
                        'Minuti: '.max((int) ($delay->minutes ?? 0), 0),
                    ],
                    $closing
                ));

                DelayEmailNotification::create([
                    'type' => $type,
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'delay_id' => $delay->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                OperationLog::record(
                    $actor,
                    'delay.inactive_guardian_info_email.sent',
                    'delay',
                    $delay->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                        'type' => $type,
                    ],
                    'INFO',
                    $request
                );
            } catch (Throwable $exception) {
                DelayEmailNotification::create([
                    'type' => $type,
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'delay_id' => $delay->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $actor,
                    'delay.inactive_guardian_info_email.failed',
                    'delay',
                    $delay->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                        'type' => $type,
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR',
                    $request
                );

                report($exception);
            }
        }
    }

    private function hasInactiveGuardianInfoAlreadySent(
        int $delayId,
        string $type,
        string $recipientEmail
    ): bool {
        return DelayEmailNotification::query()
            ->where('delay_id', $delayId)
            ->where('type', $type)
            ->where('recipient_email', $recipientEmail)
            ->where('status', 'sent')
            ->exists();
    }
}
