<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeacherRequiredCommentRequest;
use App\Models\MonthlyReport;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MonthlyReportPdfService;
use App\Services\MonthlyReportService;
use App\Support\MonthlyReportArchivePathBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TeacherMonthlyReportController extends BaseController
{
    public function __construct()
    {
        $this->middleware('teacher');
    }

    public function index(Request $request)
    {
        $teacher = $request->user();

        $reports = MonthlyReport::query()
            ->whereIn('student_id', function ($subQuery) use ($teacher) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $teacher->id);
            })
            ->with(['student.classes', 'schoolClass', 'approver', 'rejecter'])
            ->orderByDesc('report_month')
            ->orderByDesc('id')
            ->get();

        $items = $reports
            ->map(fn (MonthlyReport $report) => $this->mapReport($report))
            ->values();

        $stats = [
            'missing' => $items->where('bucket', 'missing')->count(),
            'pending' => $items->where('bucket', 'pending')->count(),
            'completed' => $items->where('bucket', 'completed')->count(),
            'total' => $items->count(),
        ];

        return Inertia::render('Teacher/MonthlyReports', [
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, MonthlyReport $monthlyReport)
    {
        $teacher = $request->user();
        $report = $this->resolveTeacherReport($teacher, $monthlyReport->id);

        $item = $this->mapReport($report);
        $history = OperationLog::query()
            ->with(['user:id,name,surname'])
            ->where('entity', 'monthly_report')
            ->where('entity_id', $report->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (OperationLog $log) {
                $payload = is_array($log->payload) ? $log->payload : [];

                return [
                    'action' => (string) $log->action,
                    'label' => $this->resolveMonthlyReportOperationLabel((string) $log->action),
                    'notes' => $this->resolveMonthlyReportOperationNotes($payload),
                    'decided_at' => $log->created_at?->format('d M Y H:i'),
                    'decided_by' => $this->resolveOperationActor($log->user),
                ];
            })
            ->values();

        return Inertia::render('Teacher/MonthlyReportDetail', [
            'item' => $item,
            'history' => $history,
        ]);
    }

    public function downloadOriginal(
        Request $request,
        MonthlyReport $monthlyReport,
        MonthlyReportPdfService $pdfService
    ) {
        $teacher = $request->user();
        $report = $this->resolveTeacherReport($teacher, $monthlyReport->id)
            ->loadMissing(['schoolClass', 'student.classes']);
        $resolvedClass = $this->resolveReportClass($report);
        $path = MonthlyReportArchivePathBuilder::normalizePath((string) $report->system_pdf_path);
        $disk = Storage::disk('local');
        $shouldRegenerate = $path === '';

        if (! $report->class_id && $resolvedClass) {
            $report->class_id = $resolvedClass->id;
            $report->setRelation('schoolClass', $resolvedClass);
            $report->save();
            $shouldRegenerate = true;
        }

        if (! $shouldRegenerate && ! MonthlyReportArchivePathBuilder::isArchivePath($path)) {
            $shouldRegenerate = true;
        }

        if (! $shouldRegenerate && ! $disk->exists($path)) {
            $shouldRegenerate = true;
        }

        if (! $shouldRegenerate) {
            $currentContent = (string) $disk->get($path);
            $shouldRegenerate = ! $this->isLikelyReadablePdf($currentContent);
        }

        if ($shouldRegenerate && $report->student) {
            $summary = is_array($report->summary_json) ? $report->summary_json : [];
            $path = $pdfService->generate($report, $report->student, $resolvedClass, $summary);
            $report->system_pdf_path = $path;
            $report->save();
        }

        if ($path === '' || ! $disk->exists($path)) {
            abort(404, 'Report non disponibile.');
        }

        OperationLog::record(
            $teacher,
            'monthly_report.downloaded',
            'monthly_report',
            $report->id,
            [
                'file_path' => $path,
                'student_id' => $report->student_id,
            ],
            'INFO',
            $request
        );

        return $disk->response($path, basename($path));
    }

    public function downloadSigned(
        Request $request,
        MonthlyReport $monthlyReport,
        MonthlyReportService $service
    ) {
        $teacher = $request->user();
        $report = $this->resolveTeacherReport($teacher, $monthlyReport->id);
        $report = $service->migrateSignedReportPathToArchive($report);
        $path = MonthlyReportArchivePathBuilder::normalizePath((string) $report->signed_pdf_path);

        if ($path === '') {
            abort(404, 'Report firmato non disponibile.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            abort(404, 'File firmato non trovato.');
        }

        OperationLog::record(
            $teacher,
            'monthly_report.signed.downloaded',
            'monthly_report',
            $report->id,
            [
                'file_path' => $path,
                'student_id' => $report->student_id,
            ],
            'INFO',
            $request
        );

        return $disk->response($path, basename($path));
    }

    public function resendEmail(
        Request $request,
        MonthlyReport $monthlyReport,
        MonthlyReportService $service
    ) {
        $teacher = $request->user();
        $report = $this->resolveTeacherReport($teacher, $monthlyReport->id);
        $status = MonthlyReport::normalizeStatus($report->status);

        if (in_array($status, [MonthlyReport::STATUS_APPROVED, MonthlyReport::STATUS_REJECTED], true)) {
            return back()->withErrors([
                'report' => 'Reinvio email non consentito per lo stato attuale del report.',
            ]);
        }

        $summary = $service->sendReportEmails($report, $teacher, true, $request);

        if ($summary['recipients'] === 0) {
            return back()->withErrors([
                'report' => 'Nessun destinatario disponibile per il reinvio.',
            ]);
        }

        if ($summary['sent'] === 0 && $summary['failed'] > 0) {
            return back()->withErrors([
                'report' => 'Reinvio non riuscito. Riprova.',
            ]);
        }

        return back()->with(
            'success',
            'Reinvio completato: '.$summary['sent'].' email inviate.'
        );
    }

    public function approve(
        Request $request,
        MonthlyReport $monthlyReport,
        MonthlyReportService $service
    ) {
        $teacher = $request->user();
        $report = $this->resolveTeacherReport($teacher, $monthlyReport->id);
        $status = MonthlyReport::normalizeStatus($report->status);

        if ($status !== MonthlyReport::STATUS_SIGNED_UPLOADED) {
            return back()->withErrors([
                'report' => 'Puoi approvare solo report con file firmato gia caricato.',
            ]);
        }

        $service->approveReport($report, $teacher, $request);

        return back()->with('success', 'Report approvato e archiviato.');
    }

    public function reject(
        TeacherRequiredCommentRequest $request,
        MonthlyReport $monthlyReport,
        MonthlyReportService $service
    ) {
        $teacher = $request->user();
        $report = $this->resolveTeacherReport($teacher, $monthlyReport->id);
        $status = MonthlyReport::normalizeStatus($report->status);

        if ($status !== MonthlyReport::STATUS_SIGNED_UPLOADED) {
            return back()->withErrors([
                'report' => 'Puoi rifiutare solo report con file firmato gia caricato.',
            ]);
        }

        $service->rejectReport(
            $report,
            $teacher,
            (string) $request->validated('comment'),
            $request
        );

        return back()->with('success', 'Report rifiutato. Lo studente puo ricaricare il file firmato.');
    }

    private function resolveTeacherReport(User $teacher, int $reportId): MonthlyReport
    {
        return MonthlyReport::query()
            ->whereKey($reportId)
            ->whereIn('student_id', function ($subQuery) use ($teacher) {
                $subQuery
                    ->select('class_user.user_id')
                    ->from('class_user')
                    ->join('class_teacher', 'class_teacher.class_id', '=', 'class_user.class_id')
                    ->where('class_teacher.teacher_id', $teacher->id);
            })
            ->with(['student.classes', 'schoolClass', 'approver', 'rejecter'])
            ->firstOrFail();
    }

    private function isLikelyReadablePdf(string $content): bool
    {
        return str_starts_with($content, '%PDF-')
            && str_contains($content, '/Type /Page')
            && str_contains($content, '/Type /Pages')
            && str_contains($content, 'xref')
            && str_contains($content, '%%EOF');
    }

    /**
     * @return array<string,mixed>
     */
    private function mapReport(MonthlyReport $report): array
    {
        $summary = is_array($report->summary_json) ? $report->summary_json : [];
        $status = MonthlyReport::normalizeStatus($report->status);
        $bucket = MonthlyReport::bucketForStatus($status);
        $studentName = trim((string) ($report->student?->name ?? '').' '.(string) ($report->student?->surname ?? ''));
        $classLabel = $this->resolveClassLabel($report);

        return [
            'report_id' => $report->id,
            'code' => $report->reportCode(),
            'month' => $report->monthLabel(),
            'student_id' => $report->student_id,
            'student_name' => $studentName !== '' ? $studentName : '-',
            'class_label' => $classLabel,
            'status_code' => $status,
            'status_label' => MonthlyReport::statusLabel($status),
            'badge' => MonthlyReport::statusBadge($status),
            'bucket' => $bucket,
            'absence_hours' => (int) ($summary['absence_hours'] ?? 0),
            'delay_count' => (int) ($summary['delay_count'] ?? 0),
            'leave_count' => (int) ($summary['leave_count'] ?? 0),
            'missing_certificates' => (int) ($summary['medical_certificates']['missing'] ?? 0),
            'generated_at' => $report->generated_at?->format('d M Y H:i'),
            'sent_at' => $report->last_sent_at?->format('d M Y H:i'),
            'signed_uploaded_at' => $report->signed_uploaded_at?->format('d M Y H:i'),
            'approved_at' => $report->approved_at?->format('d M Y H:i'),
            'approved_by' => $report->approver
                ? trim((string) ($report->approver->name ?? '').' '.(string) ($report->approver->surname ?? ''))
                : null,
            'rejected_at' => $report->rejected_at?->format('d M Y H:i'),
            'rejected_by' => $report->rejecter
                ? trim((string) ($report->rejecter->name ?? '').' '.(string) ($report->rejecter->surname ?? ''))
                : null,
            'rejection_comment' => (string) ($report->rejection_comment ?? ''),
            'original_download_url' => filled($report->system_pdf_path)
                ? route('teacher.monthly-reports.download', $report)
                : null,
            'signed_download_url' => filled($report->signed_pdf_path)
                ? route('teacher.monthly-reports.download-signed', $report)
                : null,
            'details_url' => route('teacher.monthly-reports.show', $report),
            'can_resend_email' => filled($report->system_pdf_path)
                && ! in_array(
                    $status,
                    [MonthlyReport::STATUS_APPROVED, MonthlyReport::STATUS_REJECTED],
                    true
                ),
            'can_approve' => $status === MonthlyReport::STATUS_SIGNED_UPLOADED,
            'can_reject' => $status === MonthlyReport::STATUS_SIGNED_UPLOADED,
        ];
    }

    private function resolveClassLabel(MonthlyReport $report): string
    {
        $schoolClass = $this->resolveReportClass($report);

        if (! $schoolClass) {
            return '-';
        }

        $compactLabel = trim((string) $schoolClass->year.(string) $schoolClass->section);

        return $compactLabel !== '' ? $compactLabel : ((string) $schoolClass->name ?: '-');
    }

    private function resolveReportClass(MonthlyReport $report): ?SchoolClass
    {
        $schoolClass = $report->schoolClass;

        if ($schoolClass || ! $report->student) {
            return $schoolClass;
        }

        $classes = $report->student->classes;

        if ($report->report_month) {
            $monthStart = $report->report_month->copy()->startOfMonth();
            $monthEnd = $report->report_month->copy()->endOfMonth();

            $schoolClass = $classes
                ->first(function (SchoolClass $class) use ($monthStart, $monthEnd) {
                    $start = $class->pivot?->start_date;
                    $end = $class->pivot?->end_date;

                    return (! $start || $start <= $monthEnd->toDateString())
                        && (! $end || $end >= $monthStart->toDateString());
                });
        }

        return $schoolClass
            ?: $classes
                ->sortByDesc(fn (SchoolClass $class) => $class->pivot?->start_date)
                ->first();
    }

    private function resolveMonthlyReportOperationLabel(string $action): string
    {
        return match ($action) {
            'monthly_report.generated' => 'Generazione report mensile',
            'monthly_report.generation.failed' => 'Errore generazione report mensile',
            'monthly_report.email.sent' => 'Invio email report mensile',
            'monthly_report.email.resent' => 'Reinvio email report mensile',
            'monthly_report.email.failed' => 'Errore invio email report mensile',
            'monthly_report.signed_uploaded' => 'Upload report firmato',
            'monthly_report.approved' => 'Approvazione report',
            'monthly_report.rejected' => 'Rifiuto report',
            'monthly_report.downloaded' => 'Download report originale',
            'monthly_report.signed.downloaded' => 'Download report firmato',
            default => ucfirst(str_replace(['.', '_'], ' ', strtolower($action))),
        };
    }

    private function resolveMonthlyReportOperationNotes(array $payload): string
    {
        $comment = trim((string) ($payload['comment'] ?? ''));
        if ($comment !== '') {
            return $comment;
        }

        $parts = [];

        if (array_key_exists('recipients', $payload)) {
            $parts[] = 'Destinatari: '.(int) $payload['recipients'];
        }
        if (array_key_exists('sent', $payload)) {
            $parts[] = 'Inviate: '.(int) $payload['sent'];
        }
        if (array_key_exists('failed', $payload)) {
            $parts[] = 'Fallite: '.(int) $payload['failed'];
        }

        $recipientEmail = trim((string) ($payload['recipient_email'] ?? ''));
        if ($recipientEmail !== '') {
            $parts[] = 'Destinatario: '.$recipientEmail;
        }

        $error = trim((string) ($payload['error'] ?? ''));
        if ($error !== '') {
            $parts[] = 'Errore: '.$error;
        }

        return implode(' | ', $parts);
    }

    private function resolveOperationActor(?User $user): string
    {
        if (! $user) {
            return 'Sistema';
        }

        $fullName = trim((string) ($user->name ?? '').' '.(string) ($user->surname ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        return trim((string) ($user->name ?? 'Utente'));
    }
}
