<?php

namespace App\Services;

use App\Mail\AdultStudentGuardianInfoMail;
use App\Mail\GuardianAbsenceSignatureMail;
use App\Models\Absence;
use App\Models\AbsenceConfirmationToken;
use App\Models\AbsenceEmailNotification;
use App\Models\GuardianAbsenceConfirmation;
use App\Models\OperationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class AbsenceGuardianSignatureService
{
    public function __construct(
        private readonly InactiveGuardianNotificationResolver $inactiveGuardianNotificationResolver
    ) {}

    /**
     * @return array{guardians:int,sent:int,failed:int,skipped:int}
     */
    public function sendConfirmationEmails(
        Absence $absence,
        ?User $actor = null,
        bool $forceResend = false,
        ?Request $request = null
    ): array {
        $guardians = $absence->student
            ? $this->inactiveGuardianNotificationResolver->resolveSigningGuardiansForStudent($absence->student)
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
                'absence.guardian_confirmation_email.missing_guardian',
                'absence',
                $absence->id,
                [
                    'student_id' => $absence->student_id,
                ],
                'WARNING',
                $request
            );

            if (! $forceResend) {
                $this->sendInactiveGuardianInfoEmails($absence, $actor, $request, $guardians);
            }

            return $result;
        }

        if ($this->absenceAlreadySigned($absence->id)) {
            $result['skipped'] = $guardians->count();

            return $result;
        }

        foreach ($guardians as $guardian) {
            if (! $forceResend && $this->guardianAlreadySigned($absence->id, $guardian->id)) {
                $result['skipped']++;

                continue;
            }

            [$plainToken, $token] = $this->createToken($absence, $guardian->id, $forceResend);
            $signatureUrl = route('guardian.absences.signature.show', ['token' => $plainToken]);
            $subject = $this->buildSubject($absence);

            try {
                Mail::to($guardian->email)
                    ->send(new GuardianAbsenceSignatureMail($absence, $guardian, $signatureUrl, $token->expires_at));

                AbsenceEmailNotification::create([
                    'type' => $forceResend ? 'guardian_signature_resend' : 'guardian_signature_request',
                    'recipient_email' => $guardian->email,
                    'subject' => $subject,
                    'body' => $this->buildNotificationBody($absence, $token->expires_at, $forceResend),
                    'absence_id' => $absence->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                OperationLog::record(
                    $actor,
                    $forceResend
                        ? 'absence.guardian_confirmation_email.resent'
                        : 'absence.guardian_confirmation_email.sent',
                    'absence',
                    $absence->id,
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
                AbsenceEmailNotification::create([
                    'type' => $forceResend ? 'guardian_signature_resend' : 'guardian_signature_request',
                    'recipient_email' => $guardian->email,
                    'subject' => $subject,
                    'body' => $this->buildNotificationBody($absence, $token->expires_at, $forceResend),
                    'absence_id' => $absence->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $actor,
                    'absence.guardian_confirmation_email.failed',
                    'absence',
                    $absence->id,
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
            $this->sendInactiveGuardianInfoEmails($absence, $actor, $request, $guardians);
        }

        return $result;
    }

    public function sendInformativeEmails(
        Absence $absence,
        ?User $actor = null,
        ?Request $request = null
    ): void {
        $signingGuardians = $absence->student
            ? $this->inactiveGuardianNotificationResolver->resolveSigningGuardiansForStudent($absence->student)
            : collect();

        $this->sendInactiveGuardianInfoEmails($absence, $actor, $request, $signingGuardians);
    }

    /**
     * @return array{status:string,token:?AbsenceConfirmationToken}
     */
    public function getTokenState(string $plainToken): array
    {
        $token = $this->findTokenByPlainValue($plainToken);

        if (! $token) {
            return ['status' => 'invalid', 'token' => null];
        }

        if ($this->absenceAlreadySigned($token->absence_id)) {
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

    public function markTokenAsUsed(AbsenceConfirmationToken $token): void
    {
        $usedAt = now();
        $token->used_at = $usedAt;
        $token->save();

        AbsenceConfirmationToken::query()
            ->where('absence_id', $token->absence_id)
            ->whereNull('used_at')
            ->where('id', '!=', $token->id)
            ->update(['used_at' => $usedAt]);
    }

    public function resolveFirstSignedConfirmation(int $absenceId): ?GuardianAbsenceConfirmation
    {
        return GuardianAbsenceConfirmation::query()
            ->with('guardian')
            ->where('absence_id', $absenceId)
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

    private function guardianAlreadySigned(int $absenceId, int $guardianId): bool
    {
        return GuardianAbsenceConfirmation::query()
            ->where('absence_id', $absenceId)
            ->where('guardian_id', $guardianId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    private function absenceAlreadySigned(int $absenceId): bool
    {
        return GuardianAbsenceConfirmation::query()
            ->where('absence_id', $absenceId)
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->exists();
    }

    /**
     * @return array{0:string,1:AbsenceConfirmationToken}
     */
    private function createToken(Absence $absence, int $guardianId, bool $forceResend): array
    {
        if ($forceResend) {
            AbsenceConfirmationToken::query()
                ->where('absence_id', $absence->id)
                ->where('guardian_id', $guardianId)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        }

        $plainToken = Str::random(80);
        $expiresAt = $absence->resolveMedicalCertificateDeadline()->copy()->endOfDay();

        if ($expiresAt->lt(now()->addMinutes(15))) {
            $expiresAt = now()->addMinutes(15);
        }

        $token = AbsenceConfirmationToken::create([
            'absence_id' => $absence->id,
            'guardian_id' => $guardianId,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
        ]);

        return [$plainToken, $token];
    }

    private function findTokenByPlainValue(string $plainToken): ?AbsenceConfirmationToken
    {
        $hash = hash('sha256', trim($plainToken));

        return AbsenceConfirmationToken::query()
            ->where('token_hash', $hash)
            ->with(['absence.student', 'guardian'])
            ->orderByDesc('id')
            ->first();
    }

    private function buildSubject(Absence $absence): string
    {
        $studentName = trim((string) ($absence->student?->name ?? '').' '.(string) ($absence->student?->surname ?? ''));

        return $studentName !== ''
            ? 'Conferma assenza - '.$studentName
            : 'Conferma assenza studente';
    }

    private function buildNotificationBody(Absence $absence, ?Carbon $expiresAt, bool $forceResend): string
    {
        $lines = [
            $forceResend
                ? "Promemoria: e richiesta la firma del tutore per confermare l'assenza."
                : "E richiesta la firma del tutore per confermare l'assenza.",
            'Riferimento pratica: assenza #'.$absence->id.'.',
        ];

        if ($expiresAt) {
            $lines[] = 'Scadenza link firma: '.$expiresAt->format('d/m/Y H:i').'.';
        }

        $lines[] = 'Grazie per la collaborazione.';

        return implode(PHP_EOL, $lines);
    }

    private function sendInactiveGuardianInfoEmails(
        Absence $absence,
        ?User $actor = null,
        ?Request $request = null,
        ?Collection $signingGuardians = null
    ): void {
        $student = $absence->student;
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
        $periodLabel = $this->buildAbsencePeriodLabel($absence);
        $statusCode = Absence::normalizeStatus((string) $absence->status);
        $statusLabel = $statusCode === Absence::STATUS_ARBITRARY ? 'Arbitraria' : 'Segnalata';
        $title = $statusCode === Absence::STATUS_ARBITRARY
            ? 'Aggiornamento assenza (solo informativa)'
            : 'Notifica assenza (solo informativa)';
        $intro = $statusCode === Absence::STATUS_ARBITRARY
            ? 'Lo studente maggiorenne ha una assenza passata in stato Arbitraria. Questa email e solo informativa.'
            : 'Lo studente maggiorenne ha segnalato una nuova assenza. Questa email e solo informativa.';
        $closing = 'Non devi firmare e non devi rispondere a questa email.';
        $subject = 'Notifica assenza studente maggiorenne - '.$studentName;
        $body = implode(PHP_EOL, [
            $intro,
            'Codice assenza: A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT).'.',
            'Periodo: '.$periodLabel.'.',
            'Stato: '.$statusLabel.'.',
            $closing,
        ]);

        foreach ($informativeGuardians as $guardian) {
            $recipientEmail = strtolower(trim((string) $guardian->email));
            if ($recipientEmail === '') {
                continue;
            }

            if ($this->hasInactiveGuardianInfoAlreadySent($absence->id, $recipientEmail)) {
                continue;
            }

            try {
                Mail::to($recipientEmail)->send(new AdultStudentGuardianInfoMail(
                    $subject,
                    $title,
                    $intro,
                    [
                        'Studente: '.$studentName,
                        'Codice assenza: A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
                        'Periodo: '.$periodLabel,
                        'Stato: '.$statusLabel,
                    ],
                    $closing
                ));

                AbsenceEmailNotification::create([
                    'type' => 'inactive_guardian_info',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'absence_id' => $absence->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                OperationLog::record(
                    $actor,
                    'absence.inactive_guardian_info_email.sent',
                    'absence',
                    $absence->id,
                    [
                        'guardian_id' => $guardian->id,
                        'guardian_email' => $recipientEmail,
                    ],
                    'INFO',
                    $request
                );
            } catch (Throwable $exception) {
                AbsenceEmailNotification::create([
                    'type' => 'inactive_guardian_info',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'absence_id' => $absence->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $actor,
                    'absence.inactive_guardian_info_email.failed',
                    'absence',
                    $absence->id,
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

    private function hasInactiveGuardianInfoAlreadySent(int $absenceId, string $recipientEmail): bool
    {
        return AbsenceEmailNotification::query()
            ->where('absence_id', $absenceId)
            ->where('type', 'inactive_guardian_info')
            ->where('recipient_email', $recipientEmail)
            ->where('status', 'sent')
            ->exists();
    }

    private function buildAbsencePeriodLabel(Absence $absence): string
    {
        $start = Carbon::parse($absence->start_date);
        $end = Carbon::parse($absence->end_date ?? $absence->start_date);

        if ($start->isSameDay($end)) {
            return $start->format('d/m/Y');
        }

        return $start->format('d/m/Y').' - '.$end->format('d/m/Y');
    }
}
