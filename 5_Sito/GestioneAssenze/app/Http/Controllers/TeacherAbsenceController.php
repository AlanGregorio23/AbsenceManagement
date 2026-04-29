<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeacherApproveWithoutGuardianRequest;
use App\Http\Requests\TeacherExtendAbsenceDeadlineRequest;
use App\Http\Requests\TeacherOptionalCommentRequest;
use App\Http\Requests\TeacherRequiredCommentRequest;
use App\Http\Requests\TeacherUpdateAbsenceRequest;
use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\GuardianAbsenceConfirmation;
use App\Models\MedicalCertificate;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\AbsenceGuardianSignatureService;
use App\Support\AnnualHoursLimitLabels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeacherAbsenceController extends BaseController
{
    public function __construct()
    {
        $this->middleware('teacher')->except(['destroy']);
    }

    public function approve(TeacherOptionalCommentRequest $request, Absence $absence)
    {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);
        $statusCode = Absence::normalizeStatus($absence->status);

        if ($statusCode !== Absence::STATUS_REPORTED) {
            return back()->withErrors([
                'absence' => 'Puoi approvare solo assenze segnalate.',
            ]);
        }

        if (! $this->hasGuardianSignature($absence)) {
            return back()->withErrors([
                'absence' => 'Firma tutore non presente: usa approvazione senza firma.',
            ]);
        }

        $validated = $request->validated();

        $hasValidCertificate = $absence->medicalCertificates->contains(
            fn (MedicalCertificate $certificate) => $certificate->valid
        );
        $counts40Hours = $absence->resolveCounts40Hours(null, Absence::STATUS_JUSTIFIED);
        $counts40Comment = null;

        if (! $counts40Hours) {
            $counts40Comment = $hasValidCertificate
                ? AnnualHoursLimitLabels::certificateAcceptedComment()
                : AnnualHoursLimitLabels::ruleReasonComment();
        }

        $absence->update([
            'status' => Absence::STATUS_JUSTIFIED,
            'approved_without_guardian' => false,
            'teacher_comment' => isset($validated['comment'])
                ? trim((string) $validated['comment'])
                : null,
            'counts_40_hours' => $counts40Hours,
            'counts_40_hours_comment' => $counts40Comment,
            'hours_decided_at' => now(),
            'hours_decided_by' => $teacher->id,
        ]);
        $absence->refresh();
        $this->syncMedicalCertificateRequirement($absence);

        OperationLog::record(
            $teacher,
            'absence.approved',
            'absence',
            $absence->id,
            [
                'with_guardian_signature' => true,
                'comment' => isset($validated['comment'])
                    ? trim((string) $validated['comment'])
                    : null,
                'counts_40_hours' => $counts40Hours,
                'counts_40_hours_comment' => $counts40Comment,
                'certificate_validated' => $hasValidCertificate,
                'status_before' => $statusCode,
                'status_after' => Absence::STATUS_JUSTIFIED,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Assenza approvata.');
    }

    public function approveWithoutGuardian(
        TeacherApproveWithoutGuardianRequest $request,
        Absence $absence
    ) {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);

        if (Absence::normalizeStatus($absence->status) !== Absence::STATUS_REPORTED) {
            return back()->withErrors([
                'absence' => 'Puoi approvare senza firma solo assenze ancora aperte.',
            ]);
        }

        $validated = $request->validated();
        $hasValidCertificate = $absence->medicalCertificates()
            ->where('valid', true)
            ->exists();

        $counts40Hours = (bool) $validated['counts_40_hours'];
        $counts40Comment = isset($validated['counts_40_hours_comment'])
            ? trim((string) $validated['counts_40_hours_comment'])
            : null;

        if ($hasValidCertificate) {
            $counts40Hours = false;
            $counts40Comment = AnnualHoursLimitLabels::certificateAcceptedComment();
        } elseif ($counts40Hours) {
            $counts40Comment = null;
        }

        $absence->update([
            'status' => Absence::STATUS_JUSTIFIED,
            'approved_without_guardian' => true,
            'teacher_comment' => trim((string) $validated['comment']),
            'counts_40_hours' => $counts40Hours,
            'counts_40_hours_comment' => $counts40Comment,
            'hours_decided_at' => now(),
            'hours_decided_by' => $teacher->id,
        ]);
        $absence->refresh();
        $this->syncMedicalCertificateRequirement($absence);

        OperationLog::record(
            $teacher,
            'absence.approved_without_guardian',
            'absence',
            $absence->id,
            [
                'comment' => trim((string) $validated['comment']),
                'counts_40_hours' => $counts40Hours,
                'counts_40_hours_comment' => $counts40Comment,
                'certificate_validated' => $hasValidCertificate,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Assenza approvata senza firma tutore.');
    }

    public function reject(TeacherRequiredCommentRequest $request, Absence $absence)
    {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);
        $statusCode = Absence::normalizeStatus($absence->status);

        if ($statusCode !== Absence::STATUS_REPORTED) {
            return back()->withErrors([
                'absence' => 'Puoi rifiutare solo assenze segnalate.',
            ]);
        }

        $validated = $request->validated();

        $absence->update([
            'status' => Absence::STATUS_ARBITRARY,
            'approved_without_guardian' => false,
            'teacher_comment' => trim((string) $validated['comment']),
            'counts_40_hours' => true,
            'counts_40_hours_comment' => 'Assenza rifiutata dal docente.',
            'hours_decided_at' => now(),
            'hours_decided_by' => $teacher->id,
        ]);
        $absence->refresh();
        $this->syncMedicalCertificateRequirement($absence);

        OperationLog::record(
            $teacher,
            'absence.rejected',
            'absence',
            $absence->id,
            [
                'comment' => trim((string) $validated['comment']),
                'status_before' => $statusCode,
                'status_after' => Absence::STATUS_ARBITRARY,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Assenza rifiutata.');
    }

    public function destroy(Request $request, Absence $absence)
    {

        $actor = $request->user();
        if (! $actor) {
            abort(403);
        }

        $absence = $this->resolveAbsenceForDeletion($actor, $absence->id);

        $absenceId = (int) $absence->id;
        $absenceCode = 'A-'.str_pad((string) $absenceId, 4, '0', STR_PAD_LEFT);
        $certificatePaths = $absence->medicalCertificates
            ->pluck('file_path')
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->unique()
            ->values()
            ->all();
        $guardianSignaturePaths = $absence->guardianConfirmations
            ->pluck('signature_path')
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($absence): void {
            $absence->delete();
        });

        $disk = Storage::disk(config('filesystems.default', 'local'));
        foreach (array_merge($certificatePaths, $guardianSignaturePaths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        OperationLog::record(
            $actor,
            'absence.deleted',
            'absence',
            $absenceId,
            [
                'absence_code' => $absenceCode,
                'deleted_certificate_files' => $certificatePaths,
                'deleted_guardian_signature_files' => $guardianSignaturePaths,
            ],
            'WARNING',
            $request
        );

        return redirect()
            ->route('dashboard')
            ->with('success', 'Assenza '.$absenceCode.' eliminata definitivamente.');
    }

    public function update(TeacherUpdateAbsenceRequest $request, Absence $absence)
    {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);
        $statusCode = Absence::normalizeStatus($absence->status);

        if (! in_array(
            $statusCode,
            [Absence::STATUS_REPORTED, Absence::STATUS_ARBITRARY, Absence::STATUS_JUSTIFIED],
            true
        )) {
            return back()->withErrors([
                'absence' => 'Puoi modificare solo assenze segnalate, arbitrarie o giustificate.',
            ]);
        }

        $validated = $request->validated();
        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'] ?? $validated['start_date'])->startOfDay();
        $hours = max((int) ($validated['hours'] ?? 1), 1);
        $newReason = trim((string) $validated['motivation']);
        $targetStatus = Absence::normalizeStatus((string) ($validated['status'] ?? $absence->status));
        $previousPayload = [
            'start_date' => $absence->start_date?->toDateString(),
            'end_date' => $absence->end_date?->toDateString(),
            'assigned_hours' => (int) $absence->assigned_hours,
            'reason' => (string) ($absence->reason ?? ''),
            'counts_40_hours' => (bool) $absence->counts_40_hours,
            'counts_40_hours_comment' => (string) ($absence->counts_40_hours_comment ?? ''),
            'medical_certificate_required' => (bool) $absence->medical_certificate_required,
            'medical_certificate_deadline' => $absence->medical_certificate_deadline?->toDateString(),
            'status' => Absence::normalizeStatus($absence->status),
        ];

        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $newDeadline = Absence::calculateMedicalCertificateDeadline($endDate, $absenceSetting);
        $absence->start_date = $startDate->toDateString();
        $absence->end_date = $endDate->toDateString();
        $absence->assigned_hours = $hours;
        $absence->reason = $newReason;
        $absence->status = $targetStatus;
        $medicalCertificateRequired = $absence->resolveMedicalCertificateRequired($absenceSetting);
        $currentDeadline = $absence->medical_certificate_deadline
            ? Carbon::parse($absence->medical_certificate_deadline)->startOfDay()
            : null;
        $effectiveDeadline = $absence->hasManualDeadlineExtension()
            && $currentDeadline
            && $currentDeadline->gt($newDeadline)
            ? $currentDeadline
            : $newDeadline;
        $hasValidCertificate = $absence->medicalCertificates()
            ->where('valid', true)
            ->exists();
        $counts40Hours = (bool) $validated['counts_40_hours'];
        $counts40Comment = trim((string) ($validated['counts_40_hours_comment'] ?? ''));
        if ($hasValidCertificate) {
            $counts40Hours = false;
            $counts40Comment = AnnualHoursLimitLabels::certificateAcceptedComment($absenceSetting);
        } elseif ($targetStatus === Absence::STATUS_ARBITRARY) {
            $counts40Hours = true;
            $counts40Comment = 'Assenza impostata arbitraria da rettifica docente.';
        } elseif ($counts40Hours) {
            $counts40Comment = null;
        } elseif ($counts40Comment === '') {
            $counts40Comment = AnnualHoursLimitLabels::teacherDecisionComment($absenceSetting);
        }

        $hoursDecidedAt = now();
        $hoursDecidedBy = $teacher->id;
        if ($targetStatus === Absence::STATUS_REPORTED) {
            $counts40Hours = false;
            $counts40Comment = AnnualHoursLimitLabels::pendingTeacherValidationComment($absenceSetting);
            $hoursDecidedAt = null;
            $hoursDecidedBy = null;
        }

        $absence->update([
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'assigned_hours' => $hours,
            'reason' => $newReason,
            'status' => $targetStatus,
            'counts_40_hours' => $counts40Hours,
            'counts_40_hours_comment' => $counts40Comment,
            'medical_certificate_required' => $medicalCertificateRequired,
            'medical_certificate_deadline' => $effectiveDeadline->toDateString(),
            'teacher_comment' => trim((string) $validated['comment']),
            'approved_without_guardian' => false,
            'auto_arbitrary_at' => null,
            'hours_decided_at' => $hoursDecidedAt,
            'hours_decided_by' => $hoursDecidedBy,
        ]);
        $absence->refresh();
        $medicalCertificateRequired = $absence->syncMedicalCertificateRequired($absenceSetting);
        $absence->refresh();

        OperationLog::record(
            $teacher,
            'absence.updated',
            'absence',
            $absence->id,
            [
                'before' => $previousPayload,
                'after' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'assigned_hours' => $hours,
                    'reason' => $newReason,
                    'counts_40_hours' => $counts40Hours,
                    'counts_40_hours_comment' => $counts40Comment,
                    'medical_certificate_required' => $medicalCertificateRequired,
                    'medical_certificate_deadline' => $effectiveDeadline->toDateString(),
                    'status' => Absence::normalizeStatus($absence->status),
                ],
                'comment' => trim((string) $validated['comment']),
                'certificate_validated' => $hasValidCertificate,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Assenza aggiornata.');
    }

    public function extendDeadline(TeacherExtendAbsenceDeadlineRequest $request, Absence $absence)
    {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);

        if (Absence::normalizeStatus($absence->status) !== Absence::STATUS_ARBITRARY) {
            return back()->withErrors([
                'absence' => 'Puoi concedere proroga solo su assenze arbitrarie.',
            ]);
        }

        $validated = $request->validated();
        $baseDeadline = $absence->medical_certificate_deadline
            ? Carbon::parse($absence->medical_certificate_deadline)->startOfDay()
            : Carbon::parse($absence->end_date)->startOfDay();
        if ($baseDeadline->lt(Carbon::today())) {
            $baseDeadline = Carbon::today();
        }

        $newDeadline = Absence::addBusinessDays($baseDeadline, (int) $validated['extension_days']);

        $absence->update([
            'status' => Absence::STATUS_REPORTED,
            'medical_certificate_deadline' => $newDeadline->toDateString(),
            'deadline_extension_comment' => trim((string) $validated['comment']),
            'deadline_extended_at' => now(),
            'deadline_extended_by' => $teacher->id,
            'auto_arbitrary_at' => null,
        ]);
        $absence->refresh();
        $this->syncMedicalCertificateRequirement($absence);

        OperationLog::record(
            $teacher,
            'absence.deadline.extended',
            'absence',
            $absence->id,
            [
                'extension_days' => (int) $validated['extension_days'],
                'previous_deadline' => $baseDeadline->toDateString(),
                'new_deadline' => $newDeadline->toDateString(),
                'comment' => trim((string) $validated['comment']),
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Proroga concessa fino al '.$newDeadline->format('d/m/Y').'. Assenza riaperta.');
    }

    public function resendGuardianConfirmationEmail(
        Request $request,
        Absence $absence,
        AbsenceGuardianSignatureService $guardianSignatureService
    ) {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);
        $statusCode = Absence::normalizeStatus($absence->status);

        if (! in_array($statusCode, [Absence::STATUS_REPORTED, Absence::STATUS_ARBITRARY], true)) {
            return back()->withErrors([
                'absence' => 'Reinvio non consentito per questa assenza.',
            ]);
        }

        $summary = $guardianSignatureService->sendConfirmationEmails(
            $absence,
            $teacher,
            true,
            $request
        );

        if ($summary['guardians'] === 0) {
            return back()->withErrors([
                'absence' => 'Nessun tutore con email associato allo studente.',
            ]);
        }

        if ($summary['sent'] === 0 && $summary['failed'] > 0) {
            return back()->withErrors([
                'absence' => 'Invio email non riuscito. Riprova.',
            ]);
        }

        return back()->with(
            'success',
            'Email di conferma reinviata a '.$summary['sent'].' tutore/i.'
        );
    }

    public function acceptMedicalCertificate(Request $request, Absence $absence)
    {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);
        if (! is_null($absence->derived_from_leave_id)) {
            return back()->withErrors([
                'absence' => 'Le assenze derivate da congedo non prevedono accetta/rifiuta certificato.',
            ]);
        }
        $statusCode = Absence::normalizeStatus($absence->status);
        if (! in_array($statusCode, [Absence::STATUS_REPORTED, Absence::STATUS_JUSTIFIED], true)) {
            return back()->withErrors([
                'absence' => 'Puoi validare il certificato solo su assenze aperte o giustificate entro il termine.',
            ]);
        }
        $effectiveDeadline = $absence->syncMedicalCertificateDeadline();
        if (Carbon::today()->gt($effectiveDeadline)) {
            return back()->withErrors([
                'absence' => 'Il termine per validare il certificato e scaduto. Usa la proroga dell assenza.',
            ]);
        }

        $certificate = $absence->medicalCertificates()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if (! $certificate) {
            return back()->withErrors([
                'absence' => 'Nessun certificato disponibile da validare.',
            ]);
        }

        $certificate->update([
            'valid' => true,
            'validated_by' => $teacher->id,
            'validated_at' => now(),
        ]);
        $absence->update([
            'certificate_rejection_comment' => null,
        ]);

        OperationLog::record(
            $teacher,
            'absence.certificate.accepted',
            'medical_certificate',
            $certificate->id,
            [
                'absence_id' => $absence->id,
            ],
            'INFO',
            $request
        );

        return back()->with(
            'success',
            'Certificato accettato e assenza esclusa dal '.AnnualHoursLimitLabels::limit().'.'
        );
    }

    public function rejectMedicalCertificate(TeacherRequiredCommentRequest $request, Absence $absence)
    {

        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);
        if (! is_null($absence->derived_from_leave_id)) {
            return back()->withErrors([
                'absence' => 'Le assenze derivate da congedo non prevedono accetta/rifiuta certificato.',
            ]);
        }
        $statusCode = Absence::normalizeStatus($absence->status);
        if (! in_array($statusCode, [Absence::STATUS_REPORTED, Absence::STATUS_JUSTIFIED], true)) {
            return back()->withErrors([
                'absence' => 'Puoi rifiutare il certificato solo su assenze aperte o giustificate entro il termine.',
            ]);
        }
        $effectiveDeadline = $absence->syncMedicalCertificateDeadline();
        if (Carbon::today()->gt($effectiveDeadline)) {
            return back()->withErrors([
                'absence' => 'Il termine per rifiutare il certificato e scaduto. Usa la proroga dell assenza.',
            ]);
        }

        $validated = $request->validated();

        $certificate = $absence->medicalCertificates()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if (! $certificate) {
            return back()->withErrors([
                'absence' => 'Nessun certificato disponibile da rifiutare.',
            ]);
        }

        $comment = trim((string) $validated['comment']);
        $disk = Storage::disk(config('filesystems.default', 'local'));
        $filePath = (string) $certificate->file_path;
        if ($filePath !== '' && $disk->exists($filePath)) {
            $disk->delete($filePath);
        }

        $certificateId = $certificate->id;
        $certificate->delete();
        $absence->update([
            'certificate_rejection_comment' => $comment,
        ]);

        OperationLog::record(
            $teacher,
            'absence.certificate.rejected',
            'medical_certificate',
            $certificateId,
            [
                'absence_id' => $absence->id,
                'comment' => $comment,
                'deleted_file_path' => $filePath,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Certificato rifiutato e rimosso.');
    }

    public function showMedicalCertificate(Request $request, Absence $absence)
    {
        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);

        $certificate = $this->resolveLatestCertificate($absence);
        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! $disk->exists($certificate->file_path)) {
            abort(404, 'File certificato non trovato.');
        }

        return $disk->response($certificate->file_path, basename($certificate->file_path));
    }

    public function showGuardianSignature(Request $request, Absence $absence)
    {
        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);

        $confirmation = $this->resolveLatestGuardianSignature($absence);
        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! $disk->exists($confirmation->signature_path)) {
            abort(404, 'File firma tutore non trovato.');
        }

        OperationLog::record(
            $teacher,
            'absence.guardian_signature.viewed',
            'guardian_absence_confirmation',
            $confirmation->id,
            [
                'absence_id' => $absence->id,
                'guardian_id' => $confirmation->guardian_id,
                'signature_path' => $confirmation->signature_path,
            ],
            'INFO',
            $request
        );

        return $disk->response($confirmation->signature_path, basename($confirmation->signature_path));
    }

    public function downloadMedicalCertificate(Request $request, Absence $absence)
    {
        $teacher = $request->user();
        $absence = $this->resolveTeacherAbsence($teacher, $absence->id);

        $certificate = $this->resolveLatestCertificate($absence);
        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! $disk->exists($certificate->file_path)) {
            abort(404, 'File certificato non trovato.');
        }

        OperationLog::record(
            $teacher,
            'absence.certificate.downloaded',
            'medical_certificate',
            $certificate->id,
            [
                'absence_id' => $absence->id,
                'file_path' => $certificate->file_path,
            ],
            'INFO',
            $request
        );

        return $disk->download($certificate->file_path, basename($certificate->file_path));
    }

    private function resolveLatestCertificate(Absence $absence): MedicalCertificate
    {
        $certificate = $absence->medicalCertificates()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if (! $certificate) {
            abort(404, 'Nessun certificato disponibile.');
        }

        return $certificate;
    }

    private function resolveLatestGuardianSignature(Absence $absence): GuardianAbsenceConfirmation
    {
        $confirmation = $absence->guardianConfirmations()
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

    private function hasGuardianSignature(Absence $absence): bool
    {
        return $absence->guardianConfirmations->contains(
            fn ($confirmation) => $this->isSignedGuardianConfirmation($confirmation)
        );
    }

    private function isSignedGuardianConfirmation(object $confirmation): bool
    {
        $status = strtolower(trim((string) ($confirmation->status ?? '')));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }

    private function resolveTeacherAbsence(User $teacher, int $absenceId): Absence
    {
        return Absence::query()
            ->whereKey($absenceId)
            ->whereIn('student_id', function ($subQuery) use ($teacher) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $teacher->id);
            })
            ->with(['medicalCertificates', 'guardianConfirmations'])
            ->firstOrFail();
    }

    private function resolveAbsenceForDeletion(User $user, int $absenceId): Absence
    {
        if ($user->hasRole('laboratory_manager')) {
            return Absence::query()
                ->whereKey($absenceId)
                ->with(['medicalCertificates', 'guardianConfirmations'])
                ->firstOrFail();
        }

        if (! $user->hasRole('teacher')) {
            abort(403);
        }

        return $this->resolveTeacherAbsence($user, $absenceId);
    }

    private function syncMedicalCertificateRequirement(Absence $absence): void
    {
        $absence->syncMedicalCertificateRequired();
    }
}
