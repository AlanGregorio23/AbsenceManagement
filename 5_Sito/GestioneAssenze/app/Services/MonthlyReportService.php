<?php

namespace App\Services;

use App\Jobs\Mail\MonthlyReportMail;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportEmailNotification;
use App\Models\OperationLog;
use App\Models\User;
use App\Support\MonthlyReportArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MonthlyReportService
{
    public function __construct(
        private readonly MonthlyReportDataService $dataService,
        private readonly MonthlyReportPdfService $pdfService
    ) {}

    /**
     * @return array{students:int,month:string}
     */
    public function queueGenerationForMonth(Carbon $month, ?int $actorUserId = null): array
    {
        $students = User::query()
            ->where('role', 'student')
            ->where('active', true)
            ->pluck('id');

        foreach ($students as $studentId) {
            \App\Jobs\GenerateMonthlyReportForStudentJob::dispatch(
                (int) $studentId,
                $month->copy()->startOfMonth()->toDateString(),
                $actorUserId
            );
        }

        return [
            'students' => (int) $students->count(),
            'month' => $month->copy()->startOfMonth()->toDateString(),
        ];
    }

    public function generateAndSendForStudent(
        int $studentId,
        Carbon $month,
        ?User $actor = null
    ): ?MonthlyReport {
        $student = User::query()
            ->whereKey($studentId)
            ->where('role', 'student')
            ->first();

        if (! $student) {
            return null;
        }

        $monthStart = $month->copy()->startOfMonth();

        try {
            $buildResult = $this->dataService->build($student, $monthStart);
            $summary = $buildResult['summary'];
            $class = $buildResult['class'];

            $result = DB::transaction(function () use (
                $student,
                $monthStart,
                $summary,
                $class,
                $actor
            ) {
                $monthlyReport = MonthlyReport::query()
                    ->where('student_id', $student->id)
                    ->whereDate('report_month', $monthStart->toDateString())
                    ->lockForUpdate()
                    ->first();

                if (! $monthlyReport) {
                    $monthlyReport = new MonthlyReport([
                        'student_id' => $student->id,
                        'report_month' => $monthStart->toDateString(),
                        'created_by' => $actor?->id,
                    ]);
                }

                $existingSummary = is_array($monthlyReport->summary_json)
                    ? $monthlyReport->summary_json
                    : [];
                $summaryForComparison = $summary;
                $existingSummaryForComparison = $existingSummary;
                unset($summaryForComparison['generated_at'], $existingSummaryForComparison['generated_at']);
                $existingPath = MonthlyReportArchivePathBuilder::normalizePath(
                    (string) $monthlyReport->system_pdf_path
                );
                $hasExistingPath = $existingPath !== '';
                $hasArchivePath = $hasExistingPath
                    && MonthlyReportArchivePathBuilder::isArchivePath($existingPath);
                $disk = Storage::disk('local');
                $existingFileExists = $hasExistingPath && $disk->exists($existingPath);
                $summaryChanged = $existingSummaryForComparison != $summaryForComparison;
                $needsRegeneration = ! $hasExistingPath
                    || ! $existingFileExists
                    || ! $hasArchivePath
                    || $summaryChanged;
                $status = MonthlyReport::normalizeStatus($monthlyReport->status);
                $isLockedStatus = in_array(
                    $status,
                    [
                        MonthlyReport::STATUS_APPROVED,
                        MonthlyReport::STATUS_REJECTED,
                        MonthlyReport::STATUS_SIGNED_UPLOADED,
                    ],
                    true
                );
                $isSentWithoutChanges = $status === MonthlyReport::STATUS_SENT
                    && ! $summaryChanged;
                $shouldSend = ! $isLockedStatus && ! $isSentWithoutChanges;

                if (! $needsRegeneration && ! $shouldSend) {
                    return [
                        'report' => $monthlyReport,
                        'should_send' => false,
                    ];
                }

                if ($needsRegeneration && $existingFileExists) {
                    $disk->delete($existingPath);
                }

                $monthlyReport->class_id = $class?->id;
                $monthlyReport->summary_json = $summary;
                $monthlyReport->failure_reason = null;

                if (! $isLockedStatus && ! $isSentWithoutChanges) {
                    $monthlyReport->status = MonthlyReport::STATUS_GENERATED;
                    $monthlyReport->generated_at = now();
                }

                $monthlyReport->save();

                if ($needsRegeneration) {
                    $pdfPath = $this->pdfService->generate(
                        $monthlyReport,
                        $student,
                        $class,
                        $summary
                    );

                    $monthlyReport->system_pdf_path = $pdfPath;
                    $monthlyReport->save();
                }

                return [
                    'report' => $monthlyReport,
                    'should_send' => $shouldSend,
                ];
            });

            $report = $result['report'] ?? null;
            $shouldSend = (bool) ($result['should_send'] ?? false);

            $freshReport = $report?->fresh(['student.guardians']);
            if (! $freshReport) {
                return null;
            }

            OperationLog::record(
                $actor,
                'monthly_report.generated',
                'monthly_report',
                $freshReport->id,
                [
                    'student_id' => $freshReport->student_id,
                    'report_month' => $monthStart->toDateString(),
                    'status' => $freshReport->status,
                    'file_path' => $freshReport->system_pdf_path,
                ]
            );

            if ($shouldSend) {
                $this->sendReportEmails($freshReport, $actor, false, null);
            }

            return $freshReport->fresh(['student', 'schoolClass', 'emailNotifications']);
        } catch (Throwable $exception) {
            $report = MonthlyReport::query()
                ->where('student_id', $student->id)
                ->whereDate('report_month', $monthStart->toDateString())
                ->first();

            if ($report) {
                $report->status = MonthlyReport::STATUS_FAILED;
                $report->failure_reason = $exception->getMessage();
                $report->save();
            }

            OperationLog::record(
                $actor,
                'monthly_report.generation.failed',
                'monthly_report',
                $report?->id,
                [
                    'student_id' => $student->id,
                    'report_month' => $monthStart->toDateString(),
                    'error' => $exception->getMessage(),
                ],
                'ERROR'
            );

            report($exception);

            return null;
        }
    }

    /**
     * @return array{recipients:int,sent:int,failed:int}
     */
    public function sendReportEmails(
        MonthlyReport $report,
        ?User $actor = null,
        bool $isResend = false,
        ?Request $request = null
    ): array {
        $report->loadMissing(['student.guardians']);

        if (! filled($report->system_pdf_path)) {
            return ['recipients' => 0, 'sent' => 0, 'failed' => 0];
        }

        $recipientEmails = collect()
            ->push($report->student?->email)
            ->merge(
                $report->student?->guardians
                    ?->pluck('email')
                    ->all() ?? []
            )
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();

        $summary = [
            'recipients' => (int) $recipientEmails->count(),
            'sent' => 0,
            'failed' => 0,
        ];

        foreach ($recipientEmails as $recipientEmail) {
            $subject = $this->buildMailSubject($report);
            $body = $this->buildNotificationBody($report, $isResend);

            try {
                Mail::to($recipientEmail)->send(new MonthlyReportMail($report));

                MonthlyReportEmailNotification::query()->create([
                    'monthly_report_id' => $report->id,
                    'type' => $isResend ? 'resend' : 'initial_send',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);

                $summary['sent']++;
            } catch (Throwable $exception) {
                MonthlyReportEmailNotification::query()->create([
                    'monthly_report_id' => $report->id,
                    'type' => $isResend ? 'resend' : 'initial_send',
                    'recipient_email' => $recipientEmail,
                    'subject' => $subject,
                    'body' => $body,
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);

                $summary['failed']++;

                OperationLog::record(
                    $actor,
                    'monthly_report.email.failed',
                    'monthly_report',
                    $report->id,
                    [
                        'recipient_email' => $recipientEmail,
                        'error' => $exception->getMessage(),
                    ],
                    'ERROR',
                    $request
                );

                report($exception);
            }
        }

        if ($summary['sent'] > 0) {
            $firstSentAt = $report->sent_at ?: now();

            $report->sent_at = $firstSentAt;
            $report->last_sent_at = now();
            if (in_array(
                MonthlyReport::normalizeStatus($report->status),
                [MonthlyReport::STATUS_GENERATED, MonthlyReport::STATUS_FAILED],
                true
            )) {
                $report->status = MonthlyReport::STATUS_SENT;
            }
            $report->save();

            OperationLog::record(
                $actor,
                $isResend ? 'monthly_report.email.resent' : 'monthly_report.email.sent',
                'monthly_report',
                $report->id,
                [
                    'recipients' => $summary['recipients'],
                    'sent' => $summary['sent'],
                    'failed' => $summary['failed'],
                ],
                'INFO',
                $request
            );
        }

        return $summary;
    }

    public function uploadSignedReport(
        MonthlyReport $report,
        UploadedFile $file,
        User $actor,
        ?Request $request = null
    ): MonthlyReport {
        $report = $this->migrateSignedReportPathToArchive($report);
        $month = Carbon::parse($report->report_month)->startOfMonth();
        $disk = Storage::disk('local');

        if (filled($report->signed_pdf_path) && $disk->exists($report->signed_pdf_path)) {
            $disk->delete($report->signed_pdf_path);
        }

        $extension = MonthlyReportArchivePathBuilder::normalizeExtension(
            (string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'pdf')
        );
        $storedPath = $file->storeAs(
            dirname(MonthlyReportArchivePathBuilder::signedPath(
                (int) $report->student_id,
                $month,
                $extension
            )),
            basename(MonthlyReportArchivePathBuilder::signedPath(
                (int) $report->student_id,
                $month,
                $extension
            )),
            'local'
        );
        if (! is_string($storedPath) || trim($storedPath) === '') {
            throw new \RuntimeException('Impossibile salvare il report firmato.');
        }

        $report->signed_pdf_path = $storedPath;
        $report->signed_uploaded_at = now();
        $report->status = MonthlyReport::STATUS_SIGNED_UPLOADED;
        $report->approved_at = null;
        $report->approved_by = null;
        $report->rejected_at = null;
        $report->rejected_by = null;
        $report->rejection_comment = null;
        $report->save();

        OperationLog::record(
            $actor,
            'monthly_report.signed_uploaded',
            'monthly_report',
            $report->id,
            [
                'file_path' => $storedPath,
                'student_id' => $report->student_id,
            ],
            'INFO',
            $request
        );

        return $report->fresh() ?? $report;
    }

    public function migrateSignedReportPathToArchive(MonthlyReport $report): MonthlyReport
    {
        $currentPath = MonthlyReportArchivePathBuilder::normalizePath(
            (string) $report->signed_pdf_path
        );
        if ($currentPath === '' || MonthlyReportArchivePathBuilder::isArchivePath($currentPath)) {
            return $report;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($currentPath)) {
            return $report;
        }

        $month = Carbon::parse($report->report_month)->startOfMonth();
        $extension = MonthlyReportArchivePathBuilder::normalizeExtension(
            (string) pathinfo($currentPath, PATHINFO_EXTENSION)
        );
        $targetPath = MonthlyReportArchivePathBuilder::signedPath(
            (int) $report->student_id,
            $month,
            $extension
        );
        if ($targetPath === $currentPath) {
            return $report;
        }

        if ($disk->exists($targetPath)) {
            $disk->delete($targetPath);
        }

        $disk->move($currentPath, $targetPath);
        $report->signed_pdf_path = $targetPath;
        $report->save();

        return $report->fresh() ?? $report;
    }

    public function approveReport(
        MonthlyReport $report,
        User $actor,
        ?Request $request = null
    ): MonthlyReport {
        $report->status = MonthlyReport::STATUS_APPROVED;
        $report->approved_at = now();
        $report->approved_by = $actor->id;
        $report->rejected_at = null;
        $report->rejected_by = null;
        $report->rejection_comment = null;
        $report->save();

        OperationLog::record(
            $actor,
            'monthly_report.approved',
            'monthly_report',
            $report->id,
            [
                'student_id' => $report->student_id,
                'approved_at' => $report->approved_at?->toDateTimeString(),
            ],
            'INFO',
            $request
        );

        return $report->fresh() ?? $report;
    }

    public function rejectReport(
        MonthlyReport $report,
        User $actor,
        string $comment,
        ?Request $request = null
    ): MonthlyReport {
        $normalizedComment = trim($comment);

        $report->status = MonthlyReport::STATUS_REJECTED;
        $report->approved_at = null;
        $report->approved_by = null;
        $report->rejected_at = now();
        $report->rejected_by = $actor->id;
        $report->rejection_comment = $normalizedComment;
        $report->save();

        OperationLog::record(
            $actor,
            'monthly_report.rejected',
            'monthly_report',
            $report->id,
            [
                'student_id' => $report->student_id,
                'rejected_at' => $report->rejected_at?->toDateTimeString(),
                'comment' => $normalizedComment,
            ],
            'INFO',
            $request
        );

        return $report->fresh() ?? $report;
    }

    private function buildMailSubject(MonthlyReport $report): string
    {
        $monthLabel = $report->report_month
            ? Carbon::parse($report->report_month)->format('m/Y')
            : '-';
        $studentName = trim((string) ($report->student?->name ?? '').' '.(string) ($report->student?->surname ?? ''));

        if ($studentName !== '') {
            return 'Report mensile '.$monthLabel.' - '.$studentName;
        }

        return 'Report mensile '.$monthLabel;
    }

    private function buildNotificationBody(MonthlyReport $report, bool $isResend): string
    {
        $monthLabel = $report->report_month
            ? Carbon::parse($report->report_month)->format('m/Y')
            : '-';

        $lines = [
            $isResend
                ? 'Promemoria: il report mensile e stato inviato nuovamente.'
                : 'Il report mensile e stato inviato.',
            'Mese di riferimento: '.$monthLabel.'.',
            "Grazie per l'attenzione.",
        ];

        return implode(PHP_EOL, $lines);
    }
}
