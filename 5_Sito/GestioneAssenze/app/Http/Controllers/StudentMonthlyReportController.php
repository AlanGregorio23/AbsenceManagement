<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadMonthlyReportSignedRequest;
use App\Models\MonthlyReport;
use App\Models\OperationLog;
use App\Models\User;
use App\Services\MonthlyReportPdfService;
use App\Services\MonthlyReportService;
use App\Support\MonthlyReportArchivePathBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class StudentMonthlyReportController extends BaseController
{
    public function __construct()
    {
        $this->middleware('student');
    }

    public function index(Request $request)
    {
        $student = $request->user();

        $reports = MonthlyReport::query()
            ->where('student_id', $student->id)
            ->with('schoolClass')
            ->orderByDesc('report_month')
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

        return Inertia::render('Student/MonthlyReports', [
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    public function downloadOriginal(
        Request $request,
        MonthlyReport $monthlyReport,
        MonthlyReportPdfService $pdfService
    ) {
        $student = $request->user();
        $report = $this->resolveOwnedReport($student, $monthlyReport->id)
            ->loadMissing('schoolClass');
        $path = MonthlyReportArchivePathBuilder::normalizePath((string) $report->system_pdf_path);
        $disk = Storage::disk('local');
        $shouldRegenerate = $path === '';

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

        if ($shouldRegenerate) {
            $summary = is_array($report->summary_json) ? $report->summary_json : [];
            $path = $pdfService->generate($report, $student, $report->schoolClass, $summary);
            $report->system_pdf_path = $path;
            $report->save();
        }

        if ($path === '' || ! $disk->exists($path)) {
            abort(404, 'Report non disponibile.');
        }

        OperationLog::record(
            $student,
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
        $student = $request->user();
        $report = $this->resolveOwnedReport($student, $monthlyReport->id)
            ->loadMissing('student');
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
            $student,
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

    public function uploadSigned(
        UploadMonthlyReportSignedRequest $request,
        MonthlyReport $monthlyReport,
        MonthlyReportService $service
    ) {
        $student = $request->user();
        $report = $this->resolveOwnedReport($student, $monthlyReport->id);
        $status = MonthlyReport::normalizeStatus($report->status);

        if ($status === MonthlyReport::STATUS_APPROVED) {
            return back()->withErrors([
                'document' => 'Il report e gia approvato e archiviato.',
            ]);
        }

        $service->uploadSignedReport($report, $request->file('document'), $student, $request);

        return back()->with('success', 'Report firmato caricato correttamente.');
    }

    private function resolveOwnedReport(User $student, int $reportId): MonthlyReport
    {
        return MonthlyReport::query()
            ->whereKey($reportId)
            ->where('student_id', $student->id)
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
        $bucket = MonthlyReport::bucketForStatus($report->status);
        $status = MonthlyReport::normalizeStatus($report->status);

        return [
            'report_id' => $report->id,
            'code' => $report->reportCode(),
            'month' => $report->monthLabel(),
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
            'original_download_url' => filled($report->system_pdf_path)
                ? route('student.monthly-reports.download', $report)
                : null,
            'signed_download_url' => filled($report->signed_pdf_path)
                ? route('student.monthly-reports.download-signed', $report)
                : null,
            'can_upload_signed' => in_array(
                $status,
                [
                    MonthlyReport::STATUS_GENERATED,
                    MonthlyReport::STATUS_SENT,
                    MonthlyReport::STATUS_SIGNED_UPLOADED,
                ],
                true
            ),
            'is_completed' => $status === MonthlyReport::STATUS_APPROVED,
        ];
    }
}
