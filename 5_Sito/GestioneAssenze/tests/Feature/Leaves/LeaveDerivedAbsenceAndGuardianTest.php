<?php

namespace Tests\Feature\Leaves;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Jobs\Mail\GuardianLeaveSignatureMail;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\Guardian;
use App\Models\Leave;
use App\Models\LeaveConfirmationToken;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

class LeaveDerivedAbsenceAndGuardianTest extends LeaveFeatureTestCase
{
    public function test_future_leave_registers_absence_draft_only_from_start_date(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);
        AbsenceReason::query()->create([
            'name' => 'Motivi familiari',
            'counts_40_hours' => true,
        ]);

        [
            'student' => $student,
            'laboratoryManager' => $laboratoryManager,
        ] = $this->createWorkflowActors(suffix: 'future-registration');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-12',
            'end_date' => '2026-03-12',
            'hours' => 4,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-futuro.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $preApproveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.pre-approve', $leave),
            [
                'comment' => 'Override per test registrazione differita.',
            ]
        );
        $preApproveResponse->assertStatus(302);
        $preApproveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_REGISTERED, $leave->status);
        $this->assertNull($leave->registered_absence_id);
        $this->assertDatabaseMissing('absences', [
            'derived_from_leave_id' => $leave->id,
        ]);

        Artisan::call('leaves:register-due-absences');
        $leave->refresh();
        $this->assertNull($leave->registered_absence_id);

        Carbon::setTestNow(Carbon::parse('2026-03-12 07:30:00'));
        Artisan::call('leaves:register-due-absences');

        $leave->refresh();
        $this->assertNotNull($leave->registered_absence_id);

        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);
        $this->assertSame($leave->id, $absence->derived_from_leave_id);
        $this->assertSame('2026-03-12', $absence->start_date?->toDateString());

        $this->assertInfoOperationLogExists('leave.registered', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered_as_absence', 'leave', $leave->id);
    }

    public function test_future_leave_full_draft_flow_from_approval_to_student_submission(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);
        AbsenceReason::query()->create([
            'name' => 'Motivi familiari',
            'counts_40_hours' => true,
        ]);

        [
            'student' => $student,
            'laboratoryManager' => $laboratoryManager,
        ] = $this->createWorkflowActors(suffix: 'future-full-flow');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-12',
            'end_date' => '2026-03-12',
            'hours' => 4,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-futuro-full-flow.pdf',
                128,
                'application/pdf'
            ),
        ]);
        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $preApproveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.pre-approve', $leave),
            [
                'comment' => 'Approvato per test flusso completo bozza futura.',
            ]
        );
        $preApproveResponse->assertStatus(302);
        $preApproveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_REGISTERED, $leave->status);
        $this->assertNull($leave->registered_absence_id);

        Artisan::call('leaves:register-due-absences');
        $leave->refresh();
        $this->assertNull($leave->registered_absence_id);

        Carbon::setTestNow(Carbon::parse('2026-03-12 07:30:00'));
        Artisan::call('leaves:register-due-absences');

        $leave->refresh();
        $this->assertNotNull($leave->registered_absence_id);

        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);
        $this->assertSame($leave->id, $absence->derived_from_leave_id);
        $this->assertSame(4, (int) $absence->assigned_hours);

        $editDraftResponse = $this->actingAs($student)->get(
            route('student.absences.derived-draft.edit', $absence)
        );
        $editDraftResponse->assertStatus(200);

        $submitDraftResponse = $this->actingAs($student)->post(
            route('student.absences.derived-draft.submit', $absence),
            [
                'hours' => 4,
            ]
        );
        $submitDraftResponse->assertStatus(302);
        $submitDraftResponse->assertSessionHasNoErrors();
        $submitDraftResponse->assertRedirect(route('dashboard'));

        $absence->refresh();
        $this->assertSame(Absence::STATUS_REPORTED, $absence->status);
        $this->assertSame($leave->id, $absence->derived_from_leave_id);
        $this->assertSame(4, (int) $absence->assigned_hours);

        $this->assertInfoOperationLogExists('leave.registered', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered_as_absence', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('absence.derived_leave_draft.submitted', 'absence', $absence->id);
    }

    public function test_absence_derived_from_signed_leave_requires_new_absence_guardian_signature(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);
        AbsenceReason::query()->create([
            'name' => 'Motivi familiari',
            'counts_40_hours' => true,
        ]);

        [
            'student' => $student,
            'guardian' => $guardian,
            'laboratoryManager' => $laboratoryManager,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors(suffix: 'signature-separation');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'hours' => 4,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-separazione-firma.pdf',
                128,
                'application/pdf'
            ),
        ]);
        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $token = LeaveConfirmationToken::query()
            ->where('leave_id', $leave->id)
            ->where('guardian_id', $guardian->id)
            ->firstOrFail();
        $plainToken = 'token-firma-congedo-separazione';
        $token->update([
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::today()->addDays(2)->endOfDay(),
            'used_at' => null,
        ]);

        $signatureResponse = $this->post(
            route('guardian.leaves.signature.store', ['token' => $plainToken]),
            [
                'full_name' => 'Mario Gregorio',
                'consent' => '1',
                'signature_data' => $this->validPngSignatureDataUri(),
            ]
        );
        $signatureResponse->assertStatus(302);

        $leave->refresh();
        $this->assertSame(Leave::STATUS_SIGNED, Leave::normalizeStatus($leave->status));

        $approveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.approve', $leave),
            [
                'comment' => 'Approvato dopo firma congedo.',
            ]
        );
        $approveResponse->assertStatus(302);
        $approveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);

        $submitDraftResponse = $this->actingAs($student)->post(
            route('student.absences.derived-draft.submit', $absence),
            [
                'hours' => 4,
            ]
        );
        $submitDraftResponse->assertStatus(302);
        $submitDraftResponse->assertSessionHasNoErrors();

        $absence->refresh();
        $this->assertSame(Absence::STATUS_REPORTED, $absence->status);

        $absenceItem = collect((new Absence)->getAbsence($student))
            ->firstWhere('absence_id', $absence->id);
        $this->assertNotNull($absenceItem);
        $this->assertFalse((bool) ($absenceItem['firma_tutore_presente'] ?? true));
        $this->assertSame(
            'Attesa firma tutore',
            (string) ($absenceItem['stato'] ?? '')
        );

        $teacherApproveResponse = $this->actingAs($teacher)->post(
            route('teacher.absences.approve', $absence)
        );
        $teacherApproveResponse->assertStatus(302);
        $teacherApproveResponse->assertSessionHasErrors(['absence']);

        $absence->refresh();
        $this->assertSame(Absence::STATUS_REPORTED, $absence->status);
    }

    public function test_student_cannot_update_effective_hours_for_absence_derived_from_leave(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 10:30:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);

        [
            'student' => $student,
            'laboratoryManager' => $laboratoryManager,
        ] = $this->createWorkflowActors(suffix: 'effective-hours');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-25',
            'end_date' => '2026-03-25',
            'hours' => 5,
            'motivation' => 'Permesso temporaneo',
            'destination' => 'Lugano',
        ]);
        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $preApproveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.pre-approve', $leave),
            [
                'comment' => 'Pre-approvato per test blocco ore effettive.',
            ]
        );
        $preApproveResponse->assertStatus(302);
        $preApproveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);

        $editDraftResponse = $this->actingAs($student)->get(
            route('student.absences.derived-draft.edit', $absence)
        );
        $editDraftResponse->assertStatus(200);

        $submitDraftResponse = $this->actingAs($student)->post(
            route('student.absences.derived-draft.submit', $absence),
            [
                'start_date' => '2026-03-25',
                'end_date' => '2026-03-25',
                'hours' => 5,
                'motivation' => 'Permesso temporaneo - confermato',
            ]
        );
        $submitDraftResponse->assertStatus(302);
        $submitDraftResponse->assertSessionHasNoErrors();
        $submitDraftResponse->assertRedirect(route('dashboard'));

        $absence->refresh();
        $this->assertSame(Absence::STATUS_REPORTED, $absence->status);
        $this->assertSame('Permesso temporaneo', (string) $absence->reason);

        $updateHoursResponse = $this->actingAs($student)->post(
            route('student.absences.effective-hours.update', $absence),
            [
                'hours' => 2,
            ]
        );
        $updateHoursResponse->assertStatus(302);
        $updateHoursResponse->assertSessionHasErrors(['absence']);

        $absence->refresh();
        $this->assertSame(5, (int) $absence->assigned_hours);
        $this->assertStringNotContainsString(
            'Ore effettive aggiornate dallo studente',
            (string) $absence->teacher_comment
        );
        $this->assertDatabaseMissing('operation_logs', [
            'action' => 'absence.derived_leave_effective_hours.updated_by_student',
            'entity' => 'absence',
            'entity_id' => $absence->id,
        ]);
        $this->assertInfoOperationLogExists(
            'absence.derived_leave_draft.submitted',
            'absence',
            $absence->id
        );
    }

    public function test_adult_student_can_notify_previous_guardians_without_signature_rights_on_leave(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-10 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Maggiorenne',
            'role' => 'student',
            'email' => 'alan.adult.leave@example.test',
            'birth_date' => '2007-02-01',
        ]);

        $previousGuardian = Guardian::query()->create([
            'name' => 'Genitore Storico',
            'email' => 'genitore.storico.leave@example.test',
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

        $response = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-04-11',
            'end_date' => '2026-04-11',
            'hours' => 4,
            'motivation' => 'Visita specialistica',
            'destination' => 'Lugano',
            'document' => UploadedFile::fake()->create(
                'congedo-maggiorenne.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        Mail::assertSent(GuardianLeaveSignatureMail::class, function (GuardianLeaveSignatureMail $mail) use ($student) {
            return $mail->hasTo($student->email);
        });
        Mail::assertNotSent(GuardianLeaveSignatureMail::class, function (GuardianLeaveSignatureMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });
        Mail::assertSent(AdultStudentGuardianInfoMail::class, function (AdultStudentGuardianInfoMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });

        $this->assertDatabaseHas('leave_email_notifications', [
            'leave_id' => $leave->id,
            'type' => 'inactive_guardian_info',
            'recipient_email' => $previousGuardian->email,
            'status' => 'sent',
        ]);
    }
}
