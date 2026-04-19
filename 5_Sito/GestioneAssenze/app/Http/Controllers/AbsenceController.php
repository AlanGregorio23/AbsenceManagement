<?php

namespace App\Http\Controllers;

use App\Http\Requests\AbsenceRequest;
use App\Http\Requests\SubmitDerivedLeaveDraftRequest;
use App\Http\Requests\UploadMedicalCertificateRequest;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\AbsenceSetting;
use App\Models\Leave;
use App\Models\MedicalCertificate;
use App\Models\OperationLog;
use App\Services\AbsenceGuardianSignatureService;
use App\Support\StudentArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

class AbsenceController extends BaseController
{
    public function __construct()
    {

        $this->middleware('student');

    }

    public function create()
    {

        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $absenceReasons = AbsenceReason::orderBy('name')->get();

        return Inertia::render('Student/AbsenceCreate', [
            'settings' => [
                'max_annual_hours' => $absenceSetting->max_annual_hours,
                'guardian_signature_required' => $absenceSetting->guardian_signature_required,
                'medical_certificate_days' => $absenceSetting->medical_certificate_days,
                'medical_certificate_max_days' => $absenceSetting->medical_certificate_max_days,
                'absence_countdown_days' => $absenceSetting->absence_countdown_days,
            ],
            'reasons' => $absenceReasons->map(fn (AbsenceReason $reason) => [
                'id' => $reason->id,
                'name' => $reason->name,
            ])->values(),
        ]);
    }

    public function NewRequestAbsence(
        AbsenceRequest $request,
        AbsenceGuardianSignatureService $guardianSignatureService
    ) {

        $user = $request->user();

        $validated = $request->validated();

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'] ?? $validated['start_date'])->startOfDay();
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy();
        }

        $hours = max((int) ($validated['hours'] ?? 1), 1);
        $motivation = $validated['motivation'] ?? null;

        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $medicalDeadline = Absence::calculateMedicalCertificateDeadline($endDate, $absenceSetting);
        $absenceDraft = new Absence([
            'student_id' => $user->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'reason' => (string) $motivation,
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => $hours,
        ]);
        $certificateRequired = $absenceDraft->resolveMedicalCertificateRequired($absenceSetting);

        $absence = Absence::create([
            'student_id' => $user->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'reason' => $motivation,
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => $hours,
            'medical_certificate_required' => $certificateRequired,
            'medical_certificate_deadline' => $medicalDeadline->toDateString(),
        ]);

        OperationLog::record(
            $user,
            'absence.request.created',
            'absence',
            $absence->id,
            [
                'start_date' => $absence->start_date,
                'end_date' => $absence->end_date,
                'assigned_hours' => $absence->assigned_hours,
                'reason' => $absence->reason,
                'medical_certificate_required' => (bool) $absence->medical_certificate_required,
                'medical_certificate_deadline' => $absence->medical_certificate_deadline,
            ],
            'INFO',
            $request
        );

        if ($request->hasFile('document')) {
            $filePath = StudentArchivePathBuilder::storeUploadedFileForStudent(
                $request->file('document'),
                $user,
                StudentArchivePathBuilder::CATEGORY_CERTIFICATES,
                [
                    'context' => 'certificato_assenza',
                    'code' => 'a'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
                ]
            );

            $certificate = MedicalCertificate::create([
                'absence_id' => $absence->id,
                'file_path' => $filePath,
                'uploaded_at' => now(),
                'valid' => false,
            ]);

            OperationLog::record(
                $user,
                'absence.certificate.uploaded',
                'medical_certificate',
                $certificate->id,
                [
                    'absence_id' => $absence->id,
                    'file_path' => $filePath,
                    'source' => 'absence_create',
                ],
                'INFO',
                $request
            );

            $absence->update([
                'certificate_rejection_comment' => null,
            ]);
        }

        $emailSummary = $guardianSignatureService->sendConfirmationEmails(
            $absence,
            $user,
            false,
            $request
        );

        $successMessage = 'Richiesta assenza inviata.';
        if ($emailSummary['guardians'] === 0) {
            $successMessage .= ' Nessun tutore associato: contattare la segreteria.';
        } elseif ($emailSummary['sent'] > 0) {
            $successMessage .= ' Email di firma inviata a '.$emailSummary['sent'].' tutore/i.';
        } elseif ($emailSummary['failed'] > 0) {
            $successMessage .= ' Invio email non riuscito: riprovare o contattare il docente.';
        }

