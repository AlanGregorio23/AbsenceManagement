<?php

namespace Tests\Feature\Delays;

use App\Jobs\Mail\AdultStudentGuardianInfoMail;
use App\Jobs\Mail\GuardianDelaySignatureMail;
use App\Models\Delay;
use App\Models\DelayConfirmationToken;
use App\Models\Guardian;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class DelayGuardianNotificationsTest extends DelayFeatureTestCase
{
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
}
