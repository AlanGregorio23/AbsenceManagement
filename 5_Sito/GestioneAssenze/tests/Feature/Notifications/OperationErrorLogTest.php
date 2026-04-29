<?php

namespace Tests\Feature\Notifications;

use App\Models\Delay;
use App\Models\DelaySetting;
use App\Models\Guardian;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class OperationErrorLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_delay_guardian_signature_email_failure_is_logged_as_error(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-11 09:00:00'));

        $this->createDelaySetting(guardianSignatureRequired: true);

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ] = $this->createDelayWorkflowActors('error-log');

        $createResponse = $this->actingAs($student)->post(route('student.delays.store'), [
            'delay_date' => '2026-03-11',
            'delay_minutes' => 9,
            'motivation' => 'Treno in ritardo',
        ]);

        $createResponse->assertStatus(302);
        $createResponse->assertSessionHasNoErrors();

        $delay = Delay::query()->firstOrFail();
        $this->assertSame(Delay::STATUS_REPORTED, Delay::normalizeStatus($delay->status));

        $exceptionMessage = 'Simulated delay guardian mail outage';

        Mail::shouldReceive('to')
            ->once()
            ->with($guardian->email)
            ->andReturnSelf();
        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException($exceptionMessage));

        $registerResponse = $this->actingAs($teacher)->post(
            route('teacher.delays.reject', $delay),
            [
                'comment' => 'Ritardo registrato dal docente.',
            ]
        );

        $registerResponse->assertStatus(302);
        $registerResponse->assertSessionHasNoErrors();

        $delay->refresh();
        $this->assertSame(Delay::STATUS_REGISTERED, $delay->status);

        $errorLog = OperationLog::query()
            ->where('level', 'ERROR')
            ->where('action', 'delay.guardian_confirmation_email.failed')
            ->where('entity', 'delay')
            ->where('entity_id', $delay->id)
            ->first();

        $this->assertNotNull($errorLog);
        $this->assertSame($guardian->id, data_get($errorLog?->payload, 'guardian_id'));
        $this->assertSame($guardian->email, data_get($errorLog?->payload, 'guardian_email'));
        $this->assertStringContainsString(
            $exceptionMessage,
            (string) data_get($errorLog?->payload, 'error')
        );

        $this->assertDatabaseHas('delay_email_notifications', [
            'delay_id' => $delay->id,
            'type' => 'guardian_signature_request',
            'recipient_email' => $guardian->email,
            'status' => 'failed',
        ]);
    }

    public function test_majority_transition_email_failure_is_logged_as_error(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10 07:00:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $previousGuardian,
        ] = $this->createMajorityScenario('error-log', '2008-03-10');

        $exceptionMessage = 'Simulated majority transition mail outage';

        Mail::shouldReceive('to')
            ->times(3)
            ->andReturnSelf();
        Mail::shouldReceive('send')
            ->times(3)
            ->andThrow(new RuntimeException($exceptionMessage));

        $this->artisan('students:promote-adult-guardian')
            ->assertExitCode(0);

        $student->refresh();
        $student->load('guardians');
        $this->assertCount(1, $student->guardians);
        $this->assertSame(
            strtolower($student->email),
            strtolower((string) $student->guardians->first()?->email)
        );

        $expectedRecipients = [
            strtolower($student->email),
            strtolower($previousGuardian->email),
            strtolower($teacher->email),
        ];

        $errorLogs = OperationLog::query()
            ->where('level', 'ERROR')
            ->where('action', 'student.guardian.self_assignment_email.failed')
            ->where('entity', 'student')
            ->where('entity_id', $student->id)
            ->get();

        $this->assertCount(3, $errorLogs);
        $this->assertEqualsCanonicalizing(
            $expectedRecipients,
            $errorLogs
                ->pluck('payload.recipient_email')
                ->map(fn ($email) => strtolower((string) $email))
                ->values()
                ->all()
        );

        foreach ($errorLogs as $errorLog) {
            $this->assertStringContainsString(
                $exceptionMessage,
                (string) data_get($errorLog->payload, 'error')
            );
            $this->assertNotEmpty(data_get($errorLog->payload, 'recipient_roles'));
        }
    }

    private function createDelaySetting(bool $guardianSignatureRequired): void
    {
        DelaySetting::query()->create([
            'minutes_threshold' => 15,
            'guardian_signature_required' => $guardianSignatureRequired,
            'justification_business_days' => 5,
            'pre_expiry_warning_business_days' => 1,
        ]);
    }

    /**
     * @return array{
     *     student: User,
     *     teacher: User,
     *     guardian: Guardian
     * }
     */
    private function createDelayWorkflowActors(string $suffix): array
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
            'name' => 'AE',
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

    /**
     * @return array{
     *     student: User,
     *     teacher: User,
     *     guardian: Guardian
     * }
     */
    private function createMajorityScenario(string $suffix, string $birthDate): array
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => "alan.majority.$suffix@example.test",
            'birth_date' => $birthDate,
        ]);

        $guardian = Guardian::query()->create([
            'name' => 'Mario Gregorio',
            'email' => "tutore.majority.$suffix@example.test",
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Padre',
            'is_primary' => true,
        ]);

        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => "docente.majority.$suffix@example.test",
        ]);

        $class = SchoolClass::query()->create([
            'name' => 'AM',
            'year' => '4',
            'section' => 'I',
            'active' => true,
        ]);
        $class->students()->attach($student->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        $class->teachers()->attach($teacher->id, [
            'start_date' => Carbon::today()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        return [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $guardian,
        ];
    }
}
