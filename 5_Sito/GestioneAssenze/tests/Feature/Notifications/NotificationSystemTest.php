<?php

namespace Tests\Feature\Notifications;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Jobs\Mail\AnnualHoursLimitReachedGuardianMail;
use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\Guardian;
use App\Models\Leave;
use App\Models\NotificationPreference;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\SystemMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_absence_creation_notifies_linked_teacher_in_app(): void
    {
        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createClassContext();

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 4,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-25',
        ]);

        $notification = $teacher->notifications()->latest()->first();

        $this->assertNotNull($notification);
        $this->assertSame('Nuova assenza da verificare', $notification->data['title'] ?? null);
        $this->assertSame(route('teacher.absences.show', $absence), $notification->data['url'] ?? null);
    }

    public function test_leave_status_update_notifies_student(): void
    {
        [
            'student' => $student,
        ] = $this->createClassContext();

        $leave = Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $student->id,
            'created_at_custom' => '2026-03-20 08:00:00',
            'start_date' => '2026-03-21',
            'end_date' => '2026-03-21',
            'requested_hours' => 2,
            'reason' => 'Visita',
            'destination' => 'Lugano',
            'status' => Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE,
            'approved_without_guardian' => false,
            'count_hours' => true,
        ]);

        $leave->update([
            'status' => Leave::STATUS_DOCUMENTATION_REQUESTED,
        ]);

        $notification = $student->notifications()->latest()->first();

        $this->assertNotNull($notification);
        $this->assertSame('student_leave_documentation_requested', $notification->data['event_key'] ?? null);
        $this->assertSame('Documentazione congedo richiesta', $notification->data['title'] ?? null);
        $this->assertSame(route('student.documents'), $notification->data['url'] ?? null);
    }

    public function test_absence_arbitrary_notifies_student_and_linked_teacher_with_specific_events(): void
    {
        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createClassContext();

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 4,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-25',
        ]);

        $student->notifications()->delete();
        $teacher->notifications()->delete();

        $absence->update([
            'status' => Absence::STATUS_ARBITRARY,
        ]);

        $studentNotification = $student->notifications()->latest()->first();
        $teacherNotification = $teacher->notifications()->latest()->first();

        $this->assertNotNull($studentNotification);
        $this->assertNotNull($teacherNotification);
        $this->assertSame('student_absence_arbitrary', $studentNotification->data['event_key'] ?? null);
        $this->assertSame('teacher_absence_arbitrary', $teacherNotification->data['event_key'] ?? null);
    }

    public function test_reaching_annual_hours_notifies_student_teacher_and_guardian(): void
    {
        Mail::fake();

        AbsenceSetting::query()->create([
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'guardian_signature_required' => true,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => 10,
        ]);

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createClassContext();

        $guardian = Guardian::query()->create([
            'name' => 'Tutore Sara',
            'email' => 'tutore.sara@example.test',
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Genitore',
            'is_primary' => true,
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Assenza conteggiata',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 40,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-25',
        ]);

        $studentEvents = $student->notifications()
            ->pluck('data')
            ->map(fn ($payload) => $payload['event_key'] ?? null)
            ->values()
            ->all();
        $teacherEvents = $teacher->notifications()
            ->pluck('data')
            ->map(fn ($payload) => $payload['event_key'] ?? null)
            ->values()
            ->all();

        $this->assertContains('student_annual_hours_limit_reached', $studentEvents);
        $this->assertContains('teacher_student_annual_hours_limit_reached', $teacherEvents);

        Mail::assertSent(
            AnnualHoursLimitReachedGuardianMail::class,
            function (AnnualHoursLimitReachedGuardianMail $mail) use ($guardian, $student) {
                return $mail->hasTo($guardian->email)
                    && $mail->studentName === $student->fullName()
                    && $mail->maxHours === 40;
            }
        );
    }

    public function test_email_preference_disables_mail_channel_but_keeps_database_channel(): void
    {
        $teacher = User::factory()->create([
            'name' => 'Lucia',
            'surname' => 'Verdi',
            'role' => 'teacher',
            'email' => 'lucia.verdi@example.test',
        ]);

        $notification = new SystemMessageNotification('teacher_new_absences', [
            'title' => 'Nuova assenza da verificare',
            'body' => 'Messaggio di prova.',
            'url' => route('dashboard'),
        ]);

        $this->assertSame(['database'], $notification->via($teacher));

        NotificationPreference::query()->create([
            'user_id' => $teacher->id,
            'event_key' => 'teacher_new_absences',
            'web_enabled' => true,
            'email_enabled' => true,
        ]);

        $this->assertSame(['database', 'mail'], $notification->via($teacher));

        NotificationPreference::query()->updateOrCreate([
            'user_id' => $teacher->id,
            'event_key' => 'teacher_new_absences',
        ], [
            'web_enabled' => true,
            'email_enabled' => false,
        ]);

        $this->assertSame(['database'], $notification->via($teacher));
    }

    public function test_web_preference_disables_database_channel_but_keeps_mail_channel(): void
    {
        $teacher = User::factory()->create([
            'name' => 'Marta',
            'surname' => 'Blu',
            'role' => 'teacher',
            'email' => 'marta.blu@example.test',
        ]);

        $notification = new SystemMessageNotification('teacher_new_absences', [
            'title' => 'Nuova assenza da verificare',
            'body' => 'Messaggio di prova.',
            'url' => route('dashboard'),
        ]);

        NotificationPreference::query()->create([
            'user_id' => $teacher->id,
            'event_key' => 'teacher_new_absences',
            'web_enabled' => false,
            'email_enabled' => true,
        ]);

        $this->assertSame(['mail'], $notification->via($teacher));
    }

    public function test_deadline_warning_notifications_enable_email_by_default_for_students(): void
    {
        $student = User::factory()->create([
            'name' => 'Elisa',
            'surname' => 'Scadenza',
            'role' => 'student',
            'email' => 'elisa.scadenza@example.test',
        ]);

        $absenceNotification = new SystemMessageNotification('student_absence_deadline_warning', [
            'title' => 'Scadenza assenza in avvicinamento',
            'body' => 'Promemoria test.',
            'url' => route('student.history'),
        ]);
        $delayNotification = new SystemMessageNotification('student_delay_deadline_warning', [
            'title' => 'Scadenza ritardo in avvicinamento',
            'body' => 'Promemoria test.',
            'url' => route('student.history'),
        ]);

        $this->assertSame(['database', 'mail'], $absenceNotification->via($student));
        $this->assertSame(['database', 'mail'], $delayNotification->via($student));
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()->create([
            'name' => 'Elena',
            'surname' => 'Neri',
            'role' => 'student',
            'email' => 'elena.neri@example.test',
        ]);

        $user->notify(new SystemMessageNotification('student_monthly_report_available', [
            'title' => 'Report mensile disponibile',
            'body' => 'Apri la sezione report.',
            'url' => route('student.monthly-reports'),
        ]));

        $notification = $user->notifications()->latest()->firstOrFail();
        $this->assertNull($notification->read_at);

        $response = $this->actingAs($user)
            ->post(route('notifications.read', $notification->id));

        $response->assertStatus(302);

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_admin_receives_notifications_only_for_warning_and_error_logs(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.notifications@example.test',
        ]);

        OperationLog::record(
            null,
            'delay.guardian_confirmation_email.missing_guardian',
            'delay',
            12,
            [],
            'WARNING'
        );

        OperationLog::record(
            null,
            'monthly_report.email.failed',
            'monthly_report',
            18,
            [],
            'ERROR'
        );

        OperationLog::record(
            null,
            'admin.user.updated',
            'user',
            99,
            [],
            'INFO'
        );

        $events = $admin->notifications()
            ->latest()
            ->pluck('data')
            ->map(fn ($payload) => $payload['event_key'] ?? null)
            ->values()
            ->all();

        $this->assertContains('admin_system_warnings', $events);
        $this->assertContains('admin_system_errors', $events);
        $this->assertNotContains('admin_user_events', $events);
        $this->assertCount(2, $events);
    }

    public function test_disabling_previous_guardians_email_preference_notifies_previous_guardians(): void
    {
        Mail::fake();

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Maggiorenne',
            'role' => 'student',
            'email' => 'alan.preference.toggle@example.test',
            'birth_date' => '2007-02-01',
        ]);

        $previousGuardian = Guardian::query()->create([
            'name' => 'Genitore Storico',
            'email' => 'genitore.preference.toggle@example.test',
        ]);
        $selfGuardian = Guardian::query()->create([
            'name' => 'Alan Maggiorenne',
            'email' => $student->email,
        ]);

        $student->allGuardians()->attach($previousGuardian->id, [
            'relationship' => 'Se stesso',
            'is_primary' => false,
            'is_active' => true,
            'deactivated_at' => null,
        ]);
        $student->allGuardians()->attach($selfGuardian->id, [
            'relationship' => 'Se stesso',
            'is_primary' => true,
            'is_active' => true,
            'deactivated_at' => null,
        ]);

        NotificationPreference::query()->create([
            'user_id' => $student->id,
            'event_key' => 'student_notify_inactive_guardians',
            'email_enabled' => true,
        ]);

        $response = $this->actingAs($student)
            ->from(route('profile.edit'))
            ->patch(route('profile.notifications.update'), [
                'preferences' => [
                    'student_notify_inactive_guardians' => [
                        'web_enabled' => true,
                        'email_enabled' => false,
                    ],
                ],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $student->id,
            'event_key' => 'student_notify_inactive_guardians',
            'email_enabled' => 0,
        ]);

        Mail::assertSent(AdultStudentGuardianInfoMail::class, function (AdultStudentGuardianInfoMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email)
                && str_contains(strtolower($mail->subjectLine), 'disattivata');
        });
        Mail::assertNotSent(AdultStudentGuardianInfoMail::class, function (AdultStudentGuardianInfoMail $mail) use ($student) {
            return $mail->hasTo($student->email);
        });
    }

    private function createClassContext(): array
    {
        $student = User::factory()->create([
            'name' => 'Sara',
            'surname' => 'Galli',
            'role' => 'student',
            'email' => 'sara.galli@example.test',
        ]);

        $teacher = User::factory()->create([
            'name' => 'Paolo',
            'surname' => 'Ferri',
            'role' => 'teacher',
            'email' => 'paolo.ferri@example.test',
        ]);

        $class = SchoolClass::query()->create([
            'name' => 'INF',
            'section' => 'A',
            'year' => '4',
            'active' => true,
        ]);

        $student->classes()->attach($class->id, [
            'start_date' => '2026-01-01',
            'end_date' => null,
        ]);

        $teacher->teachingClasses()->attach($class->id, [
            'start_date' => '2026-01-01',
            'end_date' => null,
        ]);

        return [
            'student' => $student,
            'teacher' => $teacher,
            'class' => $class,
        ];
    }
}
