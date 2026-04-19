<?php

namespace App\Mail;

use App\Models\MonthlyReport;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MonthlyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public MonthlyReport $report) {}

    public function envelope(): Envelope
    {
        $monthLabel = $this->report->report_month
            ? Carbon::parse($this->report->report_month)->format('m/Y')
            : '-';
        $studentName = trim((string) ($this->report->student?->name ?? '').' '.(string) ($this->report->student?->surname ?? ''));

        return new Envelope(
            subject: $studentName !== ''
                ? 'Report mensile '.$monthLabel.' - '.$studentName
                : 'Report mensile '.$monthLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.monthly-report',
            with: [
                'report' => $this->report,
                'summary' => is_array($this->report->summary_json)
                    ? $this->report->summary_json
                    : [],
                'studentName' => trim((string) ($this->report->student?->name ?? '').' '.(string) ($this->report->student?->surname ?? '')),
            ],
        );
    }

    public function attachments(): array
    {
        $path = trim((string) $this->report->system_pdf_path);
        if ($path === '') {
            return [];
        }

        $safeMonthLabel = str_replace('/', '-', $this->report->monthLabel());

        return [
            Attachment::fromStorageDisk('local', $path)
                ->as('report-mensile-'.$safeMonthLabel.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
