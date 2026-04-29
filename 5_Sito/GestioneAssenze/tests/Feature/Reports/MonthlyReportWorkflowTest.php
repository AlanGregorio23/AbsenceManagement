<?php

namespace Tests\Feature\Reports;

use App\Jobs\Mail\MonthlyReportMail;
use App\Models\Absence;
use App\Models\Guardian;
use App\Models\Leave;
use App\Models\MonthlyReport;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MonthlyReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $testDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->testDiskRoot = rtrim(sys_get_temp_dir(), '\\/')
            .DIRECTORY_SEPARATOR
            .'gestioneassenze-monthly-report-'
            .uniqid('', true);
        File::ensureDirectoryExists($this->testDiskRoot);

        config()->set('filesystems.default', 'local');
        config()->set('filesystems.disks.local.root', $this->testDiskRoot);
        config()->set('queue.default', 'sync');
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        app('filesystem')->forgetDisk('local');
        File::deleteDirectory($this->testDiskRoot);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_monthly_report_generation_uses_requested_month_and_sends_emails(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-02 08:30:00'));

        [
            'student' => $student,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-05',
            'end_date' => '2026-03-05',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 4,
            'counts_40_hours' => true,
            'medical_certificate_required' => true,
        ]);
        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
            'reason' => 'Visita',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 8,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);
        \App\Models\Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $student->id,
            'delay_datetime' => '2026-03-08 07:55:00',
            'minutes' => 12,
            'status' => \App\Models\Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
        ]);
        Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $student->id,
            'created_at_custom' => '2026-03-10 10:00:00',
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'requested_hours' => 2,
            'reason' => 'Impegno personale',
            'status' => Leave::STATUS_APPROVED,
            'count_hours' => true,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();
        $summary = is_array($report->summary_json) ? $report->summary_json : [];

        $this->assertSame('2026-03-01', $report->report_month?->toDateString());
        $this->assertSame(MonthlyReport::STATUS_SENT, $report->status);
        $this->assertSame(4, (int) ($summary['absence_hours'] ?? 0));
        $this->assertSame(1, (int) ($summary['delay_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['leave_count'] ?? 0));
        $this->assertNotNull($report->system_pdf_path);
        $this->assertStringStartsWith(
            'archivio/'.$student->id.'/report_mensili/',
            (string) $report->system_pdf_path
        );

        $this->assertTrue(Storage::disk('local')->exists((string) $report->system_pdf_path));
        $pdfContent = Storage::disk('local')->get((string) $report->system_pdf_path);
        $this->assertStringStartsWith('%PDF-', $pdfContent);
        $this->assertStringContainsString('0.12 0.16 0.22 rg', $pdfContent);
        $this->assertStringContainsString('/Type /Page', $pdfContent);
        $this->assertStringContainsString('/Type /Pages', $pdfContent);
        $this->assertStringContainsString('/Contents 4 0 R', $pdfContent);
        $this->assertStringContainsString('Ritardi non firmati', $pdfContent);
        $this->assertStringContainsString('Ore arbitrarie', $pdfContent);
        $this->assertStringNotContainsString('Ore di congedo', $pdfContent);
        $this->assertStringNotContainsString('Assenze senza certificato', $pdfContent);
        $this->assertStringNotContainsString('Certificati caricati', $pdfContent);

        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($student) {
            return $mail->hasTo($student->email);
        });
        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($guardian) {
            return $mail->hasTo($guardian->email);
        });
    }

    public function test_teacher_can_resend_report_email_without_regenerating_pdf(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-03 09:00:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-07',
            'end_date' => '2026-03-07',
            'reason' => 'Visita medica',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 3,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();
        $initialPath = (string) $report->system_pdf_path;

        $response = $this->actingAs($teacher)->post(
            route('teacher.monthly-reports.resend-email', $report)
        );
        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame($initialPath, (string) $report->system_pdf_path);
        $this->assertSame(MonthlyReport::STATUS_SENT, $report->status);

        $this->assertDatabaseHas('monthly_report_email_notifications', [
            'monthly_report_id' => $report->id,
            'type' => 'resend',
            'recipient_email' => $guardian->email,
            'status' => 'sent',
        ]);
    }

    public function test_student_download_regenerates_pdf_if_previous_file_is_corrupted(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-03 11:00:00'));

        [
            'student' => $student,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-09',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 2,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();
        $path = (string) $report->system_pdf_path;
        Storage::disk('local')->put($path, 'corrupted');

        $downloadResponse = $this->actingAs($student)->get(
            route('student.monthly-reports.download', $report)
        );
        $downloadResponse->assertStatus(200);

        $regeneratedContent = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF-', $regeneratedContent);
        $this->assertStringContainsString('/Type /Page', $regeneratedContent);
        $this->assertStringContainsString('/Type /Pages', $regeneratedContent);
    }

    public function test_student_download_regenerates_pdf_if_previous_file_is_missing(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-03 11:30:00'));

        [
            'student' => $student,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-12',
            'end_date' => '2026-03-12',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 2,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();
        $path = (string) $report->system_pdf_path;
        Storage::disk('local')->delete($path);

        $downloadResponse = $this->actingAs($student)->get(
            route('student.monthly-reports.download', $report)
        );
        $downloadResponse->assertStatus(200);

        $this->assertTrue(Storage::disk('local')->exists($path));
        $regeneratedContent = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF-', $regeneratedContent);
        $this->assertStringContainsString('/Type /Page', $regeneratedContent);
        $this->assertStringContainsString('/Type /Pages', $regeneratedContent);
    }

    public function test_generation_heals_missing_pdf_without_resending_when_sent_report_is_unchanged(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-03 12:10:00'));

        [
            'student' => $student,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-13',
            'end_date' => '2026-03-13',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 3,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();
        $originalPath = (string) $report->system_pdf_path;

        $this->assertDatabaseCount('monthly_report_email_notifications', 2);

        Storage::disk('local')->delete($originalPath);
        $this->assertFalse(Storage::disk('local')->exists($originalPath));

        Carbon::setTestNow(Carbon::parse('2026-04-03 12:40:00'));

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report->refresh();

        $this->assertSame(MonthlyReport::STATUS_SENT, $report->status);
        $this->assertSame($originalPath, (string) $report->system_pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($originalPath));
        $this->assertDatabaseCount('monthly_report_email_notifications', 2);
    }

    public function test_mail_attachment_filename_uses_safe_month_separator(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-03 13:00:00'));

        [
            'student' => $student,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-14',
            'end_date' => '2026-03-14',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 1,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()
            ->with('student')
            ->firstOrFail();
        $path = (string) $report->system_pdf_path;

        $mail = new MonthlyReportMail($report);
        $attachments = $mail->attachments();

        $this->assertCount(1, $attachments);
        $this->assertTrue(
            $attachments[0]->isEquivalent(
                Attachment::fromStorageDisk(config('filesystems.default', 'local'), $path)
                    ->as('report-mensile-03-2026.pdf')
                    ->withMime('application/pdf')
            )
        );
    }

    public function test_student_upload_and_teacher_approval_update_status_flow(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-04 08:30:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-15',
            'end_date' => '2026-03-15',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 5,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();

        $uploadResponse = $this->actingAs($student)->post(
            route('student.monthly-reports.upload-signed', $report),
            [
                'document' => UploadedFile::fake()->create(
                    'report-firmato.pdf',
                    120,
                    'application/pdf'
                ),
            ]
        );
        $uploadResponse->assertStatus(302);
        $uploadResponse->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame(MonthlyReport::STATUS_SIGNED_UPLOADED, $report->status);
        $this->assertNotNull($report->signed_pdf_path);
        $this->assertStringStartsWith(
            'archivio/'.$student->id.'/report_mensili/',
            (string) $report->signed_pdf_path
        );
        $this->assertStringContainsString('/firmati/', (string) $report->signed_pdf_path);
        $this->assertNotNull($report->signed_uploaded_at);
        $this->assertTrue(Storage::disk('local')->exists((string) $report->signed_pdf_path));

        $approveResponse = $this->actingAs($teacher)->post(
            route('teacher.monthly-reports.approve', $report)
        );
        $approveResponse->assertStatus(302);
        $approveResponse->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame(MonthlyReport::STATUS_APPROVED, $report->status);
        $this->assertSame($teacher->id, $report->approved_by);
        $this->assertNotNull($report->approved_at);
    }

    public function test_teacher_can_reject_signed_report_and_student_can_upload_again(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-04 09:30:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-16',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 5,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();

        $this->actingAs($student)->post(
            route('student.monthly-reports.upload-signed', $report),
            [
                'document' => UploadedFile::fake()->create(
                    'report-firmato.pdf',
                    120,
                    'application/pdf'
                ),
            ]
        )->assertStatus(302)->assertSessionHasNoErrors();

        $rejectResponse = $this->actingAs($teacher)->post(
            route('teacher.monthly-reports.reject', $report),
            [
                'comment' => 'Firma illeggibile, ricarica una scansione piu chiara.',
            ]
        );
        $rejectResponse->assertStatus(302);
        $rejectResponse->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame(MonthlyReport::STATUS_REJECTED, $report->status);
        $this->assertSame($teacher->id, $report->rejected_by);
        $this->assertNotNull($report->rejected_at);
        $this->assertSame(
            'Firma illeggibile, ricarica una scansione piu chiara.',
            $report->rejection_comment
        );

        $this->actingAs($teacher)->post(
            route('teacher.monthly-reports.resend-email', $report)
        )->assertStatus(302)->assertSessionHasErrors(['report']);

        $this->actingAs($student)->post(
            route('student.monthly-reports.upload-signed', $report),
            [
                'document' => UploadedFile::fake()->create(
                    'report-firmato-nuovo.pdf',
                    130,
                    'application/pdf'
                ),
            ]
        )->assertStatus(302)->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame(MonthlyReport::STATUS_SIGNED_UPLOADED, $report->status);
        $this->assertNull($report->rejected_by);
        $this->assertNull($report->rejected_at);
        $this->assertNull($report->rejection_comment);
        $this->assertTrue(Storage::disk('local')->exists((string) $report->signed_pdf_path));
    }

    public function test_teacher_cannot_resend_email_when_report_is_archived(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-04 10:15:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-17',
            'end_date' => '2026-03-17',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 4,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $this->artisan('reports:generate-monthly', [
            '--month' => '2026-03',
        ])->assertExitCode(0);

        $report = MonthlyReport::query()->firstOrFail();

        $this->actingAs($student)->post(
            route('student.monthly-reports.upload-signed', $report),
            [
                'document' => UploadedFile::fake()->create(
                    'report-firmato.pdf',
                    120,
                    'application/pdf'
                ),
            ]
        )->assertStatus(302)->assertSessionHasNoErrors();

        $this->actingAs($teacher)->post(
            route('teacher.monthly-reports.approve', $report)
        )->assertStatus(302)->assertSessionHasNoErrors();

        $resendResponse = $this->actingAs($teacher)->post(
            route('teacher.monthly-reports.resend-email', $report)
        );
        $resendResponse->assertStatus(302);
        $resendResponse->assertSessionHasErrors(['report']);

        $this->assertDatabaseMissing('monthly_report_email_notifications', [
            'monthly_report_id' => $report->id,
            'type' => 'resend',
        ]);
    }

    public function test_monthly_report_bucket_classification_is_consistent_for_ui_monitoring(): void
    {
        $this->assertSame('missing', MonthlyReport::bucketForStatus(MonthlyReport::STATUS_GENERATED));
        $this->assertSame('missing', MonthlyReport::bucketForStatus(MonthlyReport::STATUS_SENT));
        $this->assertSame('pending', MonthlyReport::bucketForStatus(MonthlyReport::STATUS_SIGNED_UPLOADED));
        $this->assertSame('completed', MonthlyReport::bucketForStatus(MonthlyReport::STATUS_APPROVED));
    }

    /**
     * @return array{
     *     student: User,
     *     teacher: User,
     *     guardian: Guardian
     * }
     */
    private function createWorkflowActors(): array
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.monthly@example.test',
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Mario Gregorio',
            'email' => 'tutore.monthly@example.test',
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Padre',
            'is_primary' => true,
        ]);

        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.monthly@example.test',
        ]);

        $class = SchoolClass::query()->create([
            'name' => 'AA',
            'year' => '3',
            'section' => 'I',
            'active' => true,
        ]);
        $class->students()->attach($student->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ]);
        $class->teachers()->attach($teacher->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ]);

        return [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ];
    }
}
