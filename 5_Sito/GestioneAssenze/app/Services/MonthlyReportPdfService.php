<?php

namespace App\Services;

use App\Models\MonthlyReport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\MonthlyReportArchivePathBuilder;
use App\Support\SimplePdfBuilder;
use App\Support\SystemSettingsResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class MonthlyReportPdfService
{
    public function __construct(private readonly SimplePdfBuilder $pdfBuilder) {}

    /**
     * @param  array<string,mixed>  $summary
     */
    public function generate(
        MonthlyReport $report,
        User $student,
        ?SchoolClass $class,
        array $summary
    ): string {
        $month = Carbon::parse($report->report_month)->startOfMonth();
        $path = $this->buildPath((int) $student->id, $month);
        $payload = $this->buildPayload($student, $class, $summary, $report, $month);
        $content = $this->pdfBuilder->buildSchoolMonthlyReport($payload);

        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function buildPath(int $studentId, Carbon $month): string
    {
        return MonthlyReportArchivePathBuilder::originalPath($studentId, $month);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function buildPayload(
        User $student,
        ?SchoolClass $class,
        array $summary,
        MonthlyReport $report,
        Carbon $month
    ): array {
        $studentName = trim((string) $student->name.' '.(string) $student->surname);
        $classLabel = $class
            ? trim((string) $class->year.(string) $class->section) ?: (string) $class->name
            : '-';
        $hours40 = is_array($summary['hours_40'] ?? null)
            ? $summary['hours_40']
            : [];
        $medical = is_array($summary['medical_certificates'] ?? null)
            ? $summary['medical_certificates']
            : [];
        $penalties = is_array($summary['penalties'] ?? null)
            ? $summary['penalties']
            : [];
        $schoolYear = $this->schoolYearLabel($month);
        $settingsLimit = max((int) SystemSettingsResolver::absenceSetting()->max_annual_hours, 0);
        $hoursLimit = (int) ($hours40['limit'] ?? 0);
        if ($hoursLimit <= 0) {
            $hoursLimit = $settingsLimit;
        }
        $hoursSectionTitle = 'Situazione limite annuale';
        if ($hoursLimit > 0) {
            $hoursSectionTitle .= ' ('.$hoursLimit.' ore)';
        }

        return [
            'school_name' => 'Istituto scolastico - '.config('app.name', 'Gestione Assenze'),
            'report_code' => $report->reportCode(),
            'generated_at' => now()->format('d/m/Y H:i'),
            'student_name' => $studentName !== '' ? $studentName : '-',
            'class_label' => $classLabel,
            'month_label' => (string) ($summary['month_label'] ?? $month->format('m/Y')),
            'school_year' => $schoolYear,
            'hours_section_title' => $hoursSectionTitle,
            'summary_rows' => [
                ['label' => 'Ore di assenza', 'value' => (string) ((int) ($summary['absence_hours'] ?? 0))],
                ['label' => 'Numero assenze', 'value' => (string) ((int) ($summary['absence_count'] ?? 0))],
                ['label' => 'Numero congedi', 'value' => (string) ((int) ($summary['leave_count'] ?? 0))],
                ['label' => 'Ore arbitrarie', 'value' => (string) ((int) ($summary['arbitrary_hours'] ?? 0))],
                ['label' => 'Ritardi nel mese', 'value' => (string) ((int) ($summary['delay_count'] ?? 0))],
            ],
            'hours_rows' => [
                ['label' => 'Ore conteggiate nel mese', 'value' => (string) ((int) ($hours40['month_counted'] ?? 0))],
                ['label' => 'Ore conteggiate annuali', 'value' => (string) ((int) ($hours40['annual_counted'] ?? 0))],
                ['label' => 'Limite annuale', 'value' => (string) $hoursLimit],
                ['label' => 'Ore residue', 'value' => (string) ((int) ($hours40['remaining'] ?? 0))],
            ],
            'medical_rows' => [
                ['label' => 'Certificati mancanti', 'value' => (string) ((int) ($medical['missing'] ?? 0))],
                ['label' => 'Assenze non firmate', 'value' => (string) ((int) ($summary['unsigned_absence_count'] ?? 0))],
                ['label' => 'Ritardi non firmati', 'value' => (string) ((int) ($summary['unsigned_delay_count'] ?? 0))],
            ],
            'notes' => $penalties,
        ];
    }

    private function schoolYearLabel(Carbon $month): string
    {
        $year = (int) $month->year;
        if ((int) $month->month >= 9) {
            return $year.'/'.($year + 1);
        }

        return ($year - 1).'/'.$year;
    }
}
