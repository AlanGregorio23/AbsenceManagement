<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveWorkflowCommentRequest;
use App\Http\Requests\LeaveWorkflowDecisionRequest;
use App\Http\Requests\UpdateManagedLeaveRequest;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\GuardianLeaveConfirmation;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveEmailNotification;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\LeaveAbsenceDraftService;
use App\Services\LeaveGuardianSignatureService;
use App\Support\AnnualHoursLimitLabels;
use App\Support\SimplePdfBuilder;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LeaveWorkflowController extends BaseController
{
    public function index(Request $request)
    {
        $user = $request->user();
        $this->ensureCanAccessLeavesIndex($user);

        $items = collect((new Leave)->getLeave($user));
        $studentClassMap = $this->getStudentClassMap($user);

        $items = $items
            ->map(function (array $item) use ($studentClassMap) {
                $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';

                return $item;
            })
            ->filter(function (array $item) {
                return in_array($item['stato_code'] ?? '', Leave::openStatuses(), true);
            })
            ->sortByDesc('date')
            ->values();

        return Inertia::render('LaboratoryManager/Leaves', [
            'items' => $items,
            'role' => $user->role,
        ]);
    }

    public function show(Request $request, Leave $leave)
    {
        $user = $request->user();
        $leave = $this->resolveManagedLeave($user, $leave->id);

        $studentClassMap = $this->getStudentClassMap($user);
        $item = collect((new Leave)->getLeave($user))->firstWhere('leave_id', $leave->id);
        if (! is_array($item)) {
            abort(404);
        }

        $item = $this->buildDetailItem($item, $leave, $studentClassMap, $user);
        $history = $this->buildHistory($leave);
        $availableActions = $this->resolveAvailableActions($item);
        $initialAction = $this->resolveInitialAction((string) $request->query('action', ''), $availableActions);

        $reasons = AbsenceReason::query()
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn ($name) => trim((string) $name) !== '')
            ->values();

        return Inertia::render('LaboratoryManager/LeaveDetail', [
            'item' => $item,
            'history' => $history,
            'initialAction' => $initialAction,
            'role' => $user->role,
            'reasons' => $reasons,
        ]);
    }

    public function preApprove(LeaveWorkflowDecisionRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);
        $statusCode = Leave::normalizeStatus($leave->status);

        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Puoi applicare override firma solo su richieste congedo aperte.'
        )) {
            return $statusErrorResponse;
        }

        if ($this->hasGuardianSignature($leave)) {
            return back()->withErrors([
                'leave' => 'Override firma non consentito: firma tutore gia presente.',
            ]);
        }
        if ((bool) $leave->approved_without_guardian) {
            return back()->withErrors([
                'leave' => 'Override firma tutore gia registrato su questo congedo.',
            ]);
        }
        if (! in_array(
            $statusCode,
            [
                Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE,
                Leave::STATUS_DOCUMENTATION_REQUESTED,
                Leave::STATUS_IN_REVIEW,
            ],
            true
        )) {
            return back()->withErrors([
                'leave' => 'Override firma disponibile solo su congedi aperti senza firma tutore.',
            ]);
        }

        $validated = $request->validated();

        $comment = $this->normalizeComment($validated['comment'] ?? null);
        if ($commentErrorResponse = $this->requireCommentWhenDocumentationMissing($leave, $comment)) {
            return $commentErrorResponse;
        }

        $nextStatus = in_array(
            $statusCode,
            [Leave::STATUS_DOCUMENTATION_REQUESTED, Leave::STATUS_IN_REVIEW],
            true
        )
            ? $statusCode
            : Leave::STATUS_PRE_APPROVED;

        $leave->update([
            'status' => $nextStatus,
            'approved_without_guardian' => true,
            'workflow_comment' => $comment !== '' ? $comment : $leave->workflow_comment,
        ]);

        $this->createApprovalRecord(
            $leave,
            $actor,
            'pre_approved',
            $comment !== '' ? $comment : null,
            true
        );

        OperationLog::record(
            $actor,
            'leave.pre_approved',
            'leave',
            $leave->id,
            [
                'comment' => $comment !== '' ? $comment : null,
                'status' => $leave->status,
                'approved_without_guardian' => true,
                'override_guardian_signature' => true,
            ],
            'INFO',
            $request
        );

        return $this->approveManagedLeave($request, $leave, $actor);
    }

    public function approve(LeaveWorkflowDecisionRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);

        return $this->approveManagedLeave($request, $leave, $actor);
    }

    private function approveManagedLeave(LeaveWorkflowDecisionRequest $request, Leave $leave, User $actor)
    {
        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Puoi approvare solo richieste congedo aperte.'
        )) {
            return $statusErrorResponse;
        }

        $validated = $request->validated();

        $guardianSigned = $this->hasGuardianSignature($leave);
        if (! $guardianSigned && ! (bool) $leave->approved_without_guardian) {
            return back()->withErrors([
                'leave' => 'Firma tutore assente: usa prima Approva senza firma (override).',
            ]);
        }

        $comment = $this->normalizeComment($validated['comment'] ?? null);
        if ($commentErrorResponse = $this->requireCommentWhenDocumentationMissing($leave, $comment)) {
            return $commentErrorResponse;
        }
        [$countHours, $countHoursComment] = $this->resolveCountHoursDecision($leave, $validated, $comment);

        $absenceId = null;
        $registrationScheduledFrom = null;
        $leaveAbsenceDraftService = app(LeaveAbsenceDraftService::class);
        DB::transaction(function () use (
            $leave,
            $actor,
            $request,
            $comment,
            $guardianSigned,
            $countHours,
            $countHoursComment,
            &$absenceId,
            &$registrationScheduledFrom,
            $leaveAbsenceDraftService
        ): void {
            $leave->update([
                'status' => Leave::STATUS_APPROVED,
                'approved_without_guardian' => ! $guardianSigned,
                'workflow_comment' => $comment !== '' ? $comment : null,
                'count_hours' => $countHours,
                'count_hours_comment' => $countHoursComment,
                'hours_decision_at' => now(),
                'hours_decision_by' => $actor->id,
            ]);

            $this->createApprovalRecord(
                $leave,
                $actor,
                'approved',
                $comment !== '' ? $comment : $countHoursComment,
                ! $guardianSigned
            );

            OperationLog::record(
                $actor,
                'leave.approved',
                'leave',
                $leave->id,
                [
                    'comment' => $comment !== '' ? $comment : null,
                    'approved_without_guardian' => ! $guardianSigned,
                    'count_hours' => $countHours,
                    'count_hours_comment' => $countHoursComment,
                    'status' => $leave->status,
                ],
                'INFO',
                $request
            );

            $leave->update([
                'status' => Leave::STATUS_REGISTERED,
                'registered_by' => $actor->id,
            ]);

            $registrationNote = 'Congedo registrato e passato ad assenza bozza in attesa invio studente.';
            if ($leaveAbsenceDraftService->shouldRegisterNow($leave)) {
                $absence = $this->registerLeaveAsAbsence($leave, $actor, $request);
                $absenceId = $absence->id;

                $leave->update([
                    'registered_at' => now(),
                    'registered_absence_id' => $absenceId,
                ]);
            } else {
                $registrationScheduledFrom = $leaveAbsenceDraftService->registrationAvailableFromLabel($leave);
                $registrationNote = 'Congedo approvato. Bozza assenza programmata automaticamente dal '
                    .$registrationScheduledFrom.'.';

                $leave->update([
                    'registered_at' => null,
                    'registered_absence_id' => null,
                ]);
            }

            $this->createApprovalRecord(
                $leave,
                $actor,
                'registered',
                $registrationNote,
                ! $guardianSigned
            );

            OperationLog::record(
                $actor,
                'leave.registered',
                'leave',
                $leave->id,
                [
                    'status' => $leave->status,
                    'registered_absence_id' => $absenceId,
                    'scheduled_from_date' => $registrationScheduledFrom !== null
                        ? $leave->start_date?->toDateString()
                        : null,
                ],
                'INFO',
                $request
            );
        });

        if ($absenceId !== null) {
            $leave->registered_absence_id = $absenceId;
        }

        if ($absenceId !== null) {
            $this->notifyTeachersAboutRegistration($leave, $comment, $actor, $request);

            return back()->with(
                'success',
                'Congedo approvato e registrato. Assenza creata in bozza: lo studente deve completarla e inviarla.'
            );
        }

        $registrationScheduledFrom ??= 'il giorno di inizio congedo';

        return back()->with(
            'success',
            'Congedo approvato e registrato. La bozza assenza verra creata automaticamente dal '
            .$registrationScheduledFrom.'.'
        );
    }

    public function reject(LeaveWorkflowCommentRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);

        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Puoi rifiutare solo richieste congedo aperte.'
        )) {
            return $statusErrorResponse;
        }

        $validated = $request->validated();

        $comment = $this->normalizeComment($validated['comment']);

        $leave->update([
            'status' => Leave::STATUS_REJECTED,
            'workflow_comment' => $comment,
            'approved_without_guardian' => false,
        ]);

        $this->createApprovalRecord(
            $leave,
            $actor,
            'rejected',
            $comment,
            false
        );

        OperationLog::record(
            $actor,
            'leave.rejected',
            'leave',
            $leave->id,
            [
                'comment' => $comment,
                'status' => $leave->status,
            ],
            'INFO',
            $request
        );

        $this->notifyRejection($leave, $comment, $actor, $request);

        return back()->with('success', 'Congedo rifiutato.');
    }

    public function destroy(Request $request, Leave $leave)
    {
        $actor = $request->user();
        if (! $actor) {
            abort(403);
        }
        if ($forbiddenResponse = $this->requireLaboratoryManager(
            $actor,
            'Solo il capo laboratorio puo eliminare il congedo.'
        )) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);
        $leave->loadMissing([
            'guardianConfirmations',
            'registeredAbsence.medicalCertificates',
            'registeredAbsence.guardianConfirmations',
        ]);

        $leaveId = (int) $leave->id;
        $leaveCode = $this->formatLeaveCode($leaveId);
        $documentationPath = trim((string) $leave->documentation_path);
        $guardianSignaturePaths = $leave->guardianConfirmations
            ->pluck('signature_path')
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->unique()
            ->values()
            ->all();

        $derivedAbsences = Absence::query()
            ->where('derived_from_leave_id', $leaveId)
            ->with(['medicalCertificates', 'guardianConfirmations'])
            ->get();

        $registeredAbsence = $leave->registeredAbsence;
        if ($registeredAbsence && ! $derivedAbsences->contains('id', $registeredAbsence->id)) {
            $registeredAbsence->loadMissing(['medicalCertificates', 'guardianConfirmations']);
            $derivedAbsences->push($registeredAbsence);
        }

        $derivedAbsenceIds = $derivedAbsences
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $derivedAbsenceCertificatePaths = $derivedAbsences
            ->flatMap(fn (Absence $absence) => $absence->medicalCertificates
                ->pluck('file_path')
                ->all())
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->unique()
            ->values()
            ->all();
        $derivedAbsenceSignaturePaths = $derivedAbsences
            ->flatMap(fn (Absence $absence) => $absence->guardianConfirmations
                ->pluck('signature_path')
                ->all())
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn ($path) => $path !== '')
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($leave, $derivedAbsenceIds): void {
            if ($derivedAbsenceIds !== []) {
                Absence::query()
                    ->whereIn('id', $derivedAbsenceIds)
                    ->delete();
            }

            $leave->delete();
        });

        $filesToDelete = array_merge(
            $documentationPath !== '' ? [$documentationPath] : [],
            $guardianSignaturePaths,
            $derivedAbsenceCertificatePaths,
            $derivedAbsenceSignaturePaths
        );

        $disk = Storage::disk(config('filesystems.default', 'local'));
        foreach ($filesToDelete as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        OperationLog::record(
            $actor,
            'leave.deleted',
            'leave',
            $leaveId,
            [
                'leave_code' => $leaveCode,
                'deleted_documentation_path' => $documentationPath !== '' ? $documentationPath : null,
                'deleted_guardian_signature_files' => $guardianSignaturePaths,
                'deleted_derived_absence_ids' => $derivedAbsenceIds,
                'deleted_derived_absence_certificate_files' => $derivedAbsenceCertificatePaths,
                'deleted_derived_absence_signature_files' => $derivedAbsenceSignaturePaths,
            ],
            'WARNING',
            $request
        );

        $redirectRoute = $actor->hasRole('laboratory_manager') ? 'lab.leaves' : 'dashboard';

        return redirect()
            ->route($redirectRoute)
            ->with('success', 'Congedo '.$leaveCode.' eliminato definitivamente.');
    }

    public function forwardToManagement(LeaveWorkflowCommentRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);

        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Puoi inoltrare in direzione solo richieste congedo aperte.'
        )) {
            return $statusErrorResponse;
        }

        $validated = $request->validated();

        $comment = $this->normalizeComment($validated['comment']);

        $leave->update([
            'status' => Leave::STATUS_FORWARDED_TO_MANAGEMENT,
            'workflow_comment' => $comment,
            'approved_without_guardian' => false,
        ]);

        $this->createApprovalRecord(
            $leave,
            $actor,
            'forwarded_to_management',
            $comment,
            false
        );

        OperationLog::record(
            $actor,
            'leave.forwarded_to_management',
            'leave',
            $leave->id,
            [
                'comment' => $comment,
                'status' => $leave->status,
            ],
            'INFO',
            $request
        );

        $this->notifyForwardToManagement($leave, $comment, $actor, $request);

        return back()->with('success', 'Congedo inoltrato in direzione.');
    }

    public function requestDocumentation(LeaveWorkflowCommentRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);
        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Puoi richiedere documentazione solo su richieste congedo aperte.'
        )) {
            return $statusErrorResponse;
        }

        if (! empty($leave->documentation_path)) {
            return back()->withErrors([
                'leave' => 'Documentazione gia presente: usa Rifiuta documentazione per richiedere un nuovo allegato.',
            ]);
        }

        $validated = $request->validated();

        $comment = $this->normalizeComment($validated['comment']);

        $leave->update([
            'status' => Leave::STATUS_DOCUMENTATION_REQUESTED,
            'documentation_request_comment' => $comment,
            'workflow_comment' => $comment,
        ]);

        $this->createApprovalRecord(
            $leave,
            $actor,
            'documentation_requested',
            $comment,
            false
        );

        OperationLog::record(
            $actor,
            'leave.documentation.requested',
            'leave',
            $leave->id,
            [
                'comment' => $comment,
                'status' => $leave->status,
            ],
            'INFO',
            $request
        );

        $studentEmail = $this->resolveStudentEmail($leave);
        if ($studentEmail !== '') {
            $leaveCode = $this->formatLeaveCode($leave->id);
            $this->sendLeaveEmail(
                $leave,
                'documentation_requested_student',
                $studentEmail,
                'Documentazione richiesta per congedo',
                $this->composeLeaveEmailBody(
                    'Per proseguire la valutazione del congedo '.$leaveCode.' e necessario inviare ulteriore documentazione.',
                    [
                        'Codice congedo' => $leaveCode,
                        'Motivazione richiesta' => $comment,
                    ],
                    'Accedi alla sezione Documenti e carica l allegato richiesto.'
                ),
                $actor,
                $request
            );
        }

        return back()->with('success', 'Documentazione richiesta allo studente.');
    }

    public function rejectDocumentation(LeaveWorkflowCommentRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);
        $statusCode = Leave::normalizeStatus($leave->status);

        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Puoi rifiutare documentazione solo su richieste congedo aperte.'
        )) {
            return $statusErrorResponse;
        }

        if (empty($leave->documentation_path)) {
            return back()->withErrors([
                'leave' => 'Nessuna documentazione da rifiutare.',
            ]);
        }

        $validated = $request->validated();

        $comment = $this->normalizeComment($validated['comment']);
        $previousDocumentationPath = (string) $leave->documentation_path;

        $nextStatus = $statusCode === Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE
            ? Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE
            : Leave::STATUS_DOCUMENTATION_REQUESTED;

        $leave->update([
            'status' => $nextStatus,
            'documentation_path' => null,
            'documentation_uploaded_at' => null,
            'documentation_request_comment' => $comment,
            'workflow_comment' => $comment,
        ]);

        if ($previousDocumentationPath !== '') {
            $disk = Storage::disk(config('filesystems.default', 'local'));
            if ($disk->exists($previousDocumentationPath)) {
                $disk->delete($previousDocumentationPath);
            }
        }

        $this->createApprovalRecord(
            $leave,
            $actor,
            'documentation_rejected',
            $comment,
            false
        );

        OperationLog::record(
            $actor,
            'leave.documentation.rejected',
            'leave',
            $leave->id,
            [
                'comment' => $comment,
                'status' => $leave->status,
            ],
            'INFO',
            $request
        );

        $studentEmail = $this->resolveStudentEmail($leave);
        if ($studentEmail !== '') {
            $leaveCode = $this->formatLeaveCode($leave->id);
            $this->sendLeaveEmail(
                $leave,
                'documentation_rejected_student',
                $studentEmail,
                'Documentazione congedo da sostituire',
                $this->composeLeaveEmailBody(
                    'La documentazione inviata per il congedo '.$leaveCode.' non e stata accettata.',
                    [
                        'Codice congedo' => $leaveCode,
                        'Motivazione del rifiuto' => $comment,
                    ],
                    'Carica un nuovo allegato nella sezione Documenti per completare la richiesta.'
                ),
                $actor,
                $request
            );
        }

        return back()->with(
            'success',
            'Documentazione rifiutata. E stata inviata una nuova richiesta di allegato allo studente.'
        );
    }

    public function update(UpdateManagedLeaveRequest $request, Leave $leave)
    {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);

        $validated = $request->validated();

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->startOfDay();
        $hours = (int) $validated['hours'];

        $beforePayload = [
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'requested_hours' => (int) $leave->requested_hours,
            'requested_lessons' => Leave::normalizeRequestedLessonsPayload($leave->requested_lessons),
            'reason' => (string) $leave->reason,
            'destination' => (string) $leave->destination,
            'status' => Leave::normalizeStatus($leave->status),
        ];

        $comment = $this->normalizeComment($validated['comment'] ?? null);
        $countHours = (bool) $validated['count_hours'];
        $countHoursComment = $this->normalizeComment($validated['count_hours_comment'] ?? null);

        $leave->update([
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'requested_hours' => $hours,
            'requested_lessons' => null,
            'reason' => $this->normalizeComment($validated['motivation']),
            'destination' => $this->normalizeComment($validated['destination']),
            'count_hours' => $countHours,
            'count_hours_comment' => $countHours ? null : $countHoursComment,
            'hours_decision_at' => now(),
            'hours_decision_by' => $actor->id,
            'workflow_comment' => $comment !== '' ? $comment : $leave->workflow_comment,
        ]);

        if ($leave->registered_absence_id) {
            $this->syncRegisteredAbsenceFromLeave($leave, $actor);
        }

        $this->createApprovalRecord(
            $leave,
            $actor,
            'updated',
            $comment !== '' ? $comment : 'Dettagli congedo aggiornati.',
            false
        );

        OperationLog::record(
            $actor,
            'leave.updated',
            'leave',
            $leave->id,
            [
                'before' => $beforePayload,
                'after' => [
                    'start_date' => $leave->start_date?->toDateString(),
                    'end_date' => $leave->end_date?->toDateString(),
                    'requested_hours' => (int) $leave->requested_hours,
                    'requested_lessons' => Leave::normalizeRequestedLessonsPayload($leave->requested_lessons),
                    'reason' => (string) $leave->reason,
                    'destination' => (string) $leave->destination,
                    'count_hours' => (bool) $leave->count_hours,
                    'count_hours_comment' => (string) $leave->count_hours_comment,
                    'status' => Leave::normalizeStatus($leave->status),
                ],
                'comment' => $comment !== '' ? $comment : null,
            ],
            'INFO',
            $request
        );

        return back()->with('success', 'Congedo aggiornato.');
    }

    public function resendGuardianConfirmationEmail(
        Request $request,
        Leave $leave,
        LeaveGuardianSignatureService $signatureService
    ) {
        $actor = $request->user();
        if ($forbiddenResponse = $this->requireLaboratoryManager($actor)) {
            return $forbiddenResponse;
        }

        $leave = $this->resolveManagedLeave($actor, $leave->id);

        if ($statusErrorResponse = $this->requireOpenLeaveStatus(
            $leave,
            'Reinvio non consentito per questo stato.'
        )) {
            return $statusErrorResponse;
        }

        if ($this->hasGuardianSignature($leave)) {
            return back()->withErrors([
                'leave' => 'Firma tutore gia presente. Reinvio non necessario.',
            ]);
        }

        $summary = $signatureService->sendConfirmationEmails(
            $leave,
            $actor,
            true,
            $request
        );

        if ($summary['guardians'] === 0) {
            return back()->withErrors([
                'leave' => 'Nessun tutore con email associato allo studente.',
            ]);
        }

        if ($summary['sent'] === 0 && $summary['failed'] > 0) {
            return back()->withErrors([
                'leave' => 'Invio email non riuscito. Riprova.',
            ]);
        }

        return back()->with(
            'success',
            'Email di conferma reinviata a '.$summary['sent'].' tutore/i.'
        );
    }

    public function showGuardianSignature(Request $request, Leave $leave)
    {
        $user = $request->user();
        $leave = $this->resolveManagedLeave($user, $leave->id);

        $confirmation = $leave->guardianConfirmations
            ->filter(fn (GuardianLeaveConfirmation $item) => filled($item->signature_path)
                && $this->isSignedGuardianConfirmation($item))
            ->sortBy(fn (GuardianLeaveConfirmation $item) => $this->signedAtTimestamp($item))
            ->first();

        if (! $confirmation || empty($confirmation->signature_path)) {
            abort(404, 'Nessuna firma tutore disponibile.');
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));
        if (! $disk->exists($confirmation->signature_path)) {
            abort(404, 'File firma tutore non trovato.');
        }

        return $disk->response($confirmation->signature_path, basename($confirmation->signature_path));
    }

    public function showDocumentation(Request $request, Leave $leave)
    {
        $user = $request->user();
        $leave = $this->resolveManagedLeave($user, $leave->id);

        $filePath = (string) $leave->documentation_path;
        if ($filePath === '') {
            abort(404, 'Nessuna documentazione disponibile.');
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));
        if (! $disk->exists($filePath)) {
            abort(404, 'File documentazione non trovato.');
        }

        return $disk->response($filePath, basename($filePath));
    }

    public function downloadForwardingPdf(
        Request $request,
        Leave $leave,
        SimplePdfBuilder $pdfBuilder
    ) {
        $actor = $request->user();
        if (! $actor) {
            abort(403);
        }

        if ($actor->hasRole('student')) {
            $leave = Leave::query()
                ->whereKey($leave->id)
                ->where('student_id', $actor->id)
                ->with([
                    'student.guardians',
                    'guardianConfirmations.guardian',
                    'approvals.decider',
                ])
                ->firstOrFail();

            if (Leave::normalizeStatus((string) $leave->status) !== Leave::STATUS_FORWARDED_TO_MANAGEMENT) {
                abort(403, 'PDF disponibile solo per congedi inoltrati in direzione.');
            }
        } else {
            $leave = $this->resolveManagedLeave($actor, $leave->id);
        }

        $history = $this->buildHistory($leave)->map(function (array $entry): string {
            $timestamp = trim((string) ($entry['decided_at'] ?? '-'));
            $decisionBy = trim((string) ($entry['decided_by'] ?? 'Sistema'));
            $label = trim((string) ($entry['label'] ?? '-'));
            $notes = trim((string) ($entry['notes'] ?? ''));
            $line = $timestamp.' - '.$label.' - '.$decisionBy;

            return $notes !== '' ? $line.' ('.$notes.')' : $line;
        })->values()->all();

        $firstGuardianSignature = $this->resolveFirstSignedGuardianConfirmation($leave);
        $guardianSignedAt = $firstGuardianSignature
            ? ($firstGuardianSignature->confirmed_at ?? $firstGuardianSignature->signed_at)
            : null;
        $guardianSignedBy = $firstGuardianSignature
            ? $this->resolveSignerName($firstGuardianSignature)
            : null;
        $requestedLessonsLabel = Leave::formatRequestedLessonsLabel(
            $leave->requested_lessons,
            $leave->start_date?->toDateString(),
            $leave->end_date?->toDateString()
        );

        $pdfBinary = $pdfBuilder->buildLeaveForwardingDocument([
            'leave_code' => $this->formatLeaveCode($leave->id),
            'status_label' => Leave::statusLabel((string) $leave->status),
            'generated_at' => now()->format('d/m/Y H:i'),
            'student_name' => $this->resolveStudentFullName($leave),
            'period_start' => $leave->start_date?->format('d/m/Y') ?? '-',
            'period_end' => $leave->end_date?->format('d/m/Y') ?? '-',
            'requested_hours' => (int) $leave->requested_hours,
            'requested_lessons_label' => $requestedLessonsLabel,
            'destination' => (string) ($leave->destination ?? '-'),
            'reason' => (string) ($leave->reason ?? '-'),
            'count_hours' => (bool) $leave->count_hours,
            'count_hours_comment' => (string) ($leave->count_hours_comment ?? ''),
            'workflow_comment' => (string) ($leave->workflow_comment ?? ''),
            'documentation_present' => ! empty($leave->documentation_path),
            'documentation_uploaded_at' => $leave->documentation_uploaded_at?->format('d/m/Y H:i') ?? '',
            'guardian_signed_by' => $guardianSignedBy,
            'guardian_signed_at' => $guardianSignedAt?->format('d/m/Y H:i') ?? '',
            'history' => $history,
        ]);

        $fileName = 'congedo-'.$this->formatLeaveCode($leave->id).'-inoltro-direzione.pdf';

        OperationLog::record(
            $actor,
            'leave.pdf.downloaded',
            'leave',
            $leave->id,
            [
                'filename' => $fileName,
                'status' => $leave->status,
            ],
            'INFO',
            $request
        );

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    private function ensureCanAccessLeavesIndex(?User $user): void
    {
        if (! $user || ! $user->hasRole('laboratory_manager')) {
            abort(403);
        }
    }

    private function ensureCanManageLeaves(?User $user): void
    {
        if (! $user) {
            abort(403);
        }

        if ($user->hasRole('laboratory_manager') || $user->hasRole('teacher')) {
            return;
        }

        abort(403);
    }

    private function resolveManagedLeave(User $user, int $leaveId): Leave
    {
        $this->ensureCanManageLeaves($user);

        $query = Leave::query()
            ->whereKey($leaveId)
            ->with([
                'student.guardians',
                'guardianConfirmations.guardian',
                'approvals.decider',
            ]);

        if ($user->hasRole('teacher')) {
            $query->whereIn('student_id', function ($subQuery) use ($user) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $user->id);
            });
        }

        return $query->firstOrFail();
    }

    private function getStudentClassMap(User $user): array
    {
        $classes = $user->hasRole('teacher')
            ? $user->teachingClasses()->with('students')->get()
            : \App\Models\SchoolClass::query()->with('students')->get();

        $map = [];

        foreach ($classes as $class) {
            $label = $class->year && $class->section
                ? $class->year.$class->section
                : $class->name;

            foreach ($class->students as $student) {
                $map[$student->id][] = $label;
            }
        }

        return array_map(
            fn (array $labels) => implode(', ', array_unique($labels)),
            $map
        );
    }

    private function hasGuardianSignature(Leave $leave): bool
    {
        return $leave->hasGuardianSignature();
    }

    private function resolveFirstSignedGuardianConfirmation(Leave $leave)
    {
        return $leave->guardianConfirmations
            ->filter(fn (GuardianLeaveConfirmation $confirmation) => $this->isSignedGuardianConfirmation($confirmation))
            ->sortBy(fn (GuardianLeaveConfirmation $confirmation) => $this->signedAtTimestamp($confirmation))
            ->first();
    }

    private function resolveSignerName($confirmation): string
    {
        $notes = json_decode((string) $confirmation->notes, true);
        $signerName = is_array($notes) ? trim((string) ($notes['signer_name'] ?? '')) : '';

        if ($signerName !== '') {
            return $signerName;
        }

        $guardianName = trim((string) $confirmation->guardian?->name);

        return $guardianName !== '' ? $guardianName : '-';
    }

    private function createApprovalRecord(
        Leave $leave,
        User $actor,
        string $decision,
        ?string $notes,
        bool $overrideGuardianSignature
    ): void {
        LeaveApproval::create([
            'leave_id' => $leave->id,
            'decided_by' => $actor->id,
            'decision' => $decision,
            'notes' => $notes,
            'decided_at' => now(),
            'override_guardian_signature' => $overrideGuardianSignature,
        ]);
    }

    private function registerLeaveAsAbsence(Leave $leave, User $actor, Request $request): Absence
    {
        return app(LeaveAbsenceDraftService::class)->registerFromLeave($leave, $actor, $request);
    }

    private function syncRegisteredAbsenceFromLeave(Leave $leave, User $actor): void
    {
        if (! $leave->registered_absence_id) {
            return;
        }

        $absence = Absence::query()->find($leave->registered_absence_id);
        if (! $absence) {
            return;
        }

        $absence->update([
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'reason' => (string) $leave->reason,
            'assigned_hours' => (int) $leave->requested_hours,
            'counts_40_hours' => (bool) $leave->count_hours,
            'counts_40_hours_comment' => (bool) $leave->count_hours
                ? null
                : ($this->normalizeComment($leave->count_hours_comment)
                    ?: AnnualHoursLimitLabels::leaveExceptionComment()),
            'medical_certificate_required' => false,
            'hours_decided_at' => null,
            'hours_decided_by' => null,
        ]);
    }

    private function notifyTeachersAboutRegistration(
        Leave $leave,
        string $comment,
        ?User $actor,
        ?Request $request
    ): void {
        $teacherEmails = $this->getClassTeacherEmails($leave->student_id);
        $leaveCode = $this->formatLeaveCode($leave->id);
        $absenceCode = $this->formatAbsenceCode($leave->registered_absence_id);
        $studentName = $this->resolveStudentFullName($leave);

        foreach ($teacherEmails as $email) {
            $body = $this->composeLeaveEmailBody(
                'Ti informiamo che il congedo '.$leaveCode.' dello studente '.$studentName.' e stato registrato come assenza.',
                [
                    'Codice congedo' => $leaveCode,
                    'Codice assenza' => $absenceCode,
                    'Studente' => $studentName,
                    'Commento del capo laboratorio' => $comment !== '' ? $comment : null,
                ],
                'Prima della validazione docente lo studente deve completare e inviare la bozza assenza.'
            );

            $this->sendLeaveEmail(
                $leave,
                'registered_teacher',
                $email,
                'Congedo registrato - azione docente',
                $body,
                $actor,
                $request
            );
        }
    }

    private function notifyRejection(
        Leave $leave,
        string $comment,
        ?User $actor,
        ?Request $request
    ): void {
        $studentEmail = $this->resolveStudentEmail($leave);
        $leaveCode = $this->formatLeaveCode($leave->id);
        $studentName = $this->resolveStudentFullName($leave);

        if ($studentEmail !== '') {
            $this->sendLeaveEmail(
                $leave,
                'rejected_student',
                $studentEmail,
                'Congedo rifiutato',
                $this->composeLeaveEmailBody(
                    'La richiesta di congedo '.$leaveCode.' non e stata approvata.',
                    [
                        'Codice congedo' => $leaveCode,
                        'Motivazione del rifiuto' => $comment,
                    ],
                    'Per chiarimenti, contatta il capo laboratorio.'
                ),
                $actor,
                $request
            );
        }

        $guardianEmails = $this->resolveGuardianEmails($leave);

        foreach ($guardianEmails as $guardianEmail) {
            $this->sendLeaveEmail(
                $leave,
                'rejected_guardian',
                $guardianEmail,
                'Congedo rifiutato - notifica tutore',
                $this->composeLeaveEmailBody(
                    'La richiesta di congedo '.$leaveCode.' dello studente '.$studentName.' non e stata approvata.',
                    [
                        'Codice congedo' => $leaveCode,
                        'Studente' => $studentName,
                        'Motivazione del rifiuto' => $comment,
                    ],
                    'Se necessario, confrontati con lo studente e con il capo laboratorio.'
                ),
                $actor,
                $request
            );
        }
    }

    private function notifyForwardToManagement(
        Leave $leave,
        string $comment,
        ?User $actor,
        ?Request $request
    ): void {
        $studentEmail = $this->resolveStudentEmail($leave);
        $leaveCode = $this->formatLeaveCode($leave->id);
        $studentName = $this->resolveStudentFullName($leave);

        if ($studentEmail !== '') {
            $this->sendLeaveEmail(
                $leave,
                'forwarded_to_management_student',
                $studentEmail,
                'Congedo inoltrato in direzione',
                $this->composeLeaveEmailBody(
                    'La richiesta di congedo '.$leaveCode.' e stata inoltrata in direzione per valutazione.',
                    [
                        'Codice congedo' => $leaveCode,
                        'Motivazione inoltro' => $comment,
                    ],
                    'Per aggiornamenti, fai riferimento al capo laboratorio.'
                ),
                $actor,
                $request
            );
        }

        $guardianEmails = $this->resolveGuardianEmails($leave);
        foreach ($guardianEmails as $guardianEmail) {
            $this->sendLeaveEmail(
                $leave,
                'forwarded_to_management_guardian',
                $guardianEmail,
                'Congedo inoltrato in direzione - notifica tutore',
                $this->composeLeaveEmailBody(
                    'La richiesta di congedo '.$leaveCode.' dello studente '.$studentName.' e stata inoltrata in direzione.',
                    [
                        'Codice congedo' => $leaveCode,
                        'Studente' => $studentName,
                        'Motivazione inoltro' => $comment,
                    ],
                    'Per dettagli aggiuntivi contatta la scuola.'
                ),
                $actor,
                $request
            );
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function composeLeaveEmailBody(
        string $intro,
        array $details = [],
        string $closing = ''
    ): string {
        $lines = [trim($intro)];
        $detailLines = [];

        foreach ($details as $label => $value) {
            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $detailLines[] = '- '.trim((string) $label).': '.$normalizedValue;
        }

        if ($detailLines !== []) {
            $lines[] = '';
            $lines[] = 'Dettagli:';
            foreach ($detailLines as $detailLine) {
                $lines[] = $detailLine;
            }
        }

        $closing = trim($closing);
        if ($closing !== '') {
            $lines[] = '';
            $lines[] = $closing;
        }

        $lines[] = '';
        $lines[] = 'Cordiali saluti,';
        $lines[] = 'Gestione assenze';

        return implode("\n", $lines);
    }

    private function sendLeaveEmail(
        Leave $leave,
        string $type,
        string $recipientEmail,
        string $subject,
        string $body,
        ?User $actor,
        ?Request $request
    ): void {
        $recipientEmail = trim($recipientEmail);
        if ($recipientEmail === '') {
            return;
        }

        try {
            Mail::send('mail.leave-workflow-notification', [
                'subject' => $subject,
                'body' => $body,
            ], function ($message) use ($recipientEmail, $subject) {
                $message->to($recipientEmail)->subject($subject);
            });

            LeaveEmailNotification::create([
                'type' => $type,
                'recipient_email' => $recipientEmail,
                'subject' => $subject,
                'body' => $body,
                'leave_id' => $leave->id,
                'sent_at' => now(),
                'status' => 'sent',
            ]);
        } catch (\Throwable $exception) {
            LeaveEmailNotification::create([
                'type' => $type,
                'recipient_email' => $recipientEmail,
                'subject' => $subject,
                'body' => $body,
                'leave_id' => $leave->id,
                'status' => 'failed',
            ]);

            OperationLog::record(
                $actor,
                'leave.guardian_confirmation_email.failed',
                'leave',
                $leave->id,
                [
                    'guardian_email' => $recipientEmail,
                    'error' => $exception->getMessage(),
                    'type' => $type,
                ],
                'ERROR',
                $request
            );

            report($exception);
        }
    }

    private function getClassTeacherEmails(int $studentId): Collection
    {
        return User::query()
            ->select('users.email')
            ->join('class_teacher', 'class_teacher.teacher_id', '=', 'users.id')
            ->join('class_user', 'class_user.class_id', '=', 'class_teacher.class_id')
            ->where('class_user.user_id', $studentId)
            ->whereNotNull('users.email')
            ->distinct()
            ->pluck('users.email');
    }

    private function buildDetailItem(array $item, Leave $leave, array $studentClassMap, User $user): array
    {
        $firstGuardianSignature = $this->resolveFirstSignedGuardianConfirmation($leave);
        $guardianSignedAt = $firstGuardianSignature
            ? ($firstGuardianSignature->confirmed_at ?? $firstGuardianSignature->signed_at)
            : null;
        $requestedLessonsPayload = Leave::normalizeRequestedLessonsPayload($leave->requested_lessons);
        $requestedLessonsLabel = Leave::formatRequestedLessonsLabel(
            $requestedLessonsPayload,
            $leave->start_date?->toDateString(),
            $leave->end_date?->toDateString()
        );

        $item['classe'] = $studentClassMap[$item['student_id']] ?? '-';
        $item['start_date'] = $leave->start_date?->toDateString();
        $item['end_date'] = $leave->end_date?->toDateString();
        $item['hours'] = (int) $leave->requested_hours;
        $item['requested_hours'] = (int) $leave->requested_hours;
        $item['requested_lessons'] = $requestedLessonsPayload;
        $item['requested_lessons_label'] = $requestedLessonsLabel !== ''
            ? $requestedLessonsLabel
            : null;
        $item['motivation'] = (string) $leave->reason;
        $item['destination'] = (string) $leave->destination;
        $item['count_hours_comment'] = (string) $leave->count_hours_comment;
        $item['workflow_comment'] = (string) $leave->workflow_comment;
        $item['documentation_request_comment'] = (string) $leave->documentation_request_comment;
        $submittedAt = $leave->created_at_custom ?? $leave->created_at;
        $item['richiesta_inviata_il'] = $item['richiesta_inviata_il']
            ?? $submittedAt?->format('d M Y H:i');
        $item['richiesta_tardiva'] = (bool) ($item['richiesta_tardiva'] ?? false);
        $item['richiesta_tardiva_label'] = (string) ($item['richiesta_tardiva_label'] ?? '');
        $item['documentation'] = $leave->documentation_path ? [
            'filename' => basename((string) $leave->documentation_path),
            'uploaded_at' => $leave->documentation_uploaded_at?->format('d M Y H:i'),
            'viewer_url' => route('leaves.documentation.view', [
                'leave' => $leave->id,
            ]),
        ] : null;
        $item['guardian_signature'] = $firstGuardianSignature ? [
            'confirmation_id' => $firstGuardianSignature->id,
            'guardian_name' => $this->resolveSignerName($firstGuardianSignature),
            'signed_at' => $guardianSignedAt?->format('d M Y H:i'),
            'viewer_url' => route('leaves.guardian-signature.view', [
                'leave' => $leave->id,
            ]),
        ] : null;
        $item['registered_absence_url'] = $leave->registered_absence_id && $user->hasRole('teacher')
            ? route('teacher.absences.show', ['absence' => $leave->registered_absence_id])
            : null;
        $item['forwarding_pdf_url'] = route('leaves.forwarding-pdf.download', [
            'leave' => $leave->id,
        ]);
        $hoursLimitExceededAtRequest = (bool) ($leave->hours_limit_exceeded_at_request ?? false);
        $hoursLimitValueAtRequest = (int) ($leave->hours_limit_value_at_request ?? 0);
        $hoursLimitMaxAtRequest = (int) ($leave->hours_limit_max_at_request ?? 0);
        $item['hours_limit_warning'] = $hoursLimitExceededAtRequest ? [
            'show' => true,
            'title' => 'Studente oltre limite ore annuale',
            'message' => 'Al momento della richiesta congedo lo studente risultava oltre il limite ore '
                .$hoursLimitValueAtRequest.' / '.$hoursLimitMaxAtRequest.'.',
            'value_at_request' => $hoursLimitValueAtRequest,
            'max_at_request' => $hoursLimitMaxAtRequest,
        ] : [
            'show' => false,
            'value_at_request' => $hoursLimitValueAtRequest,
            'max_at_request' => $hoursLimitMaxAtRequest,
        ];

        return $item;
    }

    private function buildHistory(Leave $leave): Collection
    {
        return $leave->approvals
            ->sortByDesc(function (LeaveApproval $approval) {
                return ($approval->decided_at ?? $approval->created_at)?->timestamp ?? 0;
            })
            ->values()
            ->map(function (LeaveApproval $approval) {
                return [
                    'decision' => $approval->decision,
                    'label' => $this->resolveDecisionLabel($approval->decision),
                    'notes' => (string) $approval->notes,
                    'override_guardian_signature' => (bool) $approval->override_guardian_signature,
                    'decided_at' => ($approval->decided_at ?? $approval->created_at)?->format('d M Y H:i'),
                    'decided_by' => trim((string) $approval->decider?->name.' '.(string) $approval->decider?->surname),
                ];
            });
    }

    /**
     * @return array<int, string>
     */
    private function resolveAvailableActions(array $item): array
    {
        $actionFlags = [
            'pre_approve' => 'can_pre_approve',
            'approve' => 'can_approve',
            'reject' => 'can_reject',
            'forward_to_management' => 'can_forward_to_management',
            'documentation' => 'can_request_documentation',
            'reject_documentation' => 'can_reject_documentation',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
        ];

        $availableActions = [];
        foreach ($actionFlags as $action => $flag) {
            if (! empty($item[$flag])) {
                $availableActions[] = $action;
            }
        }

        return $availableActions;
    }

    /**
     * @param  array<int, string>  $availableActions
     */
    private function resolveInitialAction(string $requestedAction, array $availableActions): string
    {
        $allowedActions = [
            'pre_approve',
            'approve',
            'reject',
            'forward_to_management',
            'documentation',
            'reject_documentation',
            'edit',
            'delete',
        ];

        if (! in_array($requestedAction, $allowedActions, true)) {
            return '';
        }

        return in_array($requestedAction, $availableActions, true)
            ? $requestedAction
            : '';
    }

    private function requireLaboratoryManager(
        ?User $user,
        string $errorMessage = 'Solo il capo laboratorio puo modificare il congedo.'
    ): ?RedirectResponse {
        if ($user && $user->hasRole('laboratory_manager')) {
            return null;
        }

        return back()->withErrors([
            'leave' => $errorMessage,
        ]);
    }

    private function requireOpenLeaveStatus(Leave $leave, string $errorMessage): ?RedirectResponse
    {
        if (in_array(Leave::normalizeStatus($leave->status), Leave::openStatuses(), true)) {
            return null;
        }

        return back()->withErrors([
            'leave' => $errorMessage,
        ]);
    }

    private function normalizeComment(mixed $value): string
    {
        return trim((string) $value);
    }

    private function requireCommentWhenDocumentationMissing(Leave $leave, string $comment): ?RedirectResponse
    {
        if (! empty($leave->documentation_path) || $comment !== '') {
            return null;
        }

        return back()->withErrors([
            'comment' => 'Commento obbligatorio quando non e presente la documentazione.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0:bool,1:?string}
     */
    private function resolveCountHoursDecision(Leave $leave, array $validated, string $comment): array
    {
        $countHours = array_key_exists('count_hours', $validated)
            ? (bool) $validated['count_hours']
            : (bool) $leave->count_hours;
        $countHoursComment = $this->normalizeComment(
            $validated['count_hours_comment'] ?? $leave->count_hours_comment
        );

        if (! $countHours && $countHoursComment === '') {
            $countHoursComment = $comment !== ''
                ? $comment
                : AnnualHoursLimitLabels::labDecisionComment();
        }

        if ($countHours) {
            return [true, null];
        }

        return [false, $countHoursComment];
    }

    private function resolveStudentEmail(Leave $leave): string
    {
        return $this->normalizeComment($leave->student?->email);
    }

    private function resolveGuardianEmails(Leave $leave): Collection
    {
        return $leave->student?->guardians
            ?->pluck('email')
            ->filter(fn ($email) => filled(trim((string) $email)))
            ->unique()
            ->values()
            ?? collect();
    }

    private function resolveStudentFullName(Leave $leave): string
    {
        $fullName = trim((string) $leave->student?->name.' '.(string) $leave->student?->surname);

        return $fullName !== '' ? $fullName : '-';
    }

    private function formatLeaveCode(int $leaveId): string
    {
        return 'C-'.str_pad((string) $leaveId, 4, '0', STR_PAD_LEFT);
    }

    private function formatAbsenceCode(?int $absenceId): string
    {
        return 'A-'.str_pad((string) ((int) $absenceId), 4, '0', STR_PAD_LEFT);
    }

    private function isSignedGuardianConfirmation(GuardianLeaveConfirmation $confirmation): bool
    {
        $status = strtolower(trim((string) $confirmation->status));

        return in_array($status, ['confirmed', 'approved', 'signed'], true)
            || ! empty($confirmation->confirmed_at)
            || ! empty($confirmation->signed_at);
    }

    private function signedAtTimestamp(GuardianLeaveConfirmation $confirmation): int
    {
        $signedAt = $confirmation->confirmed_at ?? $confirmation->signed_at;

        return $signedAt?->timestamp ?? PHP_INT_MAX;
    }

    private function resolveDecisionLabel(string $decision): string
    {
        return match ($decision) {
            'pre_approved' => 'Override firma tutore',
            'approved' => 'Approvazione',
            'registered' => 'Registrazione finale',
            'rejected' => 'Rifiuto',
            'forwarded_to_management' => 'Inoltro in direzione',
            'documentation_requested' => 'Richiesta documentazione',
            'documentation_rejected' => 'Rifiuto documentazione',
            'updated' => 'Modifica congedo',
            'count_hours_excluded' => 'Esclusione dal limite ore annuale',
            'count_hours_included' => 'Inclusione nel limite ore annuale',
            default => ucfirst(str_replace('_', ' ', $decision)),
        };
    }
}
