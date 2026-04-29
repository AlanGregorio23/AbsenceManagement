<?php

namespace Tests\Feature\Leaves;

use App\Jobs\Mail\GuardianLeaveSignatureMail;
use App\Models\Absence;
use App\Models\AbsenceReason;
use App\Models\Leave;
use App\Models\LeaveApproval;
use App\Models\LeaveConfirmationToken;
use App\Models\LeaveEmailNotification;
use App\Models\SchoolHoliday;
use App\Support\AnnualHoursLimitLabels;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

class LeaveWorkflowAndRulesTest extends LeaveFeatureTestCase
{
    public function test_leave_workflow_uses_db_rules_and_sends_emails_with_documentation_request(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-04 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 4);
        AbsenceReason::query()->create([
            'name' => 'Stage esterno',
            'counts_40_hours' => false,
        ]);

        [
            'student' => $student,
            'guardian' => $guardian,
            'laboratoryManager' => $laboratoryManager,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors();

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-04',
            'end_date' => '2026-03-05',
            'hours' => 6,
            'motivation' => 'Stage esterno',
            'destination' => 'Centro professionale esterno',
            'document' => UploadedFile::fake()->create(
                'congedo-stage.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $this->assertSame(Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE, Leave::normalizeStatus($leave->status));
        $this->assertFalse((bool) $leave->count_hours);
        $this->assertSame(AnnualHoursLimitLabels::ruleReasonComment(null, false), (string) $leave->count_hours_comment);
        $this->assertSame('Centro professionale esterno', (string) $leave->destination);
        $this->assertNull($leave->registered_absence_id);

        Mail::assertSent(GuardianLeaveSignatureMail::class, 1);
        $this->assertDatabaseHas('leave_email_notifications', [
            'leave_id' => $leave->id,
            'type' => 'guardian_signature_request',
            'recipient_email' => $guardian->email,
            'status' => 'sent',
        ]);

        $token = LeaveConfirmationToken::query()
            ->where('leave_id', $leave->id)
            ->where('guardian_id', $guardian->id)
            ->firstOrFail();
        $this->assertSame('2026-03-11', $token->expires_at?->toDateString());
        $this->assertSame('23:59:59', $token->expires_at?->format('H:i:s'));

        $plainToken = 'workflow-token-firma-congedo';
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
        $signatureResponse->assertRedirect(
            route('guardian.leaves.signature.show', ['token' => $plainToken])
        );

        $this->assertDatabaseHas('guardian_leave_confirmations', [
            'leave_id' => $leave->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
        ]);

        $leave->refresh();
        $this->assertSame(Leave::STATUS_SIGNED, Leave::normalizeStatus($leave->status));

        $token->refresh();
        $this->assertNotNull($token->used_at);

        $leave->update([
            'documentation_path' => null,
            'documentation_uploaded_at' => null,
        ]);

        $requestDocumentationResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.request-documentation', $leave),
            [
                'comment' => 'Carica il talloncino firmato dalla struttura.',
            ]
        );
        $requestDocumentationResponse->assertStatus(302);
        $requestDocumentationResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_DOCUMENTATION_REQUESTED, Leave::normalizeStatus($leave->status));
        $this->assertSame(
            'Carica il talloncino firmato dalla struttura.',
            (string) $leave->documentation_request_comment
        );
        $this->assertDatabaseHas('leave_email_notifications', [
            'leave_id' => $leave->id,
            'type' => 'documentation_requested_student',
            'recipient_email' => $student->email,
            'status' => 'sent',
        ]);

