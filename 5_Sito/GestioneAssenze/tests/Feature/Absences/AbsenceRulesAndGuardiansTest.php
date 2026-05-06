<?php

namespace Tests\Feature\Absences;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Jobs\Mail\GuardianAbsenceSignatureMail;
use App\Models\Absence;
use App\Models\Guardian;
use App\Models\GuardianAbsenceConfirmation;
use App\Models\Leave;
use App\Models\MedicalCertificate;
use App\Models\NotificationPreference;
use App\Models\SchoolClass;
use App\Models\SchoolHoliday;
use App\Models\User;
use App\Support\AnnualHoursLimitLabels;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

class AbsenceRulesAndGuardiansTest extends AbsenceFeatureTestCase
{
    public function test_justified_absence_with_unvalidated_required_certificate_becomes_arbitrary_after_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 09:00:00'));

        $absenceSetting = $this->createAbsenceSetting([
            'medical_certificate_days' => 2,
            'absence_countdown_days' => 5,
        ]);

        $student = User::factory()->create([
            'name' => 'Marco',
            'surname' => 'Studente',
            'role' => 'student',
            'email' => 'studente.scadenza.certificato@example.test',
        ]);
        $teacher = User::factory()->create([
            'name' => 'Laura',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.scadenza.certificato@example.test',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-03',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 12,
            'counts_40_hours' => false,
            'counts_40_hours_comment' => AnnualHoursLimitLabels::ruleReasonComment(),
            'medical_certificate_required' => true,
            'medical_certificate_deadline' => '2026-03-08',
            'hours_decided_at' => Carbon::parse('2026-03-04 10:00:00'),
            'hours_decided_by' => $teacher->id,
        ]);

        MedicalCertificate::query()->create([
            'absence_id' => $absence->id,
            'file_path' => 'certificati-medici/in-verifica.pdf',
            'uploaded_at' => Carbon::parse('2026-03-05 12:00:00'),
            'valid' => false,
        ]);

        $updated = Absence::applyAutomaticArbitrary();

        $this->assertSame(1, $updated);

        $absence->refresh();
        $this->assertSame(Absence::STATUS_ARBITRARY, Absence::normalizeStatus($absence->status));
        $this->assertNotNull($absence->auto_arbitrary_at);
        $this->assertTrue((bool) $absence->counts_40_hours);
        $this->assertSame(
            'Assenza impostata come arbitraria per scadenza del termine.',
            $absence->counts_40_hours_comment
        );
        $this->assertNull($absence->hours_decided_by);
        $this->assertNull($absence->hours_decided_at);
        $this->assertTrue($absence->resolveCounts40Hours());

