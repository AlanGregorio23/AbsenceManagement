<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaboratoryManagerLeaveRequest;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\AbsenceSetting;
use App\Models\Leave;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\LeaveGuardianSignatureService;
use App\Support\AnnualHoursLimitLabels;
use App\Support\StudentArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class LaboratoryManagerLeaveController extends BaseController
{
    public function __construct()
    {
        $this->middleware('laboratory_manager');
    }

    public function create()
    {
        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $maxAnnualHours = (int) $absenceSetting->max_annual_hours;
        $leaveNoticeWorkingHours = Leave::requestNoticeWorkingHours($absenceSetting);
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
        $students = User::query()
            ->where('role', 'student')
            ->where('active', true)
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'email']);

        $classMap = $this->buildStudentClassMap();
        $studentsPayload = $students->map(function (User $student) use ($classMap, $maxAnnualHours) {
            $hoursOn40 = Absence::countHoursForStudent((int) $student->id);

            return [
                'id' => $student->id,
                'name' => trim((string) $student->name.' '.(string) $student->surname),
                'email' => $student->email,
                'class' => $classMap[$student->id] ?? '-',
                'hours_used_on_40' => $hoursOn40,
                'available_hours' => max($maxAnnualHours - $hoursOn40, 0),
            ];
        })->values();

        return Inertia::render('LaboratoryManager/LeaveCreate', [
            'settings' => [
                'max_annual_hours' => $maxAnnualHours,
                'leave_request_notice_working_hours' => $leaveNoticeWorkingHours,
            ],
            'reasons' => $reasons,
            'students' => $studentsPayload,
            'lessonSlots' => collect(Leave::lessonSlots())
                ->map(fn (string $timeRange, int $period) => [
                    'period' => $period,
                    'time_range' => $timeRange,
                ])
                ->values(),
        ]);
    }

    public function store(
        LaboratoryManagerLeaveRequest $request,
        LeaveGuardianSignatureService $signatureService
    ) {
        $actor = $request->user();
        $validated = $request->validated();
        $student = User::query()
            ->where('role', 'student')
            ->where('active', true)
            ->findOrFail((int) $validated['student_id']);
        $absenceSetting = AbsenceSetting::query()->firstOrFail();
        $hoursOn40AtRequest = Absence::countHoursForStudent((int) $student->id);
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

        $leave = Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $actor->id,
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
            'workflow_comment' => 'Congedo creato da capo laboratorio.',
            'hours_decision_at' => null,
            'hours_decision_by' => null,
        ]);

        if ($request->hasFile('document')) {
            $documentationPath = StudentArchivePathBuilder::storeUploadedFileForStudent(
                $request->file('document'),
                $student,
                StudentArchivePathBuilder::CATEGORY_SLIPS,
                [
                    'context' => 'talloncino_congedo',
                    'code' => 'c'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
                ]
            );

            $leave->update([
                'documentation_path' => $documentationPath,
                'documentation_uploaded_at' => now(),
                'workflow_comment' => 'Congedo creato da capo laboratorio con documentazione allegata.',
            ]);
        }

        OperationLog::record(
            $actor,
            'leave.request.created_by_laboratory_manager',
            'leave',
            $leave->id,
            [
                'student_id' => $student->id,
                'created_by' => $actor->id,
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
                $student,
                $hoursOn40AtRequest,
                $maxAnnualHours
            );
        }

        $emailSummary = $signatureService->sendConfirmationEmails(
            $leave,
            $actor,
            false,
            $request
        );

        $studentName = trim((string) $student->name.' '.(string) $student->surname);
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $successMessage = 'Richiesta congedo creata per '.$studentName.'.';
        if ($emailSummary['guardians'] === 0) {
            $successMessage .= ' Nessun tutore associato: contattare la segreteria.';
        } elseif ($emailSummary['sent'] > 0) {
            $successMessage .= ' Email di firma inviata a '.$emailSummary['sent'].' tutore/i.';
        } elseif ($emailSummary['failed'] > 0) {
            $successMessage .= ' Invio email non riuscito: riprovare o contattare la scuola.';
        }

        if ($hoursLimitExceededAtRequest) {
            $successMessage .= ' Avviso: lo studente risulta gia oltre il limite ore annuale configurato.';
        }

        return redirect()->route('lab.leaves')->with('success', $successMessage);
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
        User $student,
        int $hoursOn40AtRequest,
        int $maxAnnualHours
    ): bool {
        $studentName = trim((string) $student->name.' '.(string) $student->surname);
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $leaveCode = 'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT);
        $subject = 'Informativa congedo oltre limite ore - '.$studentName;
        $body = implode("\n", [
            'Il capo laboratorio ha creato una richiesta di congedo per '.$studentName.'.',
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

    /**
     * @return array<int,string>
     */
    private function buildStudentClassMap(): array
    {
        $classes = SchoolClass::query()
            ->with(['students' => fn ($query) => $query->select('users.id')])
            ->get();
        $map = [];

        foreach ($classes as $class) {
            $label = trim((string) $class->name);
            if ($label === '') {
                continue;
            }

            foreach ($class->students as $student) {
                $map[$student->id][] = $label;
            }
        }

        return collect($map)
            ->map(fn (array $labels) => implode(', ', array_unique($labels)))
            ->all();
    }
}