        $uploadDocumentationResponse = $this->actingAs($student)->post(
            route('student.leaves.documentation.upload', $leave),
            [
                'document' => UploadedFile::fake()->create(
                    'talloncino-medico.pdf',
                    128,
                    'application/pdf'
                ),
            ]
        );
        $uploadDocumentationResponse->assertStatus(302);
        $uploadDocumentationResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_IN_REVIEW, Leave::normalizeStatus($leave->status));
        $this->assertNotNull($leave->documentation_path);
        $this->assertNotNull($leave->documentation_uploaded_at);

        $approveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.approve', $leave),
            [
                'comment' => 'Congedo approvato dopo verifica documentazione.',
            ]
        );
        $approveResponse->assertStatus(302);
        $approveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_REGISTERED, $leave->status);
        $this->assertNotNull($leave->registered_absence_id);
        $this->assertFalse((bool) $leave->count_hours);
        $this->assertSame(AnnualHoursLimitLabels::ruleReasonComment(null, false), (string) $leave->count_hours_comment);
        $this->assertFalse((bool) $leave->approved_without_guardian);

        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);
        $this->assertSame($leave->id, $absence->derived_from_leave_id);
        $this->assertSame('2026-03-04', $absence->start_date?->toDateString());
        $this->assertSame('2026-03-05', $absence->end_date?->toDateString());
        $this->assertSame(6, (int) $absence->assigned_hours);
        $this->assertSame('Stage esterno', (string) $absence->reason);
        $this->assertFalse((bool) $absence->approved_without_guardian);
        $this->assertFalse((bool) $absence->counts_40_hours);
        $this->assertSame(
            AnnualHoursLimitLabels::ruleReasonComment(null, false),
            (string) $absence->counts_40_hours_comment
        );
        $this->assertFalse((bool) $absence->medical_certificate_required);
        $this->assertDatabaseHas('leave_email_notifications', [
            'leave_id' => $leave->id,
            'type' => 'registered_teacher',
            'recipient_email' => $teacher->email,
            'status' => 'sent',
        ]);
        $this->assertSame(
            3,
            LeaveEmailNotification::query()
                ->where('leave_id', $leave->id)
                ->where('status', 'sent')
                ->count()
        );

        $approvalDecisions = LeaveApproval::query()
            ->where('leave_id', $leave->id)
            ->pluck('decision')
            ->all();
        $this->assertContains('documentation_requested', $approvalDecisions);
        $this->assertContains('approved', $approvalDecisions);
        $this->assertContains('registered', $approvalDecisions);

        $this->assertInfoOperationLogExists('leave.request.created', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.guardian_confirmation_email.sent', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.guardian.signature.confirmed', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.documentation.requested', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.documentation.uploaded', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.approved', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered_as_absence', 'leave', $leave->id);
    }

    public function test_leave_can_be_approved_without_guardian_signature_override(): void
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
        ] = $this->createWorkflowActors(suffix: 'override');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'hours' => 4,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-famiglia.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();
        $this->assertSame(Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE, Leave::normalizeStatus($leave->status));
        $this->assertFalse((bool) $leave->approved_without_guardian);

        $preApproveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.pre-approve', $leave),
            [
                'comment' => 'Override autorizzato: urgenza, tutore non raggiungibile.',
            ]
        );
        $preApproveResponse->assertStatus(302);
        $preApproveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_REGISTERED, $leave->status);
        $this->assertNotNull($leave->registered_absence_id);
        $this->assertTrue((bool) $leave->approved_without_guardian);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_id' => $leave->id,
            'decision' => 'pre_approved',
            'override_guardian_signature' => 1,
        ]);

        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);
        $this->assertFalse((bool) $absence->approved_without_guardian);
        $this->assertTrue((bool) $absence->counts_40_hours);

        $this->assertDatabaseMissing('guardian_leave_confirmations', [
            'leave_id' => $leave->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_id' => $leave->id,
            'decision' => 'approved',
            'override_guardian_signature' => 1,
        ]);
        $this->assertDatabaseHas('leave_approvals', [
            'leave_id' => $leave->id,
            'decision' => 'registered',
            'override_guardian_signature' => 1,
        ]);

        $this->assertInfoOperationLogExists('leave.request.created', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.guardian_confirmation_email.sent', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.pre_approved', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.approved', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered_as_absence', 'leave', $leave->id);
    }

    public function test_lab_manager_must_comment_when_pre_approving_without_signature(): void
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
        ] = $this->createWorkflowActors(suffix: 'required-pre-approve-comment');

        $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'hours' => 4,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-famiglia.pdf',
                128,
                'application/pdf'
            ),
        ])->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $response = $this->actingAs($laboratoryManager)->post(
            route('leaves.pre-approve', $leave),
            [
                'comment' => '   ',
            ]
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors('comment');

        $leave->refresh();
        $this->assertSame(Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE, Leave::normalizeStatus($leave->status));
        $this->assertFalse((bool) $leave->approved_without_guardian);
    }

    public function test_lab_manager_must_comment_when_updating_leave(): void
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
        ] = $this->createWorkflowActors(suffix: 'required-update-comment');

        $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'hours' => 4,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-update.pdf',
                128,
                'application/pdf'
            ),
        ])->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $response = $this->actingAs($laboratoryManager)->post(route('leaves.update', $leave), [
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'hours' => 5,
            'motivation' => 'Motivi familiari',
            'destination' => 'Casa',
            'count_hours' => true,
            'comment' => '   ',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('comment');

        $leave->refresh();
        $this->assertSame(4, (int) $leave->requested_hours);
    }

    public function test_leave_with_altro_custom_reason_is_counted_in_40_hours_by_default(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);
        AbsenceReason::query()->create([
            'name' => 'Altro',
            'counts_40_hours' => false,
        ]);

        [
            'student' => $student,
            'laboratoryManager' => $laboratoryManager,
        ] = $this->createWorkflowActors(suffix: 'altro-default');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'hours' => 4,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Uscita personale urgente',
            'destination' => 'Casa',
            'document' => UploadedFile::fake()->create(
                'congedo-altro.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();
        $this->assertSame('Altro - Uscita personale urgente', (string) $leave->reason);
        $this->assertTrue((bool) $leave->count_hours);
        $this->assertNull($leave->count_hours_comment);

        $preApproveResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.pre-approve', $leave),
            [
                'comment' => 'Registrato con override per urgenza interna.',
            ]
        );
        $preApproveResponse->assertStatus(302);
        $preApproveResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_REGISTERED, $leave->status);
        $this->assertNotNull($leave->registered_absence_id);

        $absence = Absence::query()->findOrFail($leave->registered_absence_id);
        $this->assertSame(Absence::STATUS_DRAFT, $absence->status);
        $this->assertTrue((bool) $absence->counts_40_hours);
        $this->assertSame('Altro - Uscita personale urgente', (string) $absence->reason);

        $this->assertInfoOperationLogExists('leave.request.created', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.guardian_confirmation_email.sent', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.pre_approved', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.approved', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered', 'leave', $leave->id);
        $this->assertInfoOperationLogExists('leave.registered_as_absence', 'leave', $leave->id);
    }

    public function test_leave_special_reason_requires_management_consent_confirmation(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);
        AbsenceReason::query()->create([
            'name' => 'GSI',
            'counts_40_hours' => true,
            'requires_management_consent' => true,
        ]);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'special-consent');

        $withoutConsentResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-26',
            'hours' => 4,
            'reason_choice' => 'GSI',
            'motivation' => 'GSI',
            'management_consent_confirmed' => false,
            'destination' => 'Sede esterna',
            'document' => UploadedFile::fake()->create(
                'congedo-gsi-no-consenso.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $withoutConsentResponse->assertStatus(302);
        $withoutConsentResponse->assertSessionHasErrors(['management_consent_confirmed']);
        $this->assertSame(0, Leave::query()->count());
        Mail::assertNothingSent();

        $withConsentResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-26',
            'hours' => 4,
            'reason_choice' => 'GSI',
            'motivation' => 'GSI',
            'management_consent_confirmed' => true,
            'destination' => 'Sede esterna',
            'document' => UploadedFile::fake()->create(
                'congedo-gsi-consenso.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $withConsentResponse->assertStatus(302);
        $withConsentResponse->assertSessionHasNoErrors();
        $this->assertSame(1, Leave::query()->count());
        Mail::assertSent(GuardianLeaveSignatureMail::class, 1);
    }

    public function test_leave_creation_without_document_is_allowed_for_standard_reason(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 09:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'document-required');

        $response = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-27',
            'end_date' => '2026-03-27',
            'hours' => 2,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Impegno esterno',
            'destination' => 'Bellinzona',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Leave::query()->count());
        Mail::assertSent(GuardianLeaveSignatureMail::class, 1);
    }

    public function test_leave_creation_fails_when_notice_working_hours_are_not_respected(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 15:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7, leaveNoticeWorkingHours: 24);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'notice-fail');

        $response = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-26',
            'hours' => 2,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Richiesta tardiva',
            'destination' => 'Lugano',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['start_date']);
        $this->assertSame(0, Leave::query()->count());
    }

    public function test_leave_notice_working_hours_excludes_school_holidays(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-24 12:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7, leaveNoticeWorkingHours: 24);
        SchoolHoliday::query()->create([
            'holiday_date' => '2026-03-25',
            'school_year' => '2025-2026',
            'label' => 'Giornata vacanza straordinaria',
            'source' => SchoolHoliday::SOURCE_MANUAL,
        ]);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'notice-holiday');

        $response = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-26',
            'hours' => 2,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Richiesta con vacanza nel mezzo',
            'destination' => 'Lugano',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['start_date']);
        $this->assertSame(0, Leave::query()->count());
    }

    public function test_leave_creation_passes_when_notice_working_hours_are_respected(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-24 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7, leaveNoticeWorkingHours: 24);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'notice-pass');

        $response = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-26',
            'hours' => 2,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Richiesta puntuale',
            'destination' => 'Lugano',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Leave::query()->count());
        Mail::assertSent(GuardianLeaveSignatureMail::class, 1);
    }

    public function test_laboratory_manager_can_create_leave_for_student(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-24 08:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7, leaveNoticeWorkingHours: 24);

        [
            'student' => $student,
            'guardian' => $guardian,
            'laboratoryManager' => $laboratoryManager,
        ] = $this->createWorkflowActors(suffix: 'lab-create');

        $response = $this->actingAs($laboratoryManager)->post(route('lab.leaves.store'), [
            'student_id' => $student->id,
            'start_date' => '2026-03-25',
            'end_date' => '2026-03-25',
            'hours' => 2,
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Inserimento manuale da capo laboratorio',
            'destination' => 'Lugano',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('dashboard'));

        $leave = Leave::query()->firstOrFail();
        $this->assertSame($student->id, (int) $leave->student_id);
        $this->assertSame($laboratoryManager->id, (int) $leave->created_by);
        $this->assertSame(Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE, Leave::normalizeStatus($leave->status));

        Mail::assertSent(GuardianLeaveSignatureMail::class, function (GuardianLeaveSignatureMail $mail) use ($guardian) {
            return $mail->hasTo($guardian->email);
        });
        $this->assertInfoOperationLogExists('leave.request.created_by_laboratory_manager', 'leave', $leave->id);
    }

    public function test_leave_creation_accepts_school_periods_and_estimates_requested_hours(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 09:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'lesson-periods');

        $response = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-27',
            'lessons_start' => [1, 2, 4],
            'lessons_end' => [5, 7, 8],
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Uscita parziale',
            'destination' => 'Lugano',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $this->assertSame(6, (int) $leave->requested_hours);
        $this->assertSame([
            'start' => [1, 2, 4],
            'end' => [5, 7, 8],
        ], Leave::normalizeRequestedLessonsPayload($leave->requested_lessons));
    }

    public function test_leave_can_be_forwarded_to_management_and_exported_to_pdf(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 10:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);

        [
            'student' => $student,
            'laboratoryManager' => $laboratoryManager,
        ] = $this->createWorkflowActors(suffix: 'forward-management');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-27',
            'lessons_start' => [1, 2, 3],
            'lessons_end' => [8, 9],
            'reason_choice' => 'Altro',
            'motivation_custom' => 'Richiesta valutazione direzione',
            'destination' => 'Sede esterna',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $forwardResponse = $this->actingAs($laboratoryManager)->post(
            route('leaves.forward-to-management', $leave),
            [
                'comment' => 'Caso da valutare con la direzione scolastica.',
            ]
        );

        $forwardResponse->assertStatus(302);
        $forwardResponse->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame(Leave::STATUS_FORWARDED_TO_MANAGEMENT, $leave->status);
        $this->assertSame(
            'Caso da valutare con la direzione scolastica.',
            (string) $leave->workflow_comment
        );
        $this->assertDatabaseHas('leave_approvals', [
            'leave_id' => $leave->id,
            'decision' => 'forwarded_to_management',
        ]);
        $this->assertInfoOperationLogExists('leave.forwarded_to_management', 'leave', $leave->id);
        $notification = $student->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertSame(
            'student_leave_forwarded_to_management',
            $notification->data['event_key'] ?? null
        );
        $this->assertSame('Scarica', $notification->data['action_label'] ?? null);
        $this->assertSame('download', $notification->data['action_type'] ?? null);
        $this->assertSame(
            route('leaves.forwarding-pdf.download', $leave),
            $notification->data['url'] ?? null
        );

        $pdfResponse = $this->actingAs($laboratoryManager)->get(
            route('leaves.forwarding-pdf.download', $leave)
        );

        $pdfResponse->assertStatus(200);
        $pdfResponse->assertHeader('Content-Type', 'application/pdf');
        $pdfContent = (string) $pdfResponse->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfContent);
        $this->assertStringContainsString('INOLTRO RICHIESTA CONGEDO IN DIREZIONE', $pdfContent);
        $this->assertInfoOperationLogExists('leave.pdf.downloaded', 'leave', $leave->id);

        $studentPdfResponse = $this->actingAs($student)->get(
            route('leaves.forwarding-pdf.download', $leave)
        );
        $studentPdfResponse->assertStatus(200);
        $studentPdfResponse->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_teacher_cannot_delete_leave_but_laboratory_manager_can(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-26 09:00:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);

        [
            'student' => $student,
            'laboratoryManager' => $laboratoryManager,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors(suffix: 'delete-permissions');

        $createResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-27',
            'end_date' => '2026-03-27',
            'hours' => 2,
            'motivation' => 'Permesso personale',
            'destination' => 'Lugano',
        ]);
        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $leave = Leave::query()->firstOrFail();

        $teacherDeleteResponse = $this->actingAs($teacher)
            ->from(route('dashboard'))
            ->delete(route('leaves.destroy', $leave));
        $teacherDeleteResponse->assertStatus(302);
        $teacherDeleteResponse->assertSessionHasErrors(['leave']);

        $this->assertDatabaseHas('leaves', [
            'id' => $leave->id,
        ]);

        $managerDeleteResponse = $this->actingAs($laboratoryManager)
            ->from(route('dashboard'))
            ->delete(route('leaves.destroy', $leave));
        $managerDeleteResponse->assertStatus(302);
        $managerDeleteResponse->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('leaves', [
            'id' => $leave->id,
        ]);
    }

    public function test_special_reason_can_require_document_before_leave_submission(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-25 09:30:00'));

        $this->createAbsenceSetting(absenceCountdownDays: 7);
        AbsenceReason::query()->create([
            'name' => 'GSI con allegato',
            'counts_40_hours' => true,
            'requires_management_consent' => true,
            'requires_document_on_leave_creation' => true,
        ]);

        [
            'student' => $student,
        ] = $this->createWorkflowActors(suffix: 'special-document-required');

        $withoutDocumentResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-28',
            'end_date' => '2026-03-28',
            'hours' => 2,
            'reason_choice' => 'GSI con allegato',
            'motivation' => 'GSI con allegato',
            'management_consent_confirmed' => true,
            'destination' => 'Bellinzona',
        ]);

        $withoutDocumentResponse->assertStatus(302);
        $withoutDocumentResponse->assertSessionHasErrors(['document']);
        $this->assertSame(0, Leave::query()->count());
        Mail::assertNothingSent();

        $withDocumentResponse = $this->actingAs($student)->post(route('student.leaves.store'), [
            'start_date' => '2026-03-28',
            'end_date' => '2026-03-28',
            'hours' => 2,
            'reason_choice' => 'GSI con allegato',
            'motivation' => 'GSI con allegato',
            'management_consent_confirmed' => true,
            'destination' => 'Bellinzona',
            'document' => UploadedFile::fake()->create(
                'congedo-gsi-obbligatorio.pdf',
                128,
                'application/pdf'
            ),
        ]);

        $withDocumentResponse->assertStatus(302);
        $withDocumentResponse->assertSessionHasNoErrors();
        $this->assertSame(1, Leave::query()->count());
        Mail::assertSent(GuardianLeaveSignatureMail::class, 1);
    }
}