        $certificateRequirement = $absence->resolveCertificateRequirementStatus($absenceSetting);
        $this->assertSame('required_overdue', $certificateRequirement['code']);
        $this->assertSame('Certificato scaduto', $certificateRequirement['label']);
        $this->assertSame('Scaduto', $certificateRequirement['short_label']);
    }

    public function test_justified_absence_with_validated_required_certificate_is_not_marked_arbitrary_after_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 09:00:00'));

        $this->createAbsenceSetting([
            'medical_certificate_days' => 2,
            'absence_countdown_days' => 5,
        ]);

        $student = User::factory()->create([
            'name' => 'Giovanni',
            'surname' => 'Studente',
            'role' => 'student',
            'email' => 'studente.certificato.validato@example.test',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-03',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 12,
            'medical_certificate_required' => true,
            'medical_certificate_deadline' => '2026-03-08',
        ]);

        MedicalCertificate::query()->create([
            'absence_id' => $absence->id,
            'file_path' => 'certificati-medici/validato.pdf',
            'uploaded_at' => Carbon::parse('2026-03-05 12:00:00'),
            'valid' => true,
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Tutore Giovanni',
            'email' => 'tutore.giovanni@example.test',
        ]);
        GuardianAbsenceConfirmation::query()->create([
            'absence_id' => $absence->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-03-06 08:00:00'),
            'signed_at' => Carbon::parse('2026-03-06 08:00:00'),
        ]);

        $updated = Absence::applyAutomaticArbitrary();

        $this->assertSame(0, $updated);

        $absence->refresh();
        $this->assertSame(Absence::STATUS_JUSTIFIED, Absence::normalizeStatus($absence->status));
        $this->assertNull($absence->auto_arbitrary_at);
    }

    public function test_medical_certificate_upload_is_blocked_after_deadline_calculated_from_settings(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-10 08:00:00'));

        $this->createAbsenceSetting([
            'medical_certificate_days' => 1,
            'absence_countdown_days' => 1,
        ]);

        $student = User::factory()->create([
            'name' => 'Paolo',
            'surname' => 'Studente',
            'role' => 'student',
            'email' => 'studente.upload.deadline@example.test',
        ]);

        $createResponse = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-10',
            'hours' => 4,
            'motivation' => 'Malattia',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $absence = Absence::query()->firstOrFail();
        $this->assertSame('2026-04-13', $absence->medical_certificate_deadline?->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-04-14 09:00:00'));

        $uploadResponse = $this->actingAs($student)->post(
            route('student.absences.certificate.upload', $absence),
            [
                'document' => UploadedFile::fake()->create('certificato.pdf', 120, 'application/pdf'),
            ]
        );

        $uploadResponse->assertStatus(302);
        $uploadResponse->assertSessionHasErrors('document');

        $absence->refresh();
        $this->assertSame(Absence::STATUS_ARBITRARY, Absence::normalizeStatus($absence->status));
        $this->assertSame(0, $absence->medicalCertificates()->count());
    }

    public function test_medical_certificate_deadline_excludes_configured_school_holidays(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-10 08:00:00'));

        $this->createAbsenceSetting([
            'medical_certificate_days' => 1,
            'absence_countdown_days' => 3,
        ]);
        SchoolHoliday::query()->create([
            'holiday_date' => '2026-04-13',
            'school_year' => '2025-2026',
            'label' => 'Ponte straordinario',
            'source' => SchoolHoliday::SOURCE_MANUAL,
        ]);

        $student = User::factory()->create([
            'name' => 'Paolo',
            'surname' => 'Festivita',
            'role' => 'student',
            'email' => 'paolo.holiday.deadline@example.test',
        ]);

        $createResponse = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-10',
            'hours' => 4,
            'motivation' => 'Malattia',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $absence = Absence::query()->firstOrFail();
        $this->assertSame('2026-04-16', $absence->medical_certificate_deadline?->toDateString());
    }

    public function test_stale_saved_deadline_without_manual_extension_is_recalculated_from_current_rules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 08:00:00'));

        $this->createAbsenceSetting([
            'absence_countdown_days' => 5,
        ]);

        $student = User::factory()->create([
            'name' => 'Paolo',
            'surname' => 'Scadenza',
            'role' => 'student',
            'email' => 'paolo.scadenza.ricalcolo@example.test',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-16',
            'end_date' => '2026-04-16',
            'reason' => 'Dentista generale',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 5,
            'medical_certificate_deadline' => '2026-04-30',
            'deadline_extended_at' => null,
            'deadline_extended_by' => null,
        ]);

        $this->assertSame('2026-04-23', $absence->resolveMedicalCertificateDeadline()->toDateString());

        $absence->syncMedicalCertificateDeadline();
        $absence->refresh();

        $this->assertSame('2026-04-23', $absence->medical_certificate_deadline?->toDateString());

        $item = collect((new Absence)->getAbsence($student))
            ->firstWhere('absence_id', $absence->id);

        $this->assertNotNull($item);
        $this->assertSame('23 Apr 2026', $item['scadenza']);
        $this->assertSame('1 giorno lavorativo', $item['countdown']);
    }

    public function test_manual_absence_deadline_extension_is_preserved_over_recalculated_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-22 08:00:00'));

        $this->createAbsenceSetting([
            'absence_countdown_days' => 5,
        ]);

        $student = User::factory()->create([
            'name' => 'Paolo',
            'surname' => 'Proroga',
            'role' => 'student',
            'email' => 'paolo.proroga.assenza@example.test',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-16',
            'end_date' => '2026-04-16',
            'reason' => 'Dentista generale',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 5,
            'medical_certificate_deadline' => '2026-04-30',
            'deadline_extended_at' => Carbon::parse('2026-04-17 09:00:00'),
            'deadline_extended_by' => 1,
        ]);

        $this->assertTrue($absence->hasManualDeadlineExtension());
        $this->assertSame('2026-04-30', $absence->resolveMedicalCertificateDeadline()->toDateString());
    }

    public function test_student_can_upload_medical_certificate_on_justified_absence_within_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00'));

        $this->createAbsenceSetting([
            'medical_certificate_days' => 1,
            'absence_countdown_days' => 10,
        ]);

        $student = User::factory()->create([
            'name' => 'Nina',
            'surname' => 'Studente',
            'role' => 'student',
            'email' => 'nina.upload.justified@example.test',
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

        $response = $this->actingAs($student)->post(
            route('student.absences.certificate.upload', $absence),
            [
                'document' => UploadedFile::fake()->create('certificato.pdf', 120, 'application/pdf'),
            ]
        );

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, $absence->medicalCertificates()->count());
    }

    public function test_reported_absence_with_validated_certificate_without_guardian_signature_becomes_arbitrary_after_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 09:00:00'));

        $absenceSetting = $this->createAbsenceSetting([
            'medical_certificate_days' => 2,
            'absence_countdown_days' => 5,
        ]);

        $student = User::factory()->create([
            'name' => 'Elia',
            'surname' => 'Studente',
            'role' => 'student',
            'email' => 'elia.scadenza.firma@example.test',
        ]);
        $teacher = User::factory()->create([
            'name' => 'Lucia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.elia@example.test',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-03',
            'reason' => 'Malattia',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 9,
            'counts_40_hours' => false,
            'medical_certificate_required' => true,
            'medical_certificate_deadline' => '2026-03-08',
        ]);

        MedicalCertificate::query()->create([
            'absence_id' => $absence->id,
            'file_path' => 'certificati-medici/validato-senza-firma.pdf',
            'uploaded_at' => Carbon::parse('2026-03-05 12:00:00'),
            'valid' => true,
            'validated_by' => $teacher->id,
            'validated_at' => Carbon::parse('2026-03-05 12:05:00'),
        ]);

        $updated = Absence::applyAutomaticArbitrary();

        $this->assertSame(1, $updated);

        $absence->refresh();
        $this->assertSame(Absence::STATUS_ARBITRARY, Absence::normalizeStatus($absence->status));
        $this->assertTrue((bool) $absence->counts_40_hours);
        $this->assertTrue($absence->resolveCounts40Hours());

        $certificateRequirement = $absence->resolveCertificateRequirementStatus($absenceSetting);
        $this->assertSame('required_done', $certificateRequirement['code']);
    }

    public function test_single_day_absence_requires_certificate_when_student_exceeds_max_annual_hours(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-02 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Marta',
            'surname' => 'Soglia',
            'role' => 'student',
            'email' => 'marta.soglia@example.test',
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Assenza pregressa',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 40,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-30',
        ]);

        $response = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'hours' => 1,
            'reason_choice' => 'Motivi familiari',
            'motivation' => 'Assenza breve',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $absence = Absence::query()
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Absence::STATUS_REPORTED, Absence::normalizeStatus($absence->status));
        $this->assertTrue((bool) $absence->medical_certificate_required);
    }

    public function test_teacher_approval_recomputes_certificate_requirement_when_hours_limit_is_exceeded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Marta',
            'surname' => 'Soglia',
            'role' => 'student',
            'email' => 'marta.approvazione.soglia@example.test',
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Tutore Marta',
            'email' => 'tutore.marta.soglia@example.test',
        ]);
        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.approvazione.soglia@example.test',
        ]);
        $class = SchoolClass::query()->create([
            'name' => 'INF',
            'year' => '1',
            'section' => 'A',
            'active' => true,
        ]);
        $class->students()->attach($student->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ]);
        $class->teachers()->attach($teacher->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Assenza pregressa',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 39,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-30',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'reason' => 'Assenza breve',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 4,
            'counts_40_hours' => false,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-04-15',
        ]);

        GuardianAbsenceConfirmation::query()->create([
            'absence_id' => $absence->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-04-03 07:30:00'),
            'signed_at' => Carbon::parse('2026-04-03 07:30:00'),
        ]);

        $this->actingAs($teacher)->post(route('teacher.absences.approve', $absence), [
            'comment' => 'Approvata con firma tutore.',
        ])->assertSessionHasNoErrors();

        $absence->refresh();
        $this->assertSame(Absence::STATUS_JUSTIFIED, Absence::normalizeStatus($absence->status));
        $this->assertTrue((bool) $absence->medical_certificate_required);
    }

    public function test_teacher_approval_keeps_certificate_not_required_for_absence_derived_from_leave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Congedo',
            'role' => 'student',
            'email' => 'luca.derived.approval@example.test',
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Tutore Luca',
            'email' => 'tutore.luca.derived@example.test',
        ]);
        $teacher = User::factory()->create([
            'name' => 'Marco',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.derived.approval@example.test',
        ]);
        $class = SchoolClass::query()->create([
            'name' => 'INF',
            'year' => '1',
            'section' => 'B',
            'active' => true,
        ]);
        $class->students()->attach($student->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ]);
        $class->teachers()->attach($teacher->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Assenza pregressa',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 39,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-30',
        ]);

        $leave = Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $student->id,
            'created_at_custom' => now(),
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'requested_hours' => 4,
            'reason' => 'Congedo personale',
            'status' => Leave::STATUS_SIGNED,
            'count_hours' => true,
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'derived_from_leave_id' => $leave->id,
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'reason' => 'Bozza da congedo',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 4,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-04-15',
        ]);

        GuardianAbsenceConfirmation::query()->create([
            'absence_id' => $absence->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-04-03 07:30:00'),
            'signed_at' => Carbon::parse('2026-04-03 07:30:00'),
        ]);

        $this->actingAs($teacher)->post(route('teacher.absences.approve', $absence), [
            'comment' => 'Approvata con firma tutore.',
        ])->assertSessionHasNoErrors();

        $absence->refresh();
        $this->assertSame(Absence::STATUS_JUSTIFIED, Absence::normalizeStatus($absence->status));
        $this->assertFalse((bool) $absence->medical_certificate_required);
    }

    public function test_single_day_absence_does_not_require_certificate_when_only_open_leave_reaches_40_hours(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-02 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Elena',
            'surname' => 'Congedo',
            'role' => 'student',
            'email' => 'elena.congedo.40h@example.test',
        ]);

        Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $student->id,
            'created_at_custom' => now(),
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'requested_hours' => 40,
            'reason' => 'Congedo che rientra nel limite annuale',
            'status' => Leave::STATUS_APPROVED,
            'count_hours' => true,
        ]);

        $response = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'hours' => 1,
            'reason_choice' => 'Motivi familiari',
            'motivation' => 'Assenza breve oltre soglia',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $absence = Absence::query()
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Absence::STATUS_REPORTED, Absence::normalizeStatus($absence->status));
        $this->assertFalse((bool) $absence->medical_certificate_required);
    }

    public function test_absence_derived_from_leave_keeps_certificate_not_required_even_over_max_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 08:00:00'));

        $absenceSetting = $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Congedo',
            'role' => 'student',
            'email' => 'luca.congedo@example.test',
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-15',
            'end_date' => '2026-03-15',
            'reason' => 'Assenza pregressa',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 40,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-25',
        ]);

        $leave = Leave::query()->create([
            'student_id' => $student->id,
            'created_by' => $student->id,
            'created_at_custom' => now(),
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'requested_hours' => 2,
            'reason' => 'Congedo breve',
            'status' => Leave::STATUS_APPROVED,
            'count_hours' => true,
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'derived_from_leave_id' => $leave->id,
            'start_date' => '2026-04-02',
            'end_date' => '2026-04-02',
            'reason' => 'Derivata da congedo',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 2,
            'counts_40_hours' => true,
            'medical_certificate_required' => true,
            'medical_certificate_deadline' => '2026-04-10',
        ]);

        $this->assertFalse($absence->resolveMedicalCertificateRequired($absenceSetting));

        $absence->syncMedicalCertificateRequired($absenceSetting);
        $absence->refresh();

        $this->assertFalse((bool) $absence->medical_certificate_required);
    }

    public function test_adult_student_can_inform_previous_guardians_without_giving_them_signature_access(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-08 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Maggiorenne',
            'role' => 'student',
            'email' => 'alan.adult.absence@example.test',
            'birth_date' => '2007-02-01',
        ]);

        $previousGuardian = Guardian::query()->create([
            'name' => 'Genitore Storico',
            'email' => 'genitore.storico.absence@example.test',
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

        $response = $this->actingAs($student)->post(route('student.absences.store'), [
            'start_date' => '2026-04-07',
            'end_date' => '2026-04-07',
            'hours' => 2,
            'motivation' => 'Visita medica',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $absence = Absence::query()->firstOrFail();

        Mail::assertSent(GuardianAbsenceSignatureMail::class, function (GuardianAbsenceSignatureMail $mail) use ($student) {
            return $mail->hasTo($student->email);
        });
        Mail::assertNotSent(GuardianAbsenceSignatureMail::class, function (GuardianAbsenceSignatureMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });
        Mail::assertSent(AdultStudentGuardianInfoMail::class, function (AdultStudentGuardianInfoMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });

        $this->assertDatabaseHas('absence_email_notifications', [
            'absence_id' => $absence->id,
            'type' => 'inactive_guardian_info',
            'recipient_email' => $previousGuardian->email,
            'status' => 'sent',
        ]);
    }

    public function test_adult_previous_guardian_is_informed_even_when_absence_is_auto_marked_arbitrary(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-15 08:00:00'));

        $this->createAbsenceSetting([
            'absence_countdown_days' => 1,
        ]);

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Maggiorenne',
            'role' => 'student',
            'email' => 'pvl278@student.edu.ti.ch',
            'birth_date' => '2007-02-01',
        ]);

        $previousGuardian = Guardian::query()->create([
            'name' => 'Alan Gregorio',
            'email' => 'alan.gregorio@icloud.com',
        ]);
        $selfGuardian = Guardian::query()->create([
            'name' => 'Alan Gregorio',
            'email' => $student->email,
        ]);

        $student->allGuardians()->attach($previousGuardian->id, [
            'relationship' => 'Se stesso',
            'is_primary' => false,
            'is_active' => false,
            'deactivated_at' => now()->subDay(),
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

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'reason' => 'Assenza retrodatata',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 2,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-04-02',
        ]);

        Absence::applyAutomaticArbitrary();
        $absence->refresh();

        $this->assertSame(Absence::STATUS_ARBITRARY, Absence::normalizeStatus($absence->status));
        Mail::assertSent(AdultStudentGuardianInfoMail::class, function (AdultStudentGuardianInfoMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });
        $this->assertDatabaseHas('absence_email_notifications', [
            'absence_id' => $absence->id,
            'type' => 'inactive_guardian_info',
            'recipient_email' => $previousGuardian->email,
            'status' => 'sent',
        ]);
    }

    public function test_arbitrary_absence_is_not_marked_certificate_overdue_before_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-02 08:00:00'));

        $this->createAbsenceSetting();

        $student = User::factory()->create([
            'name' => 'Michele',
            'surname' => 'Scadenza',
            'role' => 'student',
            'email' => 'michele.scadenza@example.test',
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'reason' => 'Assenza pregressa',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 39,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-31',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'reason' => 'Assenza manuale',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 2,
            'counts_40_hours' => true,
            'medical_certificate_required' => true,
            'medical_certificate_deadline' => '2026-04-10',
        ]);

        $status = $absence->resolveCertificateRequirementStatus();

        $this->assertSame('required_pending', $status['code']);
    }

    public function test_student_sees_absence_deadline_and_receives_warning_before_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-07 08:00:00'));

        $this->createAbsenceSetting([
            'guardian_signature_required' => false,
            'medical_certificate_days' => 0,
            'absence_countdown_days' => 5,
            'pre_expiry_warning_percent' => 80,
        ]);

        $student = User::factory()->create([
            'name' => 'Sara',
            'surname' => 'Scadenza',
            'role' => 'student',
            'email' => 'sara.scadenza.assenza@example.test',
        ]);

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'reason' => 'Assenza con scadenza',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 4,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-04-08',
        ]);

        $updated = Absence::applyAutomaticArbitrary();

        $this->assertSame(0, $updated);
        $this->assertDatabaseHas('absence_email_notifications', [
            'absence_id' => $absence->id,
            'type' => 'student_deadline_warning_80_2026-04-08',
            'recipient_email' => $student->email,
            'status' => 'sent',
        ]);

        $studentNotification = $student->notifications()->latest()->first();
        $this->assertNotNull($studentNotification);
        $this->assertSame('student_absence_deadline_warning', $studentNotification->data['event_key'] ?? null);
        $this->assertSame('A-0001', $studentNotification->data['reference_code'] ?? null);
        $this->assertSame('2026-04-08', $studentNotification->data['deadline_date'] ?? null);

        $response = $this->actingAs($student)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Student')
            ->has('assenze', 1)
            ->where('assenze.0.id', 'A-0001')
            ->where('assenze.0.scadenza', '08 Apr 2026')
        );
    }
}
