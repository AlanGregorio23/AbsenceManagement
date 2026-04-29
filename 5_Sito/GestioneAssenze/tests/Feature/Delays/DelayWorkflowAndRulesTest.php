<?php

namespace Tests\Feature\Delays;

use App\Jobs\Mail\DelayRuleTriggeredMail;
use App\Jobs\Mail\GuardianAbsenceSignatureMail;
use App\Jobs\Mail\GuardianDelaySignatureMail;
use App\Models\Absence;
use App\Models\Delay;
use App\Models\DelayEmailNotification;
use App\Models\DelayRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

class DelayWorkflowAndRulesTest extends DelayFeatureTestCase
{
    public function test_teacher_can_justify_reported_delay_even_without_guardian_signature(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: true);
        $this->createAbsenceSetting(guardianSignatureRequired: true);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('full');

        $createResponse = $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 18,
            'motivation' => 'Treno in ritardo',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->assertSame(Delay::STATUS_REPORTED, Delay::normalizeStatus($delay->status));
        $this->assertFalse((bool) $delay->converted_to_absence);
        $this->assertNull($delay->converted_absence_id);
        $this->assertNull($delay->justification_deadline);
        $this->assertFalse((bool) $delay->count_in_semester);

        Mail::assertNotSent(GuardianDelaySignatureMail::class);
        Mail::assertNotSent(GuardianAbsenceSignatureMail::class);