        return back()->with('success', $successMessage);
    }

    public function uploadMedicalCertificate(UploadMedicalCertificateRequest $request, Absence $absence)
    {
        $absence->refresh();

        if ($absence->student_id !== $request->user()->id) {
            abort(403);
        }

        $statusCode = Absence::normalizeStatus($absence->status);
        if (! in_array($statusCode, [Absence::STATUS_REPORTED, Absence::STATUS_JUSTIFIED], true)) {
            return back()->withErrors([
                'document' => 'Il certificato puo essere caricato solo su assenze segnalate o giustificate entro il termine.',
            ]);
        }

        $effectiveDeadline = $absence->syncMedicalCertificateDeadline();
        if (Carbon::today()->gt($effectiveDeadline)) {
            $absence->markArbitraryForExpiredDeadlineIfNeeded();

            return back()->withErrors([
                'document' => 'Il termine per il caricamento del certificato e scaduto.',
            ]);
        }

        $existingCertificate = $absence->medicalCertificates()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();
        if ($existingCertificate) {
            return back()->withErrors([
                'document' => 'E gia presente un certificato per questa assenza. Puoi caricarne uno nuovo solo se il docente lo rifiuta.',
            ]);
        }

        $filePath = StudentArchivePathBuilder::storeUploadedFileForStudent(
            $request->file('document'),
            $request->user(),
            StudentArchivePathBuilder::CATEGORY_CERTIFICATES,
            [
                'context' => 'certificato_assenza',
                'code' => 'a'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
            ]
        );

        $certificate = MedicalCertificate::create([
            'absence_id' => $absence->id,
            'file_path' => $filePath,
            'uploaded_at' => now(),
            'valid' => false,
        ]);
        $absence->update([
            'certificate_rejection_comment' => null,
        ]);

        OperationLog::record(
            $request->user(),
            'absence.certificate.uploaded',
            'medical_certificate',
            $certificate->id,
            [
                'absence_id' => $absence->id,
                'file_path' => $filePath,
                'source' => 'student_documents',
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Certificato medico caricato.');
    }

    public function editDerivedLeaveDraft(Request $request, Absence $absence)
    {
        $absence->refresh();

        $student = $request->user();
        if (! $student || $absence->student_id !== $student->id) {
            abort(403);
        }

        if (is_null($absence->derived_from_leave_id)) {
            return redirect()->route('dashboard')->withErrors([
                'absence' => 'Questa assenza non e una bozza derivata da congedo.',
            ]);
        }

        $statusCode = Absence::normalizeStatus($absence->status);
        if ($statusCode !== Absence::STATUS_DRAFT) {
            return redirect()->route('dashboard')->withErrors([
                'absence' => 'La bozza non e piu modificabile.',
            ]);
        }

        $startDate = Carbon::parse($absence->start_date)->startOfDay();
        if ($startDate->isFuture()) {
            return redirect()->route('dashboard')->withErrors([
                'absence' => 'La bozza sara disponibile dal '.$startDate->format('d/m/Y').'.',
            ]);
        }

        $absence->loadMissing('derivedFromLeave');
        $derivedLeave = $absence->derivedFromLeave;

        $leaveCode = $derivedLeave
            ? 'C-'.str_pad((string) $derivedLeave->id, 4, '0', STR_PAD_LEFT)
            : null;
        $leavePeriod = null;
        $leaveRequestedLessonsLabel = null;
        if ($derivedLeave) {
            $leaveStart = Carbon::parse($derivedLeave->start_date)->startOfDay();
            $leaveEnd = Carbon::parse($derivedLeave->end_date ?? $derivedLeave->start_date)->startOfDay();
            $leavePeriod = $leaveStart->isSameDay($leaveEnd)
                ? $leaveStart->format('d M Y')
                : $leaveStart->format('d M Y').' - '.$leaveEnd->format('d M Y');
            $leaveRequestedLessonsLabel = Leave::formatRequestedLessonsLabel(
                Leave::normalizeRequestedLessonsPayload($derivedLeave->requested_lessons),
                $derivedLeave->start_date?->toDateString(),
                $derivedLeave->end_date?->toDateString()
            );
        }

        return Inertia::render('Student/AbsenceDraftFromLeave', [
            'draft' => [
                'id' => 'A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
                'absence_id' => $absence->id,
                'start_date' => $absence->start_date?->toDateString(),
                'end_date' => Carbon::parse($absence->end_date ?? $absence->start_date)->toDateString(),
                'hours' => max((int) $absence->assigned_hours, 1),
                'motivation' => (string) ($absence->reason ?? ''),
                'derived_leave_code' => $leaveCode,
                'leave_period' => $leavePeriod,
                'leave_reason' => $derivedLeave?->reason ?? '-',
                'leave_destination' => $derivedLeave?->destination ?? '-',
                'leave_requested_lessons_label' => $leaveRequestedLessonsLabel !== ''
                    ? $leaveRequestedLessonsLabel
                    : null,
            ],
        ]);
    }

    public function submitDerivedLeaveDraft(
        SubmitDerivedLeaveDraftRequest $request,
        Absence $absence,
        AbsenceGuardianSignatureService $guardianSignatureService
    ) {
        $absence->refresh();

        $student = $request->user();
        if (! $student || $absence->student_id !== $student->id) {
            abort(403);
        }

        if (is_null($absence->derived_from_leave_id)) {
            return back()->withErrors([
                'absence' => 'Questa assenza non e una bozza derivata da congedo.',
            ]);
        }

        $statusCode = Absence::normalizeStatus($absence->status);
        if ($statusCode !== Absence::STATUS_DRAFT) {
            return back()->withErrors([
                'absence' => 'Puoi inviare solo assenze in stato bozza.',
            ]);
        }

        $validated = $request->validated();

        $startDate = Carbon::parse($absence->start_date)->startOfDay();
        $endDate = Carbon::parse($absence->end_date ?? $absence->start_date)->startOfDay();
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy();
        }

        if ($startDate->isFuture()) {
            return back()->withErrors([
                'absence' => 'Puoi inviare la bozza solo dal giorno di assenza in poi.',
            ]);
        }

        $overlapExists = Absence::query()
            ->where('student_id', $student->id)
            ->whereKeyNot($absence->id)
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->exists();
        if ($overlapExists) {
            return back()->withErrors([
                'start_date' => 'Hai gia una richiesta assenza su uno o piu giorni selezionati.',
            ]);
        }

        $hours = max((int) $validated['hours'], 1);
        $existingComment = trim((string) ($absence->teacher_comment ?? ''));
        $submissionNote = 'Bozza congedo inviata come assenza il '.now()->format('d/m/Y H:i').'.';

        $beforePayload = [
            'start_date' => $absence->start_date?->toDateString(),
            'end_date' => $absence->end_date?->toDateString(),
            'assigned_hours' => (int) $absence->assigned_hours,
            'reason' => (string) ($absence->reason ?? ''),
            'status' => Absence::normalizeStatus($absence->status),
            'derived_from_leave_id' => (int) $absence->derived_from_leave_id,
        ];

        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $medicalDeadline = Absence::calculateMedicalCertificateDeadline($endDate, $absenceSetting);

        $absence->update([
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'assigned_hours' => $hours,
            'status' => Absence::STATUS_REPORTED,
            'approved_without_guardian' => false,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => $medicalDeadline->toDateString(),
            'certificate_rejection_comment' => null,
            'hours_decided_at' => null,
            'hours_decided_by' => null,
            'teacher_comment' => $existingComment !== ''
                ? $existingComment."\n".$submissionNote
                : $submissionNote,
        ]);

        OperationLog::record(
            $student,
            'absence.derived_leave_draft.submitted',
            'absence',
            $absence->id,
            [
                'derived_from_leave_id' => (int) $absence->derived_from_leave_id,
                'before' => $beforePayload,
                'after' => [
                    'start_date' => $absence->start_date?->toDateString(),
                    'end_date' => $absence->end_date?->toDateString(),
                    'assigned_hours' => (int) $absence->assigned_hours,
                    'reason' => (string) ($absence->reason ?? ''),
                    'status' => Absence::normalizeStatus($absence->status),
                ],
            ],
            'INFO',
            $request
        );

        $emailSummary = $guardianSignatureService->sendConfirmationEmails(
            $absence,
            $student,
            false,
            $request
        );

        $successMessage = 'Bozza assenza inviata.';
        if ($emailSummary['guardians'] === 0) {
            $successMessage .= ' Nessun tutore associato: contattare la segreteria.';
        } elseif ($emailSummary['sent'] > 0) {
            $successMessage .= ' Email di firma inviata a '.$emailSummary['sent'].' tutore/i.';
        } elseif ($emailSummary['failed'] > 0) {
            $successMessage .= ' Invio email non riuscito: riprovare o contattare il docente.';
        }

        return redirect()->route('dashboard')->with('success', $successMessage);
    }

    public function updateDerivedLeaveEffectiveHours(Request $request, Absence $absence)
    {
        return back()->withErrors([
            'absence' => 'Modifica ore effettive disabilitata: la gestione ore resta al docente.',
        ]);
    }
}
