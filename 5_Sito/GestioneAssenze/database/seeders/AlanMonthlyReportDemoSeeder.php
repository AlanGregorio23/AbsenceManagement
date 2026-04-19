<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\Delay;
use App\Models\Guardian;
use App\Models\Leave;
use App\Models\MedicalCertificate;
use App\Models\MonthlyReport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\MonthlyReportService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AlanMonthlyReportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SystemRulesSeeder::class);

        $month = Carbon::today()->subMonth()->startOfMonth();

        $student = User::query()->updateOrCreate(
            ['email' => 'alan.gregorio@example.com'],
            [
                'name' => 'Alan',
                'surname' => 'Gregorio',
                'role' => 'student',
                'birth_date' => '2008-03-11',
                'password' => Hash::make('Admin$00'),
                'active' => true,
            ]
        );

        $guardian = Guardian::query()->updateOrCreate(
            ['email' => 'tutore.alan.gregorio@example.com'],
            ['name' => 'Mario Gregorio']
        );

        $student->guardians()->syncWithoutDetaching([
            $guardian->id => [
                'relationship' => 'Padre',
                'is_primary' => true,
            ],
        ]);

        $teacher = User::query()->updateOrCreate(
            ['email' => 'docente.alan.gregorio@example.com'],
            [
                'name' => 'Giulia',
                'surname' => 'Docente',
                'role' => 'teacher',
                'birth_date' => '1984-02-17',
                'password' => Hash::make('Admin$00'),
                'active' => true,
            ]
        );

        $class = SchoolClass::query()->updateOrCreate(
            ['name' => '3I Demo Report'],
            [
                'year' => '3',
                'section' => 'I',
                'active' => true,
            ]
        );

        $class->students()->syncWithoutDetaching([
            $student->id => [
                'start_date' => $month->copy()->subMonth()->toDateString(),
                'end_date' => null,
            ],
        ]);
        $class->teachers()->syncWithoutDetaching([
            $teacher->id => [
                'start_date' => $month->copy()->subMonth()->toDateString(),
                'end_date' => null,
            ],
        ]);

        $absenceMissingCertificate = Absence::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'start_date' => $month->copy()->addDays(3)->toDateString(),
                'end_date' => $month->copy()->addDays(3)->toDateString(),
                'reason' => 'Malattia senza certificato',
            ],
            [
                'status' => Absence::STATUS_JUSTIFIED,
                'assigned_hours' => 4,
                'counts_40_hours' => true,
                'medical_certificate_required' => true,
                'medical_certificate_deadline' => $month->copy()->addDays(10)->toDateString(),
            ]
        );

        $absenceWithCertificate = Absence::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'start_date' => $month->copy()->addDays(8)->toDateString(),
                'end_date' => $month->copy()->addDays(8)->toDateString(),
                'reason' => 'Visita medica con certificato',
            ],
            [
                'status' => Absence::STATUS_JUSTIFIED,
                'assigned_hours' => 3,
                'counts_40_hours' => false,
                'counts_40_hours_comment' => 'Esclusa dalle 40 ore per certificato medico accettato.',
                'medical_certificate_required' => true,
                'medical_certificate_deadline' => $month->copy()->addDays(15)->toDateString(),
            ]
        );

        $certificatePath = 'certificati-medici/demo-alan-'.$month->format('Y-m').'.pdf';
        Storage::disk(config('filesystems.default', 'local'))->put(
            $certificatePath,
            'Demo certificato medico per test report mensile Alan'
        );

        MedicalCertificate::query()->updateOrCreate(
            [
                'absence_id' => $absenceWithCertificate->id,
                'file_path' => $certificatePath,
            ],
            [
                'uploaded_at' => $month->copy()->addDays(9)->setTime(8, 30),
                'valid' => true,
                'validated_at' => $month->copy()->addDays(10)->setTime(9, 15),
                'validated_by' => $teacher->id,
            ]
        );

        Delay::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'delay_datetime' => $month->copy()->addDays(5)->setTime(8, 12)->toDateTimeString(),
            ],
            [
                'recorded_by' => $teacher->id,
                'minutes' => 12,
                'justification_deadline' => $month->copy()->addDays(12)->toDateString(),
                'notes' => 'Ritardo mezzi pubblici',
                'status' => Delay::STATUS_REGISTERED,
                'count_in_semester' => true,
                'global' => false,
            ]
        );

        Delay::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'delay_datetime' => $month->copy()->addDays(17)->setTime(8, 5)->toDateTimeString(),
            ],
            [
                'recorded_by' => $teacher->id,
                'minutes' => 5,
                'justification_deadline' => $month->copy()->addDays(24)->toDateString(),
                'notes' => 'Ritardo lieve',
                'status' => Delay::STATUS_JUSTIFIED,
                'count_in_semester' => false,
                'global' => false,
            ]
        );

        Leave::query()->updateOrCreate(
            [
                'student_id' => $student->id,
                'start_date' => $month->copy()->addDays(12)->toDateString(),
                'end_date' => $month->copy()->addDays(12)->toDateString(),
                'reason' => 'Permesso personale',
            ],
            [
                'created_by' => $student->id,
                'created_at_custom' => $month->copy()->addDays(11)->setTime(17, 45),
                'requested_hours' => 2,
                'requested_lessons' => null,
                'destination' => 'Ufficio medico',
                'status' => Leave::STATUS_APPROVED,
                'approved_without_guardian' => false,
                'count_hours' => true,
                'count_hours_comment' => null,
            ]
        );

        $this->deleteExistingMonthlyReportForMonth((int) $student->id, $month);

        /** @var MonthlyReportService $reportService */
        $reportService = app(MonthlyReportService::class);
        $report = $reportService->generateAndSendForStudent((int) $student->id, $month);

        $reportCode = $report?->reportCode() ?? '-';
        $status = $report?->status ?? 'not-generated';
        $this->command?->info('Seeder demo report Alan completato.');
        $this->command?->line('Studente: alan.gregorio@example.com');
        $this->command?->line('Tutore: tutore.alan.gregorio@example.com');
        $this->command?->line('Docente classe: docente.alan.gregorio@example.com');
        $this->command?->line('Mese report: '.$month->format('Y-m'));
        $this->command?->line('Report: '.$reportCode.' | Stato: '.$status);
    }

    private function deleteExistingMonthlyReportForMonth(int $studentId, Carbon $month): void
    {
        $existing = MonthlyReport::query()
            ->where('student_id', $studentId)
            ->whereDate('report_month', $month->toDateString())
            ->with('emailNotifications')
            ->first();

        if (! $existing) {
            return;
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));

        foreach ([$existing->system_pdf_path, $existing->signed_pdf_path] as $path) {
            $resolvedPath = trim((string) $path);
            if ($resolvedPath !== '' && $disk->exists($resolvedPath)) {
                $disk->delete($resolvedPath);
            }
        }

        $existing->emailNotifications()->delete();
        $existing->delete();
    }
}
