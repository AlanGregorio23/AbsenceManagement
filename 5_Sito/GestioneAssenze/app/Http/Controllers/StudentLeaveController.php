<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveRequest;
use App\Http\Requests\UploadLeaveDocumentationRequest;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\AbsenceSetting;
use App\Models\Leave;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\LeaveGuardianSignatureService;
use App\Support\AnnualHoursLimitLabels;
use App\Support\StudentArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class StudentLeaveController extends BaseController
{
    public function __construct()
    {
        $this->middleware('student');
    }

    public function create()
    {
        $user = auth()->user();

        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $leaveNoticeWorkingHours = Leave::requestNoticeWorkingHours($absenceSetting);
        $maxAnnualHours = (int) $absenceSetting->max_annual_hours;
        $absenceHoursOn40 = Absence::countHoursForStudent((int) $user->id);
        $laboratoryManagerEmails = User::query()
            ->where('role', 'laboratory_manager')
            ->where('active', true)
            ->whereNotNull('email')
            ->orderBy('surname')
            ->orderBy('name')
            ->pluck('email')
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();
        $reasons = AbsenceReason::query()
            ->orderBy('name')
            ->get()
            ->map(fn (AbsenceReason $reason) => [
                'id' => $reason->id,
                'name' => $reason->name,
                'counts_40_hours' => (bool) $reason->counts_40_hours,
                'requires_management_consent' => (bool) $reason->requires_management_consent,
                'requires_document_on_leave_creation' => (bool) $reason->requires_document_on_leave_creation,
                'management_consent_note' => $reason->management_consent_note,
            ])
            ->values();

        return Inertia::render('Student/LeaveCreate', [
            'settings' => [
                'max_annual_hours' => $maxAnnualHours,
                'hours_used_on_40' => $absenceHoursOn40,
                'available_hours' => max($maxAnnualHours - $absenceHoursOn40, 0),
                'leave_request_notice_working_hours' => $leaveNoticeWorkingHours,
            ],
            'reasons' => $reasons,
            'lessonSlots' => collect(Leave::lessonSlots())
                ->map(fn (string $timeRange, int $period) => [
                    'period' => $period,
                    'time_range' => $timeRange,
                ])
                ->values(),
            'contacts' => [
                'laboratory_manager_emails' => $laboratoryManagerEmails,
            ],
        ]);
    }

    public function store(LeaveRequest $request, LeaveGuardianSignatureService $signatureService)
    {
        $user = $request->user();
        $validated = $request->validated();
        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $hoursOn40AtRequest = Absence::countHoursForStudent((int) $user->id);
        $maxAnnualHours = max((int) $absenceSetting->max_annual_hours, 0);
        $hoursLimitExceededAtRequest = $maxAnnualHours > 0
            && $hoursOn40AtRequest > $maxAnnualHours;
        $viceDirectorEmail = trim((string) ($absenceSetting->vice_director_email ?? ''));

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'] ?? $validated['start_date'])->startOfDay();
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy();
        }

        $motivation = trim((string) $validated['motivation']);
        $reasonChoice = trim((string) ($validated['reason_choice'] ?? $motivation));
        $destination = trim((string) $validated['destination']);
        $countHours = true;
        $countHoursComment = null;
        $startLessons = Leave::normalizeLessonPeriods($validated['lessons_start'] ?? []);
        $endLessons = Leave::normalizeLessonPeriods($validated['lessons_end'] ?? []);
        $hasRequestedLessons = $startLessons !== [] || $endLessons !== [];

        if ($startLessons === [] && $endLessons !== []) {
            $startLessons = $endLessons;
        }
        if ($endLessons === []) {
            $endLessons = $startLessons;
        }

        $requestedHours = $hasRequestedLessons
            ? $this->estimateRequestedHoursFromLessons($startDate, $endDate, $startLessons, $endLessons)
            : (int) ($validated['hours'] ?? 1);
        $requestedLessonsPayload = $hasRequestedLessons
            ? json_encode([
                'start' => $startLessons,
                'end' => $endLessons,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if (strtolower($reasonChoice) !== 'altro') {
            $reasonRule = AbsenceReason::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($reasonChoice)])
                ->first();
            $countHours = $reasonRule ? (bool) $reasonRule->counts_40_hours : true;
            $countHoursComment = $countHours
                ? null
                : AnnualHoursLimitLabels::ruleReasonComment($absenceSetting, false);
        }

        $leave = Leave::create([
            'student_id' => $user->id,
            'created_by' => $user->id,
            'created_at_custom' => now(),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'requested_hours' => max($requestedHours, 1),
            'hours_limit_exceeded_at_request' => $hoursLimitExceededAtRequest,
            'hours_limit_value_at_request' => $hoursOn40AtRequest,
            'hours_limit_max_at_request' => $maxAnnualHours,
            'requested_lessons' => $requestedLessonsPayload,
            'reason' => $motivation,
            'destination' => $destination,
            'status' => Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE,
            'approved_without_guardian' => false,
            'count_hours' => $countHours,
            'count_hours_comment' => $countHoursComment,
            'documentation_path' => null,
            'documentation_uploaded_at' => null,
            'workflow_comment' => null,
            'hours_decision_at' => null,
            'hours_decision_by' => null,
        ]);

        if ($request->hasFile('document')) {
            $documentationPath = StudentArchivePathBuilder::storeUploadedFileForStudent(
                $request->file('document'),
                $user,
                StudentArchivePathBuilder::CATEGORY_SLIPS,
                [
                    'context' => 'talloncino_congedo',
                    'code' => 'c'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
                ]
            );

            $leave->update([
                'documentation_path' => $documentationPath,
                'documentation_uploaded_at' => now(),
                'workflow_comment' => 'Documentazione allegata in fase di creazione richiesta.',
            ]);
        }

        OperationLog::record(
            $user,
            'leave.request.created',
            'leave',
            $leave->id,
            [
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'requested_hours' => $leave->requested_hours,
                'requested_lessons' => Leave::normalizeRequestedLessonsPayload($leave->requested_lessons),
                'reason' => $leave->reason,
                'destination' => $leave->destination,
                'status' => $leave->status,
                'count_hours' => (bool) $leave->count_hours,
                'count_hours_comment' => $leave->count_hours_comment,
                'documentation_path' => $leave->documentation_path,
                'hours_limit_exceeded_at_request' => $hoursLimitExceededAtRequest,
                'hours_limit_value_at_request' => $hoursOn40AtRequest,
                'hours_limit_max_at_request' => $maxAnnualHours,
            ],
            'INFO',
            $request
        );

        if ($hoursLimitExceededAtRequest && $viceDirectorEmail !== '') {
            $this->notifyViceDirectorAboutExceededHours(
                $leave,
                $viceDirectorEmail,
                $user,
                $hoursOn40AtRequest,
                $maxAnnualHours
            );
        }

        $emailSummary = $signatureService->sendConfirmationEmails(
            $leave,
            $user,
            false,
            $request
        );

        $successMessage = 'Richiesta congedo inviata. Stato iniziale: In attesa firma tutore.';
        if ($emailSummary['guardians'] === 0) {
            $successMessage .= ' Nessun tutore associato: contattare la segreteria.';
        } elseif ($emailSummary['sent'] > 0) {
            $successMessage .= ' Email di firma inviata a '.$emailSummary['sent'].' tutore/i.';
        } elseif ($emailSummary['failed'] > 0) {
            $successMessage .= ' Invio email non riuscito: riprovare o contattare la scuola.';
        }

        if ($hoursLimitExceededAtRequest) {
            $successMessage .= ' Avviso: hai gia superato il limite ore annuale configurato.';
        }

        return back()->with('success', $successMessage);
    }

    public function uploadDocumentation(UploadLeaveDocumentationRequest $request, Leave $leave)
    {
        $leave->refresh();

        if ($leave->student_id !== $request->user()->id) {
            abort(403);
        }

        $statusCode = Leave::normalizeStatus($leave->status);
        if (! in_array($statusCode, Leave::openStatuses(), true)) {
            return back()->withErrors([
                'document' => 'La documentazione puo essere caricata solo su congedi aperti.',
            ]);
        }

        $previousPath = (string) ($leave->documentation_path ?? '');
        $filePath = StudentArchivePathBuilder::storeUploadedFileForStudent(
            $request->file('document'),
            $request->user(),
            StudentArchivePathBuilder::CATEGORY_SLIPS,
            [
                'context' => 'talloncino_congedo',
                'code' => 'c'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
            ]
        );
        if ($previousPath !== '') {
            $disk = Storage::disk(config('filesystems.default', 'local'));
            if ($disk->exists($previousPath)) {
                $disk->delete($previousPath);
            }
        }

        $nextStatus = $statusCode === Leave::STATUS_DOCUMENTATION_REQUESTED
            ? Leave::STATUS_IN_REVIEW
            : $statusCode;

        $leave->update([
            'documentation_path' => $filePath,
            'documentation_uploaded_at' => now(),
            'status' => $nextStatus,
            'workflow_comment' => $statusCode === Leave::STATUS_DOCUMENTATION_REQUESTED
                ? 'Documentazione caricata dallo studente e inviata in valutazione.'
                : 'Documentazione caricata dallo studente.',
        ]);

        OperationLog::record(
            $request->user(),
            'leave.documentation.uploaded',
            'leave',
            $leave->id,
            [
                'documentation_path' => $filePath,
                'documentation_uploaded_at' => $leave->documentation_uploaded_at?->toIso8601String(),
                'status' => $leave->status,
            ],
            'INFO',
            $request
        );

        $success = $statusCode === Leave::STATUS_DOCUMENTATION_REQUESTED
            ? 'Documentazione congedo caricata. Stato aggiornato a In valutazione.'
            : 'Documentazione congedo caricata.';

        return back()->with('success', $success);
    }

    /**
     * @param  array<int,int>  $startLessons
     * @param  array<int,int>  $endLessons
     */
    private function estimateRequestedHoursFromLessons(
        Carbon $startDate,
        Carbon $endDate,
        array $startLessons,
        array $endLessons
    ): int {
        $days = max($startDate->diffInDays($endDate) + 1, 1);
        $startCount = count($startLessons);
        $endCount = count($endLessons);

        if ($days === 1) {
            return max($startCount, 1);
        }

        $middleDays = max($days - 2, 0);
        $maxLessonsPerDay = count(Leave::lessonSlots());

        return max($startCount + $endCount + ($middleDays * $maxLessonsPerDay), 1);
    }

    private function notifyViceDirectorAboutExceededHours(
        Leave $leave,
        string $recipientEmail,
        $student,
        int $hoursOn40AtRequest,
        int $maxAnnualHours
    ): bool {
        $studentName = trim((string) $student->name.' '.(string) $student->surname);
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $leaveCode = 'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT);
        $subject = 'Informativa congedo oltre limite ore - '.$studentName;
        $body = implode("\n", [
            'Lo studente '.$studentName.' ha inoltrato una richiesta di congedo ai capi laboratorio.',
            'Codice congedo: '.$leaveCode,
            'Periodo: '.$leave->start_date?->format('d/m/Y').' - '.$leave->end_date?->format('d/m/Y'),
            'Ore congedo richieste: '.(int) ($leave->requested_hours ?? 0),
            'Monte ore gia conteggiato: '.$hoursOn40AtRequest.' / '.$maxAnnualHours,
            'Motivo: '.(trim((string) $leave->reason) !== '' ? trim((string) $leave->reason) : '-'),
        ]);

        try {
            Mail::send('mail.leave-workflow-notification', [
                'subject' => $subject,
                'body' => $body,
            ], function ($message) use ($recipientEmail, $subject) {
                $message->to($recipientEmail)->subject($subject);
            });

            return true;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