        $this->assertDatabaseMissing('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'guardian_signature_request',
            'recipient_email' => $guardian->email,
        ]);
        $this->assertDatabaseCount('delay_confirmation_tokens', 0);

        $approveResponse = $this->actingAs($teacher)->post(
            route('teacher.delays.approve', $delay),
            [
                'comment' => 'Direzione: ritardo giustificato e non conteggiato.',
            ]
        );

        $approveResponse->assertStatus(302);
        $approveResponse->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_JUSTIFIED, $delay->status);
        $this->assertSame(
            'Direzione: ritardo giustificato e non conteggiato.',
            $delay->teacher_comment
        );
        $this->assertFalse((bool) $delay->count_in_semester);
        $this->assertSame($teacher->id, (int) $delay->validated_by);
        Mail::assertNotSent(GuardianDelaySignatureMail::class);

        $this->assertInfoOperationLogExists('delay.request.created', 'delay', $delay->id);
        $this->assertInfoOperationLogExists('delay.approved', 'delay', $delay->id);
    }

    public function test_teacher_must_comment_when_registering_reported_delay(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors('required-register-comment');

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Bus perso',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $response = $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => '   ',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('comment');

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REPORTED, Delay::normalizeStatus($delay->status));
        $this->assertNull($delay->teacher_comment);
    }

    public function test_delay_rule_notifications_are_sent_only_after_teacher_registers_the_delay(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);
        DelayRule::query()->create([
            'min_delays' => 1,
            'max_delays' => 1,
            'actions' => [
                ['type' => 'notify_guardian'],
                ['type' => 'notify_student'],
                ['type' => 'notify_teacher'],
            ],
            'info_message' => 'Notifiche inviate dopo registrazione.',
        ]);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('register');

        $createResponse = $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Bus perso',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();
        $this->assertSame(Delay::STATUS_REPORTED, Delay::normalizeStatus($delay->status));

        $this->assertSame(
            0,
            DelayEmailNotification::query()
                ->where('delay_id', $delay->id)
                ->where('type', 'delay_rule_notification')
                ->count()
        );

        $registerResponse = $this->actingAs($teacher)->post(
            route('teacher.delays.reject', $delay),
            [
                'comment' => 'Ritardo registrato e conteggiato.',
            ]
        );

        $registerResponse->assertStatus(302);
        $registerResponse->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);

        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'delay_rule_notification',
            'recipient_email' => $guardian->email,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'delay_rule_notification',
            'recipient_email' => $student->email,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'delay_rule_notification',
            'recipient_email' => $teacher->email,
            'status' => 'sent',
        ]);

        Mail::assertSent(DelayRuleTriggeredMail::class, 3);

        $this->assertInfoOperationLogExists('delay.request.created', 'delay', $delay->id);
        $this->assertInfoOperationLogExists('delay.rejected', 'delay', $delay->id);
        $this->assertInfoOperationLogExists('delay.rule.applied', 'delay', $delay->id);
    }

    public function test_automatic_delay_registration_does_not_update_related_absence_when_deadline_expires(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 09:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);
        $this->createAbsenceSetting(guardianSignatureRequired: true);

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors('auto');

        $absence = Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'reason' => 'Ora di assenza da ritardo oltre soglia',
            'status' => Absence::STATUS_REPORTED,
            'assigned_hours' => 1,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
        ]);

        $delay = Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $student->id,
            'delay_datetime' => '2026-03-10 08:00:00',
            'minutes' => 25,
            'justification_deadline' => '2026-03-17',
            'notes' => 'Ingresso tardivo',
            'status' => Delay::STATUS_REPORTED,
            'count_in_semester' => false,
            'global' => false,
        ]);

        $updated = Delay::applyAutomaticArbitrary();

        $this->assertSame(1, $updated);

        $delay->refresh();
        $absence->refresh();

        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);
        $this->assertNotNull($delay->auto_arbitrary_at);

        $this->assertSame(Absence::STATUS_REPORTED, $absence->status);
        $this->assertTrue((bool) $absence->counts_40_hours);
        $this->assertNull($absence->counts_40_hours_comment);

        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'auto_registered_student',
            'recipient_email' => $student->email,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'auto_registered_teacher',
            'recipient_email' => $teacher->email,
            'status' => 'pending',
        ]);
    }

    public function test_teacher_applies_actions_from_all_overlapping_delay_rules(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 08:30:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);

        DelayRule::query()->create([
            'min_delays' => 1,
            'max_delays' => 1,
            'actions' => [
                ['type' => 'notify_student'],
            ],
            'info_message' => null,
        ]);
        DelayRule::query()->create([
            'min_delays' => 1,
            'max_delays' => null,
            'actions' => [
                ['type' => 'notify_guardian'],
            ],
            'info_message' => null,
        ]);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('overlap-rules');

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Treno in ritardo',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Registrato dal docente.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'delay_rule_notification',
            'recipient_email' => $student->email,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'delay_rule_notification',
            'recipient_email' => $guardian->email,
            'status' => 'sent',
        ]);

        Mail::assertSent(DelayRuleTriggeredMail::class, 2);
    }

    public function test_editing_an_already_registered_delay_does_not_send_rule_notifications_again(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 10:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);
        DelayRule::query()->create([
            'min_delays' => 1,
            'max_delays' => null,
            'actions' => [
                ['type' => 'notify_student'],
            ],
            'info_message' => 'Avviso ritardo.',
        ]);

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors('dedupe-rule-email');

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 8,
            'motivation' => 'Bus in ritardo',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Prima registrazione.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DelayEmailNotification::query()
            ->where('delay_id', $delay->id)
            ->where('type', 'delay_rule_notification')
            ->where('recipient_email', $student->email)
            ->where('status', 'sent')
            ->count());
        Mail::assertSent(DelayRuleTriggeredMail::class, 1);

        $this->actingAs($teacher)->post(route('teacher.delays.update', $delay), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 8,
            'motivation' => 'Bus in ritardo',
            'status' => Delay::STATUS_REGISTERED,
        ])->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);
        $this->assertSame(1, DelayEmailNotification::query()
            ->where('delay_id', $delay->id)
            ->where('type', 'delay_rule_notification')
            ->where('recipient_email', $student->email)
            ->where('status', 'sent')
            ->count());
        Mail::assertSent(DelayRuleTriggeredMail::class, 1);
    }

    public function test_teacher_can_switch_registered_delay_back_to_justified_and_remove_it_from_count(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 11:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors('switch-status');

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Ingresso in ritardo',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Ritardo registrato.',
        ])->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);
        $this->assertSame(1, Delay::countRegisteredInSemester((int) $student->id, Carbon::today()));

        $this->actingAs($teacher)->post(route('teacher.delays.update', $delay), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Ingresso in ritardo',
            'status' => Delay::STATUS_JUSTIFIED,
        ])->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_JUSTIFIED, $delay->status);
        $this->assertFalse((bool) $delay->count_in_semester);
        $this->assertSame(0, Delay::countRegisteredInSemester((int) $student->id, Carbon::today()));
    }

    public function test_deadline_mode_registered_delay_uses_absence_like_actions_without_conversion(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->createDelaySetting(
            guardianSignatureRequired: true,
            deadlineModeActive: true,
            deadlineBusinessDays: 3
        );

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('deadline-mode-flow');

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 10,
            'motivation' => 'Treno in ritardo',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Ritardo registrato dal docente.',
        ])->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);
        $this->assertSame('2026-03-16', $delay->justification_deadline?->toDateString());
        $this->assertDatabaseCount('delay_confirmation_tokens', 1);

        $stateBeforeSignature = (new Delay)->getDelay($teacher)->firstWhere('delay_id', $delay->id);
        $this->assertNotNull($stateBeforeSignature);
        $this->assertFalse((bool) ($stateBeforeSignature['can_approve'] ?? false));
        $this->assertTrue((bool) ($stateBeforeSignature['can_approve_without_guardian'] ?? false));
        $this->assertTrue((bool) ($stateBeforeSignature['can_reject'] ?? false));
        $this->assertFalse((bool) ($stateBeforeSignature['can_extend_deadline'] ?? false));

        $signatureToken = null;
        Mail::assertSent(GuardianDelaySignatureMail::class, function (GuardianDelaySignatureMail $mail) use (
            $guardian,
            &$signatureToken
        ) {
            if (! $mail->hasTo($guardian->email)) {
                return false;
            }

            $signatureToken = $this->extractTokenFromSignatureUrl($mail->signatureUrl);

            return true;
        });

        $this->assertNotNull($signatureToken);

        $this->post(route('guardian.delays.signature.store', ['token' => $signatureToken]), [
            'full_name' => 'Mario Gregorio',
            'consent' => 1,
            'signature_data' => $this->validSignatureDataUrl(),
        ])->assertSessionHasNoErrors();

        $stateAfterSignature = (new Delay)->getDelay($teacher)->firstWhere('delay_id', $delay->id);
        $this->assertNotNull($stateAfterSignature);
        $this->assertTrue((bool) ($stateAfterSignature['can_approve'] ?? false));
        $this->assertFalse((bool) ($stateAfterSignature['can_approve_without_guardian'] ?? false));
        $this->assertTrue((bool) ($stateAfterSignature['can_reject'] ?? false));

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Ritardo rifiutato dal docente.',
        ])->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);
        $this->assertTrue(Carbon::parse($delay->justification_deadline)->lt(Carbon::today()));

        $stateArbitrary = (new Delay)->getDelay($teacher)->firstWhere('delay_id', $delay->id);
        $this->assertNotNull($stateArbitrary);
        $this->assertFalse((bool) ($stateArbitrary['can_approve'] ?? false));
        $this->assertFalse((bool) ($stateArbitrary['can_reject'] ?? false));
        $this->assertTrue((bool) ($stateArbitrary['can_extend_deadline'] ?? false));

        $this->actingAs($teacher)->post(route('teacher.delays.extend-deadline', $delay), [
            'extension_days' => 2,
            'comment' => 'Proroga concessa.',
        ])->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertTrue(Carbon::parse($delay->justification_deadline)->gte(Carbon::today()));

        $stateAfterExtend = (new Delay)->getDelay($teacher)->firstWhere('delay_id', $delay->id);
        $this->assertNotNull($stateAfterExtend);
        $this->assertFalse((bool) ($stateAfterExtend['can_extend_deadline'] ?? false));
        $this->assertTrue((bool) ($stateAfterExtend['can_approve'] ?? false));
    }

    public function test_student_sees_registered_delay_deadline_and_receives_warning_before_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-13 08:00:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
        ] = $this->createWorkflowActors('student-deadline-warning');

        $this->createDelaySetting(
            guardianSignatureRequired: true,
            deadlineModeActive: true,
            deadlineBusinessDays: 3,
            preExpiryWarningPercent: 80
        );

        $delay = Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $student->id,
            'delay_datetime' => '2026-03-11 08:00:00',
            'minutes' => 10,
            'justification_deadline' => '2026-03-16',
            'notes' => 'Treno in ritardo',
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'global' => false,
            'validated_at' => Carbon::parse('2026-03-11 09:00:00'),
            'validated_by' => $teacher->id,
        ]);

        $updated = Delay::applyAutomaticArbitrary();

        $this->assertSame(0, $updated);
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'student_deadline_warning_80_2026-03-16',
            'recipient_email' => $student->email,
            'status' => 'sent',
        ]);

        $studentNotification = $student->notifications()->latest()->first();
        $this->assertNotNull($studentNotification);
        $this->assertSame('student_delay_deadline_warning', $studentNotification->data['event_key'] ?? null);
        $this->assertSame('R-0001', $studentNotification->data['reference_code'] ?? null);
        $this->assertSame('2026-03-16', $studentNotification->data['deadline_date'] ?? null);

        $response = $this->actingAs($student)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Student')
            ->has('assenze', 1)
            ->where('assenze.0.id', 'R-0001')
            ->where('assenze.0.tipo', 'Ritardo')
            ->where('assenze.0.scadenza', '16 Mar 2026')
        );
    }
}
