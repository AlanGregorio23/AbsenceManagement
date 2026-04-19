<?php

namespace Tests\Feature;

use App\Mail\AdultStudentGuardianInfoMail;
use App\Mail\DelayRuleTriggeredMail;
use App\Mail\GuardianAbsenceSignatureMail;
use App\Mail\GuardianDelaySignatureMail;
use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\Delay;
use App\Models\DelayConfirmationToken;
use App\Models\DelayEmailNotification;
use App\Models\DelayRule;
use App\Models\DelaySetting;
use App\Models\Guardian;
use App\Models\NotificationPreference;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class DelayWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $testDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->testDiskRoot = rtrim(sys_get_temp_dir(), '\\/')
            .DIRECTORY_SEPARATOR
            .'gestioneassenze-delay-workflow-'
            .uniqid('', true);
        File::ensureDirectoryExists($this->testDiskRoot);

        config()->set('filesystems.default', 'local');
        config()->set('filesystems.disks.local.root', $this->testDiskRoot);
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        app('filesystem')->forgetDisk('local');
        File::deleteDirectory($this->testDiskRoot);
        Carbon::setTestNow();

        parent::tearDown();
    }

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

    public function test_delay_guardian_signature_email_is_sent_when_teacher_registers_delay(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: true);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('guardian-signature');

        $createResponse = $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Traffico intenso',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();
        $this->assertSame(Delay::STATUS_REPORTED, Delay::normalizeStatus($delay->status));
        Mail::assertNotSent(GuardianDelaySignatureMail::class);
        $this->assertDatabaseCount('delay_confirmation_tokens', 0);

        $registerResponse = $this->actingAs($teacher)->post(
            route('teacher.delays.reject', $delay),
            [
                'comment' => 'Ritardo registrato.',
            ]
        );

        $registerResponse->assertStatus(302);
        $registerResponse->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);
        $this->assertTrue((bool) $delay->count_in_semester);

        Mail::assertSent(GuardianDelaySignatureMail::class, 1);
        Mail::assertSent(GuardianDelaySignatureMail::class, function (GuardianDelaySignatureMail $mail) use ($guardian) {
            return $mail->hasTo($guardian->email);
        });
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'guardian_signature_request',
            'recipient_email' => $guardian->email,
            'status' => 'sent',
        ]);
        $this->assertDatabaseCount('delay_confirmation_tokens', 1);
        $token = DelayConfirmationToken::query()->firstOrFail();
        $this->assertSame('2026-03-16', $token->expires_at?->toDateString());
        $this->assertSame('23:59:59', $token->expires_at?->format('H:i:s'));

        $this->assertInfoOperationLogExists('delay.request.created', 'delay', $delay->id);
        $this->assertInfoOperationLogExists('delay.rejected', 'delay', $delay->id);
        $this->assertInfoOperationLogExists('delay.guardian_confirmation_email.sent', 'delay', $delay->id);
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

    public function test_expired_delay_signature_token_triggers_new_email_with_new_token_after_five_days(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: true);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('token-expiry');

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Traffico intenso',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Registrato dal docente.',
        ])->assertSessionHasNoErrors();

        $initialToken = DelayConfirmationToken::query()->firstOrFail();
        $this->assertSame('2026-03-16', $initialToken->expires_at?->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-03-17 09:00:00'));

        $this->artisan('delays:resend-expired-signature-tokens')
            ->assertExitCode(0);

        $this->assertDatabaseCount('delay_confirmation_tokens', 2);
        $initialToken->refresh();
        $this->assertNotNull($initialToken->used_at);

        $latestToken = DelayConfirmationToken::query()->latest('id')->firstOrFail();
        $this->assertSame('2026-03-22', $latestToken->expires_at?->toDateString());
        $this->assertSame('23:59:59', $latestToken->expires_at?->format('H:i:s'));

        Mail::assertSent(GuardianDelaySignatureMail::class, 2);
        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'guardian_signature_resend',
            'recipient_email' => $guardian->email,
            'status' => 'sent',
        ]);
    }

    public function test_only_one_guardian_can_sign_the_same_delay(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: true);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createWorkflowActors('double-sign');

        $secondGuardian = Guardian::query()->create([
            'name' => 'Anna Gregorio',
            'email' => 'tutore.delay.double-sign.second@example.test',
        ]);
        $student->guardians()->attach($secondGuardian->id, [
            'relationship' => 'Madre',
            'is_primary' => false,
        ]);

        $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Traffico intenso',
        ])->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        $this->actingAs($teacher)->post(route('teacher.delays.reject', $delay), [
            'comment' => 'Registrato dal docente.',
        ])->assertSessionHasNoErrors();

        $firstGuardianToken = null;
        $secondGuardianToken = null;

        Mail::assertSent(GuardianDelaySignatureMail::class, function (GuardianDelaySignatureMail $mail) use (
            $guardian,
            &$firstGuardianToken
        ) {
            if (! $mail->hasTo($guardian->email)) {
                return false;
            }

            $firstGuardianToken = $this->extractTokenFromSignatureUrl($mail->signatureUrl);

            return true;
        });

        Mail::assertSent(GuardianDelaySignatureMail::class, function (GuardianDelaySignatureMail $mail) use (
            $secondGuardian,
            &$secondGuardianToken
        ) {
            if (! $mail->hasTo($secondGuardian->email)) {
                return false;
            }

            $secondGuardianToken = $this->extractTokenFromSignatureUrl($mail->signatureUrl);

            return true;
        });

        $this->assertNotNull($firstGuardianToken);
        $this->assertNotNull($secondGuardianToken);

        $this->post(route('guardian.delays.signature.store', ['token' => $firstGuardianToken]), [
            'full_name' => 'Mario Gregorio',
            'consent' => 1,
            'signature_data' => $this->validSignatureDataUrl(),
        ])->assertSessionHasNoErrors();

        $this->post(route('guardian.delays.signature.store', ['token' => $secondGuardianToken]), [
            'full_name' => 'Anna Gregorio',
            'consent' => 1,
            'signature_data' => $this->validSignatureDataUrl(),
        ])->assertSessionHasErrors('token');

        $this->assertDatabaseCount('guardian_delay_confirmations', 1);
        $this->assertDatabaseHas('guardian_delay_confirmations', [
            'delay_id' => $delay->id,
            'guardian_id' => $guardian->id,
            'status' => 'confirmed',
        ]);
        $this->assertSame(
            2,
            DelayConfirmationToken::query()->whereNotNull('used_at')->count()
        );
    }

    public function test_adult_student_can_notify_previous_guardians_when_reporting_a_delay(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-10 08:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: false);

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Maggiorenne',
            'role' => 'student',
            'email' => 'alan.adult.delay@example.test',
            'birth_date' => '2007-02-01',
        ]);

        $previousGuardian = Guardian::query()->create([
            'name' => 'Genitore Storico',
            'email' => 'genitore.storico.delay@example.test',
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

        $response = $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-04-10',
            'delay_minutes' => 8,
            'motivation' => 'Autobus in ritardo',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();

        Mail::assertSent(AdultStudentGuardianInfoMail::class, function (AdultStudentGuardianInfoMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });
        Mail::assertNotSent(GuardianDelaySignatureMail::class);

        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'inactive_guardian_reported_info',
            'recipient_email' => $previousGuardian->email,
            'status' => 'sent',
        ]);
    }

    private function assertInfoOperationLogExists(string $action, string $entity, ?int $entityId = null): void
    {
        $query = OperationLog::query()
            ->where('action', $action)
            ->where('entity', $entity)
            ->where('level', 'INFO');

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        $this->assertTrue(
            $query->exists(),
            sprintf(
                'Missing INFO operation log action [%s] entity [%s] entity_id [%s].',
                $action,
                $entity,
                $entityId !== null ? (string) $entityId : 'any'
            )
        );
    }

    private function createDelaySetting(
        bool $guardianSignatureRequired,
        bool $deadlineModeActive = false,
        int $deadlineBusinessDays = 5
    ): void {
        DelaySetting::query()->create([
            'minutes_threshold' => 15,
            'guardian_signature_required' => $guardianSignatureRequired,
            'deadline_active' => $deadlineModeActive,
            'deadline_business_days' => $deadlineBusinessDays,
            'justification_business_days' => 5,
            'pre_expiry_warning_business_days' => 1,
        ]);
    }

    private function createAbsenceSetting(bool $guardianSignatureRequired): void
    {
        AbsenceSetting::query()->create([
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'guardian_signature_required' => $guardianSignatureRequired,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => 5,
        ]);
    }

    /**
     * @return array{
     *     student: User,
     *     teacher: User,
     *     guardian: Guardian
     * }
     */
    private function createWorkflowActors(string $suffix): array
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => "alan.delay.$suffix@example.test",
            'birth_date' => '2008-03-11',
        ]);

        $guardian = Guardian::query()->create([
            'name' => 'Mario Gregorio',
            'email' => "tutore.delay.$suffix@example.test",
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Padre',
            'is_primary' => true,
        ]);

        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => "docente.delay.$suffix@example.test",
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

    private function extractTokenFromSignatureUrl(string $signatureUrl): string
    {
        return Str::afterLast($signatureUrl, '/');
    }

    private function validSignatureDataUrl(): string
    {
        return 'data:image/png;base64,'
            .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO9WlRkAAAAASUVORK5CYII=';
    }
}
