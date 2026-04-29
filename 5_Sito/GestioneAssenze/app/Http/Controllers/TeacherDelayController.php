<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeacherExtendDelayDeadlineRequest;
use App\Http\Requests\TeacherOptionalCommentRequest;
use App\Http\Requests\TeacherRejectDelayRequest;
use App\Http\Requests\TeacherRequiredCommentRequest;
use App\Http\Requests\TeacherUpdateDelayRequest;
use App\Jobs\Mail\DelayRuleTriggeredMail;
use App\Models\Delay;
use App\Models\DelayEmailNotification;
use App\Models\GuardianDelayConfirmation;
use App\Models\DelaySetting;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\DelayGuardianSignatureService;
use App\Support\DelayRuleEvaluator;
use App\Support\SystemSettingsResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TeacherDelayController extends BaseController
{
    public function __construct()
    {
        $this->middleware('teacher');
    }

    public function approve(TeacherOptionalCommentRequest $request, Delay $delay)
    {

        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);
        $delaySetting = $this->resolveDelaySetting();
        $guardianSignatureRequired = (bool) $delaySetting->guardian_signature_required;
        $deadlineModeActive = Delay::deadlineModeActive($delaySetting);
        $statusCode = Delay::normalizeStatus($delay->status);

        if ($statusCode === Delay::STATUS_REPORTED) {
            // Segnalazione iniziale: il docente puo giustificare subito.
        } elseif ($statusCode === Delay::STATUS_REGISTERED && $deadlineModeActive) {
            if ($this->isRegisteredDelayDeadlineExpired($delay)) {
                return back()->withErrors([
                    'delay' => 'Ritardo scaduto in stato arbitrario: usa la proroga.',
                ]);
            }

            if ($guardianSignatureRequired && ! $this->hasGuardianSignature($delay)) {
                return back()->withErrors([
                    'delay' => 'Firma tutore non presente: usa approvazione senza firma.',
                ]);
            }
        } else {
            return back()->withErrors([
                'delay' => 'Puoi approvare solo ritardi segnalati o ritardi registrati in gestione.',
            ]);
        }

        $validated = $request->validated();
        $comment = $this->normalizeOptionalComment($validated['comment'] ?? null);

        $delay->update([
            'status' => Delay::STATUS_JUSTIFIED,
            'count_in_semester' => false,
            'teacher_comment' => $comment,
            'validated_at' => now(),
            'validated_by' => $teacher->id,
        ]);

        OperationLog::record(
            $teacher,
            'delay.approved',
            'delay',
            $delay->id,
            [
                'comment' => $comment,
                'guardian_signature_required' => $guardianSignatureRequired,
                'guardian_signature_present' => $this->hasGuardianSignature($delay),
                'approval_mode' => $statusCode === Delay::STATUS_REPORTED
                    ? 'reported_direct_justify'
                    : 'registered_with_signature',
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Ritardo approvato e giustificato.');
    }

    public function approveWithoutGuardian(TeacherRequiredCommentRequest $request, Delay $delay)
    {

        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);
        $delaySetting = $this->resolveDelaySetting();
        $deadlineModeActive = Delay::deadlineModeActive($delaySetting);

        if (Delay::normalizeStatus($delay->status) !== Delay::STATUS_REGISTERED || ! $deadlineModeActive) {
            return back()->withErrors([
                'delay' => 'Puoi approvare senza firma solo ritardi registrati in gestione scadenza.',
            ]);
        }

        if ($this->isRegisteredDelayDeadlineExpired($delay)) {
            return back()->withErrors([
                'delay' => 'Ritardo scaduto in stato arbitrario: usa la proroga.',
            ]);
        }

        if ($this->hasGuardianSignature($delay)) {
            return back()->withErrors([
                'delay' => 'La firma tutore e gia presente: usa approvazione standard.',
            ]);
        }

        $validated = $request->validated();
        $comment = trim((string) $validated['comment']);

        $delay->update([
            'status' => Delay::STATUS_JUSTIFIED,
            'count_in_semester' => false,
            'teacher_comment' => $comment,
            'validated_at' => now(),
            'validated_by' => $teacher->id,
        ]);

        OperationLog::record(
            $teacher,
            'delay.approved_without_guardian',
            'delay',
            $delay->id,
            [
                'comment' => $comment,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Ritardo approvato senza firma tutore.');
    }

    public function reject(
        TeacherRejectDelayRequest $request,
        Delay $delay,
        DelayGuardianSignatureService $delayGuardianSignatureService
    ) {

        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);
        $delaySetting = $this->resolveDelaySetting();
        $guardianSignatureRequired = (bool) $delaySetting->guardian_signature_required;
        $deadlineModeActive = Delay::deadlineModeActive($delaySetting);
        $statusCode = Delay::normalizeStatus($delay->status);

        if ($statusCode === Delay::STATUS_REPORTED) {
            $validated = $request->validated();

            $comment = trim((string) $validated['comment']);
            $registeredDeadline = $deadlineModeActive
                ? Delay::calculateRegisteredDeadline(Carbon::today(), $delaySetting)
                : null;

            $delay->update([
                'status' => Delay::STATUS_REGISTERED,
                'count_in_semester' => true,
                'justification_deadline' => $registeredDeadline?->toDateString(),
                'teacher_comment' => $comment,
                'validated_at' => now(),
                'validated_by' => $teacher->id,
                'auto_arbitrary_at' => null,
            ]);

            OperationLog::record(
                $teacher,
                'delay.rejected',
                'delay',
                $delay->id,
                [
                    'comment' => $comment,
                    'status_before' => $statusCode,
                    'status_after' => Delay::STATUS_REGISTERED,
                    'flow_step' => 'teacher_register',
                ],
                'INFO',
                $request
            );

            $delayEmailSummary = ['guardians' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
            if ($guardianSignatureRequired) {
                $delayEmailSummary = $delayGuardianSignatureService->sendConfirmationEmails(
                    $delay,
                    $teacher,
                    false,
                    $request
                );
            }

            $countInSemester = Delay::countRegisteredInSemester(
                (int) $delay->student_id,
                Carbon::today()
            );
            $ruleEvaluation = DelayRuleEvaluator::evaluateForCount($countInSemester);
            $ruleSummary = ['sent' => 0, 'failed' => 0];

            if ($ruleEvaluation['actions']->isNotEmpty()) {
                $ruleSummary = $this->applyConfiguredRuleActions(
                    $delay,
                    $teacher,
                    $request,
                    $ruleEvaluation,
                    $countInSemester
                );
            }

            $successMessage = 'Ritardo registrato.';
            if ($deadlineModeActive) {
                $successMessage .= ' Gestione scadenza attivata.';
            }

            $ruleInfoMessage = implode(' ', $ruleEvaluation['info_messages']);
            if ($ruleInfoMessage !== '') {
                $successMessage .= ' '.$ruleInfoMessage;
            }

            if ($guardianSignatureRequired && $delayEmailSummary['sent'] > 0) {
                $successMessage .= ' Email firma ritardo inviata a '.$delayEmailSummary['sent'].' tutore/i.';
            }

            if ($ruleSummary['sent'] > 0) {
                $successMessage .= ' Notifiche regola inviate a '.$ruleSummary['sent'].' destinatari.';
            }

            return back()->with('success', $successMessage);
        }

        if ($statusCode !== Delay::STATUS_REGISTERED || ! $deadlineModeActive) {
            return back()->withErrors([
                'delay' => 'Puoi rifiutare solo ritardi registrati in gestione scadenza.',
            ]);
        }

        if ($this->isRegisteredDelayDeadlineExpired($delay)) {
            return back()->withErrors([
                'delay' => 'Ritardo gia arbitrario: usa la proroga.',
            ]);
        }

        $validated = $request->validated();
        $comment = trim((string) $validated['comment']);
        $forcedArbitraryDeadline = Carbon::today()->subDay()->toDateString();

        $delay->update([
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'justification_deadline' => $forcedArbitraryDeadline,
            'teacher_comment' => $comment,
            'validated_at' => now(),
            'validated_by' => $teacher->id,
            'auto_arbitrary_at' => now(),
        ]);

        OperationLog::record(
            $teacher,
            'delay.rejected',
            'delay',
            $delay->id,
            [
                'comment' => $comment,
                'status_before' => $statusCode,
                'status_after' => Delay::STATUS_REGISTERED,
                'flow_step' => 'registered_rejected_to_arbitrary',
                'forced_deadline' => $forcedArbitraryDeadline,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Ritardo rifiutato. Pratica impostata in stato arbitrario.');
    }

    public function extendDeadline(TeacherExtendDelayDeadlineRequest $request, Delay $delay)
    {

        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);
        $delaySetting = $this->resolveDelaySetting();

        if (! Delay::deadlineModeActive($delaySetting)) {
            return back()->withErrors([
                'delay' => 'La proroga e disponibile solo con gestione scadenza ritardi attiva.',
            ]);
        }

        if (Delay::normalizeStatus($delay->status) !== Delay::STATUS_REGISTERED) {
            return back()->withErrors([
                'delay' => 'Puoi concedere proroga solo su ritardi registrati.',
            ]);
        }

        if (! $this->isRegisteredDelayDeadlineExpired($delay)) {
            return back()->withErrors([
                'delay' => 'La proroga e disponibile solo dopo scadenza (stato arbitrario).',
            ]);
        }

        $validated = $request->validated();

        $storedDeadline = $delay->justification_deadline
            ? Carbon::parse($delay->justification_deadline)->startOfDay()
            : Carbon::today()->subDay();
        $baseDeadline = $storedDeadline->lt(Carbon::today())
            ? Carbon::today()->startOfDay()
            : $storedDeadline;
        $newDeadline = Delay::addBusinessDays($baseDeadline, (int) $validated['extension_days']);
        $comment = trim((string) $validated['comment']);

        $delay->update([
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'justification_deadline' => $newDeadline->toDateString(),
            'teacher_comment' => $comment,
            'validated_at' => now(),
            'validated_by' => $teacher->id,
            'auto_arbitrary_at' => null,
        ]);

        OperationLog::record(
            $teacher,
            'delay.deadline.extended',
            'delay',
            $delay->id,
            [
                'extension_days' => (int) $validated['extension_days'],
                'previous_deadline' => $storedDeadline->toDateString(),
                'new_deadline' => $newDeadline->toDateString(),
                'comment' => $comment,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Proroga concessa fino al '.$newDeadline->format('d/m/Y').'.');
    }

    public function resendGuardianConfirmationEmail(
        Request $request,
        Delay $delay,
        DelayGuardianSignatureService $delayGuardianSignatureService
    ) {
        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);
        $delaySetting = $this->resolveDelaySetting();

        if (! (bool) $delaySetting->guardian_signature_required) {
            return back()->withErrors([
                'delay' => 'La firma tutore sui ritardi non e attiva nella configurazione.',
            ]);
        }

        if (Delay::normalizeStatus($delay->status) !== Delay::STATUS_REGISTERED
            || ! Delay::deadlineModeActive($delaySetting)
        ) {
            return back()->withErrors([
                'delay' => 'Puoi reinviare la firma solo su ritardi registrati in gestione scadenza.',
            ]);
        }

        if ($this->isRegisteredDelayDeadlineExpired($delay)) {
            return back()->withErrors([
                'delay' => 'Ritardo scaduto: concedi prima una proroga.',
            ]);
        }

        if ($this->hasGuardianSignature($delay)) {
            return back()->withErrors([
                'delay' => 'Firma tutore gia presente. Reinvio non necessario.',
            ]);
        }

        $summary = $delayGuardianSignatureService->sendConfirmationEmails(
            $delay,
            $teacher,
            true,
            $request
        );

        if ($summary['guardians'] === 0) {
            return back()->withErrors([
                'delay' => 'Nessun tutore con email associato allo studente.',
            ]);
        }

        if ($summary['sent'] === 0) {
            return back()->withErrors([
                'delay' => 'Nessuna email inviata. Verifica tutori o stato della firma.',
            ]);
        }

        return back()->with(
            'success',
            'Email di conferma reinviata a '.$summary['sent'].' tutore/i.'
        );
    }

    public function destroy(Request $request, Delay $delay)
    {

        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);

        $delayId = (int) $delay->id;
        $delayCode = 'R-'.str_pad((string) $delayId, 4, '0', STR_PAD_LEFT);
        $guardianSignaturePaths = $delay->guardianConfirmations
            ->pluck('signature_path')
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->unique()
            ->values()
            ->all();
        DB::transaction(function () use ($delay): void {
            $delay->delete();
        });

        $disk = Storage::disk(config('filesystems.default', 'local'));
        foreach ($guardianSignaturePaths as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        OperationLog::record(
            $teacher,
            'delay.deleted',
            'delay',
            $delayId,
            [
                'delay_code' => $delayCode,
                'deleted_guardian_signature_files' => $guardianSignaturePaths,
            ],
            'WARNING',
            $request
        );

        return redirect()
            ->route('dashboard')
            ->with('success', 'Ritardo '.$delayCode.' eliminato definitivamente.');
    }

    public function showGuardianSignature(Request $request, Delay $delay)
    {
        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);

        $confirmation = $this->resolveLatestGuardianSignature($delay);
        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! $disk->exists($confirmation->signature_path)) {
            abort(404, 'File firma tutore non trovato.');
        }

        OperationLog::record(
            $teacher,
            'delay.guardian_signature.viewed',
            'guardian_delay_confirmation',
            $confirmation->id,
            [
                'delay_id' => $delay->id,
                'guardian_id' => $confirmation->guardian_id,
                'signature_path' => $confirmation->signature_path,
            ],
            'INFO',
            $request
        );

        return $disk->response($confirmation->signature_path, basename($confirmation->signature_path));
    }

    public function update(
        TeacherUpdateDelayRequest $request,
        Delay $delay
    ) {

        $teacher = $request->user();
        $delay = $this->resolveTeacherDelay($teacher, $delay->id);
        $statusCode = Delay::normalizeStatus($delay->status);

        if (! in_array(
            $statusCode,
            [Delay::STATUS_REPORTED, Delay::STATUS_JUSTIFIED, Delay::STATUS_REGISTERED],
            true
        )) {
            return back()->withErrors([
                'delay' => 'Puoi modificare solo ritardi segnalati, giustificati o registrati.',
            ]);
        }

        $validated = $request->validated();

        $delayDate = Carbon::parse((string) $validated['delay_date'])->startOfDay();
        $delayMinutes = (int) $validated['delay_minutes'];
        $motivation = trim((string) $validated['motivation']);
        $targetStatus = Delay::normalizeStatus((string) ($validated['status'] ?? $delay->status));
        $countInSemester = $targetStatus === Delay::STATUS_REGISTERED;
        $delaySetting = $this->resolveDelaySetting();
        $guardianSignatureRequired = (bool) $delaySetting->guardian_signature_required;
        $deadlineModeActive = Delay::deadlineModeActive($delaySetting);
        $justificationDeadline = $deadlineModeActive && $targetStatus === Delay::STATUS_REGISTERED
            ? Delay::calculateRegisteredDeadline(Carbon::today(), $delaySetting)
            : (($guardianSignatureRequired || $deadlineModeActive)
                ? null
                : Delay::calculateJustificationDeadline($delayDate, $delaySetting));

        $before = [
            'delay_datetime' => $delay->delay_datetime?->toDateString(),
            'minutes' => (int) $delay->minutes,
            'reason' => (string) $delay->notes,
            'justification_deadline' => $delay->justification_deadline?->toDateString(),
            'status' => Delay::normalizeStatus($delay->status),
            'count_in_semester' => (bool) $delay->count_in_semester,
        ];

        $delay = DB::transaction(function () use (
            $delay,
            $delayDate,
            $delayMinutes,
            $motivation,
            $justificationDeadline,
            $targetStatus,
            $countInSemester
        ) {
            $delay->delay_datetime = $delayDate;
            $delay->minutes = $delayMinutes;
            $delay->notes = $motivation;
            $delay->justification_deadline = $justificationDeadline?->toDateString();
            $delay->status = $targetStatus;
            $delay->count_in_semester = $countInSemester;
            $delay->auto_arbitrary_at = null;

            $delay->save();

            return $delay->fresh();
        });

        OperationLog::record(
            $teacher,
            'delay.updated',
            'delay',
            $delay->id,
            [
                'before' => $before,
                'after' => [
                    'delay_datetime' => $delay->delay_datetime?->toDateString(),
                    'minutes' => (int) $delay->minutes,
                    'reason' => (string) $delay->notes,
                    'justification_deadline' => $delay->justification_deadline?->toDateString(),
                    'status' => Delay::normalizeStatus($delay->status),
                    'count_in_semester' => (bool) $delay->count_in_semester,
                ],
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Ritardo modificato.');
    }

    private function resolveTeacherDelay(User $teacher, int $delayId): Delay
    {
        return Delay::query()
            ->whereKey($delayId)
            ->whereIn('student_id', function ($subQuery) use ($teacher) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $teacher->id);
            })
            ->with(['student.guardians', 'guardianConfirmations.guardian'])
            ->firstOrFail();
    }

    private function resolveDelaySetting(): DelaySetting
    {
        return SystemSettingsResolver::delaySetting();
    }

    private function hasGuardianSignature(Delay $delay): bool
    {
        return $delay->guardianConfirmations->contains(function ($confirmation) {
            $status = strtolower(trim((string) $confirmation->status));

            return in_array($status, ['confirmed', 'approved', 'signed'], true)
                || ! empty($confirmation->confirmed_at)
                || ! empty($confirmation->signed_at);
        });
    }

    private function resolveLatestGuardianSignature(Delay $delay): GuardianDelayConfirmation
    {
        $confirmation = $delay->guardianConfirmations()
            ->whereNotNull('signature_path')
            ->where(function ($query) {
                $query
                    ->whereIn('status', ['confirmed', 'approved', 'signed'])
                    ->orWhereNotNull('confirmed_at')
                    ->orWhereNotNull('signed_at');
            })
            ->orderByRaw('COALESCE(confirmed_at, signed_at) asc')
            ->orderBy('id')
            ->first();

        if (! $confirmation) {
            abort(404, 'Nessuna firma tutore disponibile.');
        }

        return $confirmation;
    }

    private function isRegisteredDelayDeadlineExpired(Delay $delay): bool
    {
        if (Delay::normalizeStatus($delay->status) !== Delay::STATUS_REGISTERED) {
            return false;
        }

        if (! $delay->justification_deadline) {
            return false;
        }

        return Carbon::parse($delay->justification_deadline)
            ->startOfDay()
            ->lt(Carbon::today());
    }

    /**
     * @return array{sent:int,failed:int}
     */
    private function applyConfiguredRuleActions(
        Delay $delay,
        User $teacher,
        Request $request,
        array $ruleEvaluation,
        int $countInSemester
    ): array {
        $delay->loadMissing(['student.guardians']);

        $student = $delay->student;
        $studentName = trim((string) ($student?->name ?? '').' '.(string) ($student?->surname ?? ''));
        $studentName = $studentName !== '' ? $studentName : 'Studente';

        $actions = collect($ruleEvaluation['actions'] ?? [])->values();
        $primaryRule = $ruleEvaluation['primary_rule'] ?? null;
        $applicableRules = collect($ruleEvaluation['applicable_rules'] ?? []);

        if ($actions->isEmpty()) {
            return ['sent' => 0, 'failed' => 0];
        }

        $guardianEmails = collect($student?->guardians ?? [])
            ->pluck('email')
            ->filter(fn ($email) => filled($email))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();
        $teacherEmails = $this->resolveTeacherEmails((int) $delay->student_id);
        $studentEmail = strtolower(trim((string) ($student?->email ?? '')));

        $recipientLines = [];
        $addRecipientLine = function (string $email, string $line) use (&$recipientLines): void {
            $normalizedEmail = strtolower(trim($email));
            if ($normalizedEmail === '') {
                return;
            }

            if (! array_key_exists($normalizedEmail, $recipientLines)) {
                $recipientLines[$normalizedEmail] = [];
            }

            if (! in_array($line, $recipientLines[$normalizedEmail], true)) {
                $recipientLines[$normalizedEmail][] = $line;
            }
        };

        foreach ($actions as $action) {
            $type = $action['type'];
            $detail = $action['detail'];

            if ($type === 'notify_student') {
                $addRecipientLine(
                    $studentEmail,
                    'Notifica prevista per l allievo dalla regola ritardi.'
                );

                continue;
            }

            if ($type === 'notify_guardian') {
                foreach ($guardianEmails as $guardianEmail) {
                    $addRecipientLine(
                        $guardianEmail,
                        'Notifica prevista per il tutore dalla regola ritardi.'
                    );
                }

                continue;
            }

            if ($type === 'notify_teacher') {
                foreach ($teacherEmails as $teacherEmail) {
                    $addRecipientLine(
                        $teacherEmail,
                        'Notifica prevista per il docente di classe dalla regola ritardi.'
                    );
                }

                continue;
            }

            if ($type === 'extra_activity_notice') {
                $line = 'Segnalazione: prevista ora extra secondo la regola ritardi.';
                $addRecipientLine($studentEmail, $line);
                foreach ($guardianEmails as $guardianEmail) {
                    $addRecipientLine($guardianEmail, $line);
                }
                foreach ($teacherEmails as $teacherEmail) {
                    $addRecipientLine($teacherEmail, $line);
                }

                continue;
            }

            if ($type === 'conduct_penalty') {
                $line = $detail !== ''
                    ? 'Segnalazione condotta: '.$detail
                    : 'Segnalazione condotta prevista dalla regola ritardi.';
                $addRecipientLine($studentEmail, $line);
                foreach ($guardianEmails as $guardianEmail) {
                    $addRecipientLine($guardianEmail, $line);
                }
                foreach ($teacherEmails as $teacherEmail) {
                    $addRecipientLine($teacherEmail, $line);
                }
            }
        }

        if (empty($recipientLines)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $summary = ['sent' => 0, 'failed' => 0];
        $subject = 'Regole ritardi applicate - '.$studentName;

        foreach ($recipientLines as $recipientEmail => $lines) {
            $body = implode("\n", [
                'Ritardo registrato: R-'.str_pad((string) $delay->id, 4, '0', STR_PAD_LEFT),
                'Studente: '.$studentName,
                'Conteggio ritardi: '.$countInSemester,
                'Azioni applicate:',
                ...array_map(fn (string $line) => '- '.$line, $lines),
            ]);

            if ($this->hasSameDelayRuleNotificationAlreadySent(
                (int) $delay->id,
                $recipientEmail,
                $subject,
                $body
            )) {
                continue;
            }

            try {
                Mail::to($recipientEmail)->send(new DelayRuleTriggeredMail(
                    $delay,
                    $studentName,
                    $countInSemester,
                    $lines
                ));

                DelayEmailNotification::create([
                    'type' => 'delay_rule_notification',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'delay_id' => $delay->id,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                $summary['sent']++;
            } catch (Throwable $exception) {
                DelayEmailNotification::create([
                    'type' => 'delay_rule_notification',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'delay_id' => $delay->id,
                    'status' => 'failed',
                ]);

                OperationLog::record(
                    $teacher,
                    'delay.rule_notification.failed',
                    'delay',
                    $delay->id,
                    [
                        'recipient_email' => $recipientEmail,
                        'delay_rule_id' => $primaryRule?->id,
                        'delay_rule_ids' => $applicableRules->pluck('id')->values()->all(),
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR',
                    $request
                );

                report($exception);
                $summary['failed']++;
            }
        }

        OperationLog::record(
            $teacher,
            'delay.rule.applied',
            'delay',
            $delay->id,
            [
                'delay_rule_id' => $primaryRule?->id,
                'delay_rule_ids' => $applicableRules->pluck('id')->values()->all(),
                'delay_count_in_semester' => $countInSemester,
                'delay_rule_actions' => $actions->all(),
                'delay_rule_info_messages' => $ruleEvaluation['info_messages'] ?? [],
                'notifications_sent' => $summary['sent'],
                'notifications_failed' => $summary['failed'],
            ],
            'INFO',
            $request
        );

        return $summary;
    }

    /**
     * @return array<int,string>
     */
    private function resolveTeacherEmails(int $studentId): array
    {
        return User::query()
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->join('class_user', 'class_user.class_id', '=', 'class_teacher.class_id')
            ->where('class_user.user_id', $studentId)
            ->whereNotNull('users.email')
            ->distinct()
            ->pluck('users.email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => $email !== '')
            ->values()
            ->all();
    }

    private function normalizeOptionalComment(mixed $value): ?string
    {
        $comment = trim((string) ($value ?? ''));

        return $comment !== '' ? $comment : null;
    }

    private function hasSameDelayRuleNotificationAlreadySent(
        int $delayId,
        string $recipientEmail,
        string $subject,
        string $body
    ): bool {
        $normalizedEmail = strtolower(trim($recipientEmail));

        if ($normalizedEmail === '') {
            return true;
        }

        return DelayEmailNotification::query()
            ->where('delay_id', $delayId)
            ->where('type', 'delay_rule_notification')
            ->where('recipient_email', $normalizedEmail)
            ->where('subject', $subject)
            ->where('body', $body)
            ->where('status', 'sent')
            ->exists();
    }
}
