<?php

namespace Tests\Feature\Absences;

use App\Jobs\Mail\GuardianAbsenceSignatureMail;
use App\Models\Absence;
use App\Models\AbsenceConfirmationToken;
use App\Models\AbsenceReason;
use App\Models\Guardian;
use App\Models\MedicalCertificate;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\AnnualHoursLimitLabels;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

class AbsenceWorkflowAndAuthorizationTest extends AbsenceFeatureTestCase
{
    public function test_full_absence_workflow_from_student_request_to_teacher_approval(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-02-12 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.workflow@example.test',
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Mario Gregorio',
            'email' => 'tutore.workflow@example.test',
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Padre',
            'is_primary' => true,
        ]);

        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.workflow@example.test',
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

        $createResponse = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-12',
            'hours' => 6,
            'reason_choice' => 'Motivi familiari',
            'motivation' => 'Influenza',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $absence = Absence::query()->firstOrFail();

        $this->assertSame(Absence::STATUS_REPORTED, Absence::normalizeStatus($absence->status));
        $this->assertTrue((bool) $absence->medical_certificate_required);
        $this->assertSame('2026-02-26', $absence->medical_certificate_deadline?->toDateString());
        $this->assertSame(0, $absence->medicalCertificates()->count());

        Mail::assertSent(GuardianAbsenceSignatureMail::class, 1);

        MedicalCertificate::query()->create([
            'absence_id' => $absence->id,
            'file_path' => 'certificati-medici/manuale.pdf',
            'uploaded_at' => now(),
            'valid' => false,
        ]);

        $token = AbsenceConfirmationToken::query()
            ->where('absence_id', $absence->id)
            ->firstOrFail();
        $plainToken = 'workflow-token-firma';
        $token->update([
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => Carbon::today()->addDays(2)->endOfDay(),
            'used_at' => null,
        ]);

        $signatureResponse = $this->post(
            route('guardian.absences.signature.store', ['token' => $plainToken]),
            [
                'full_name' => 'Mario Gregorio',
                'consent' => '1',
                'signature_data' => $this->validPngSignatureDataUri(),
            ]
        );

        $signatureResponse->assertStatus(302);
        $signatureResponse->assertRedirect(
            route('guardian.absences.signature.show', ['token' => $plainToken])
        );

        $this->assertDatabaseHas('guardian_absence_confirmations', [
            'absence_id' => $absence->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
        ]);

        $token->refresh();
        $this->assertNotNull($token->used_at);

        $acceptCertificateResponse = $this->actingAs($teacher)->post(
            route('teacher.absences.accept-certificate', $absence)
        );
        $acceptCertificateResponse->assertStatus(302);
        $acceptCertificateResponse->assertSessionHasNoErrors();

        $certificate = MedicalCertificate::query()
            ->where('absence_id', $absence->id)
            ->firstOrFail();
        $this->assertTrue((bool) $certificate->valid);
        $this->assertSame($teacher->id, $certificate->validated_by);

        $approveResponse = $this->actingAs($teacher)->post(
            route('teacher.absences.approve', $absence),
            [
                'comment' => 'Tutto regolare.',
            ]
        );
        $approveResponse->assertStatus(302);
        $approveResponse->assertSessionHasNoErrors();

        $absence->refresh();

        $this->assertSame(Absence::STATUS_JUSTIFIED, $absence->status);
        $this->assertFalse((bool) $absence->approved_without_guardian);
        $this->assertFalse((bool) $absence->counts_40_hours);
        $this->assertSame(
            AnnualHoursLimitLabels::certificateAcceptedComment(),
            $absence->counts_40_hours_comment
        );

        $this->assertInfoOperationLogExists('absence.request.created', 'absence', $absence->id);
        $this->assertInfoOperationLogExists('absence.guardian_confirmation_email.sent', 'absence', $absence->id);
        $this->assertInfoOperationLogExists('absence.guardian.signature.confirmed', 'absence', $absence->id);
        $this->assertInfoOperationLogExists('absence.certificate.accepted', 'medical_certificate', $certificate->id);
        $this->assertInfoOperationLogExists('absence.approved', 'absence', $absence->id);
    }

    public function test_absence_with_altro_custom_reason_counts_only_after_justification(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createAbsenceSetting();
        AbsenceReason::query()->create([
            'name' => 'Altro',
            'counts_40_hours' => false,
        ]);

        $student = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Altro',
            'role' => 'student',
            'email' => 'luca.altro@example.test',
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Tutore Luca',
            'email' => 'tutore.altro@example.test',
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Genitore',
            'is_primary' => true,
        ]);

        $createResponse = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'hours' => 2,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Appuntamento personale',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $absence = Absence::query()->firstOrFail();
        $this->assertSame('Altro - Appuntamento personale', (string) $absence->reason);
        $this->assertFalse($absence->resolveCounts40Hours());
        $this->assertSame(0, Absence::countHoursForStudent((int) $student->id));

        $absence->update([
            'status' => Absence::STATUS_JUSTIFIED,
        ]);
        $absence->refresh();

        $this->assertTrue($absence->resolveCounts40Hours());
        $this->assertSame(2, Absence::countHoursForStudent((int) $student->id));

        $this->assertInfoOperationLogExists('absence.request.created', 'absence', $absence->id);
        $this->assertInfoOperationLogExists('absence.guardian_confirmation_email.sent', 'absence', $absence->id);
    }

    public function test_dashboard_teacher_shows_justified_absence_with_uploaded_certificate_as_actionable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Alessio',
            'surname' => 'Certificato',
            'role' => 'student',
            'email' => 'alessio.certificato@example.test',
        ]);

        $teacher = User::factory()->create([
            'name' => 'Chiara',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'chiara.docente@example.test',
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

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-15',
            'end_date' => '2026-03-15',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 3,
            'medical_certificate_required' => true,
            'medical_certificate_deadline' => '2026-03-31',
        ]);

        MedicalCertificate::query()->create([
            'absence_id' => $absence->id,
            'file_path' => 'certificati-medici/in-verifica-dashboard.pdf',
            'uploaded_at' => Carbon::parse('2026-03-19 09:00:00'),
            'valid' => false,
        ]);

        $response = $this->actingAs($teacher)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Teacher')
            ->has('rows', 1)
            ->where('rows.0.absence_id', $absence->id)
            ->where('rows.0.stato_code', Absence::STATUS_JUSTIFIED)
            ->where('rows.0.can_accept_certificate', true)
            ->where('rows.0.can_reject_certificate', true)
        );
    }

    public function test_teacher_can_switch_absence_status_between_arbitrary_and_justified(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 09:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Sara',
            'surname' => 'Verdi',
            'role' => 'student',
            'email' => 'sara.switch@example.test',
        ]);

        $teacher = User::factory()->create([
            'name' => 'Marco',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.switch@example.test',
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

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'reason' => 'Assenza non giustificata',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 1,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-20',
        ]);

        $this->actingAs($teacher)->post(route('teacher.absences.update', $absence), [
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'hours' => 1,
            'motivation' => 'Assenza non giustificata',
            'status' => Absence::STATUS_JUSTIFIED,
            'counts_40_hours' => true,
            'comment' => 'Rettifica docente: assenza giustificata.',
        ])->assertSessionHasNoErrors();

        $absence->refresh();
        $this->assertSame(Absence::STATUS_JUSTIFIED, Absence::normalizeStatus($absence->status));
        $this->assertSame('Rettifica docente: assenza giustificata.', $absence->teacher_comment);

        $this->actingAs($teacher)->post(route('teacher.absences.update', $absence), [
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'hours' => 1,
            'motivation' => 'Assenza non giustificata',
            'status' => Absence::STATUS_ARBITRARY,
            'counts_40_hours' => true,
            'comment' => 'Nuova valutazione: torna arbitraria.',
        ])->assertSessionHasNoErrors();

        $absence->refresh();
        $this->assertSame(Absence::STATUS_ARBITRARY, Absence::normalizeStatus($absence->status));
        $this->assertSame('Nuova valutazione: torna arbitraria.', $absence->teacher_comment);

        $this->assertInfoOperationLogExists('absence.updated', 'absence', $absence->id);
    }

    public function test_teacher_cannot_delete_absence_but_laboratory_manager_can(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 09:00:00'));

        $this->createAbsenceSetting([
            'absence_countdown_days' => 7,
        ]);

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.delete-absence@example.test',
        ]);
        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.delete-absence@example.test',
        ]);
        $laboratoryManager = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Laboratorio',
            'role' => 'laboratory_manager',
            'email' => 'lab.delete-absence@example.test',
        ]);
        $class = SchoolClass::query()->create([
            'name' => 'INF',
            'section' => 'A',
            'year' => '1',
        ]);
        $class->students()->attach($student->id);
        $class->teachers()->attach($teacher->id);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'reason' => 'Motivi familiari',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 2,
            'medical_certificate_deadline' => '2026-04-10',
            'medical_certificate_required' => false,
            'approved_without_guardian' => false,
            'counts_40_hours' => true,
        ]);

        $teacherDeleteResponse = $this->actingAs($teacher)->delete(
            route('teacher.absences.destroy', $absence)
        );
        $teacherDeleteResponse->assertStatus(302);
        $teacherDeleteResponse->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('absences', ['id' => $absence->id]);

        $absenceForManager = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'reason' => 'Motivi familiari',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 2,
            'medical_certificate_deadline' => '2026-04-11',
            'medical_certificate_required' => false,
            'approved_without_guardian' => false,
            'counts_40_hours' => true,
        ]);

        $managerDeleteResponse = $this->actingAs($laboratoryManager)->delete(
            route('teacher.absences.destroy', $absenceForManager)
        );
        $managerDeleteResponse->assertStatus(302);
        $managerDeleteResponse->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('absences', ['id' => $absenceForManager->id]);
    }
}
