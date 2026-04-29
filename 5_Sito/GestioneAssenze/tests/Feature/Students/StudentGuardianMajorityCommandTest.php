<?php

namespace Tests\Feature\Students;

use App\Jobs\Mail\AdultGuardianTransitionMail;
use App\Models\Guardian;
use App\Models\OperationLog;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentGuardianMajorityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_updates_guardian_and_notifies_teacher_previous_guardian_and_student(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-10 07:00:00'));

        [
            'student' => $studentTurning18,
            'teacher' => $classTeacher,
            'guardian' => $previousGuardian,
        ] = $this->createStudentScenario('due', '2008-03-10');
        [
            'student' => $studentNotDue,
            'guardian' => $guardianNotDue,
        ] = $this->createStudentScenario('not-due', '2008-03-11');

        $this->artisan('students:promote-adult-guardian')
            ->assertExitCode(0);

        $studentTurning18->refresh();
        $studentTurning18->load('guardians');

        $this->assertCount(1, $studentTurning18->guardians);
        $newGuardian = $studentTurning18->guardians->first();
        $this->assertNotNull($newGuardian);
        $this->assertSame(
            strtolower($studentTurning18->email),
            strtolower((string) $newGuardian->email)
        );
        $this->assertSame('Se stesso', (string) ($newGuardian->pivot?->relationship ?? ''));
        $this->assertTrue((bool) ($newGuardian->pivot?->is_primary ?? false));

        $this->assertDatabaseHas('guardian_student', [
            'student_id' => $studentTurning18->id,
            'guardian_id' => $previousGuardian->id,
            'is_active' => 0,
            'is_primary' => 0,
        ]);
        $this->assertDatabaseHas('guardian_student', [
            'student_id' => $studentTurning18->id,
            'guardian_id' => $newGuardian->id,
            'is_active' => 1,
        ]);

        $studentNotDue->refresh();
        $studentNotDue->load('guardians');
        $this->assertCount(1, $studentNotDue->guardians);
        $this->assertSame($guardianNotDue->id, (int) $studentNotDue->guardians->first()->id);

        Mail::assertSent(AdultGuardianTransitionMail::class, 3);
        Mail::assertSent(AdultGuardianTransitionMail::class, function (AdultGuardianTransitionMail $mail) use ($studentTurning18) {
            return $mail->hasTo($studentTurning18->email);
        });
        Mail::assertSent(AdultGuardianTransitionMail::class, function (AdultGuardianTransitionMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });
        Mail::assertSent(AdultGuardianTransitionMail::class, function (AdultGuardianTransitionMail $mail) use ($classTeacher) {
            return $mail->hasTo($classTeacher->email);
        });

        $this->assertInfoOperationLogCount('student.guardian.self_assigned', 'student', 1, $studentTurning18->id);
        $this->assertInfoOperationLogCount('student.guardian.self_assignment_email.sent', 'student', 3, $studentTurning18->id);
        $this->assertInfoOperationLogCount('student.guardian.self_assigned', 'student', 0, $studentNotDue->id);
        $this->assertInfoOperationLogCount('student.guardian.self_assignment_email.sent', 'student', 0, $studentNotDue->id);
    }

    public function test_command_is_idempotent_when_executed_twice_on_same_day(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-10 07:00:00'));

        [
            'student' => $studentTurning18,
            'guardian' => $previousGuardian,
        ] = $this->createStudentScenario('idempotent', '2008-03-10');

        $this->artisan('students:promote-adult-guardian')->assertExitCode(0);
        $this->artisan('students:promote-adult-guardian')->assertExitCode(0);

        $studentTurning18->refresh();
        $studentTurning18->load('guardians');
        $this->assertCount(1, $studentTurning18->guardians);
        $this->assertSame(
            strtolower($studentTurning18->email),
            strtolower((string) $studentTurning18->guardians->first()->email)
        );

        $this->assertDatabaseHas('guardian_student', [
            'student_id' => $studentTurning18->id,
            'guardian_id' => $previousGuardian->id,
            'is_active' => 0,
            'is_primary' => 0,
        ]);

        Mail::assertSent(AdultGuardianTransitionMail::class, 3);

        $this->assertInfoOperationLogCount('student.guardian.self_assigned', 'student', 1, $studentTurning18->id);
        $this->assertInfoOperationLogCount('student.guardian.self_assignment_email.sent', 'student', 3, $studentTurning18->id);
    }

    public function test_command_processes_students_already_older_than_18_after_birth_date_update(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-11 07:00:00'));

        [
            'student' => $student,
            'teacher' => $teacher,
            'guardian' => $previousGuardian,
        ] = $this->createStudentScenario('birthdate-updated', '2008-03-20');

        $student->forceFill([
            'birth_date' => '2007-02-28',
        ])->save();

        $this->artisan('students:promote-adult-guardian')
            ->assertExitCode(0);

        $student->refresh();
        $student->load('guardians');
        $this->assertCount(1, $student->guardians);

        $newGuardian = $student->guardians->first();
        $this->assertNotNull($newGuardian);
        $this->assertSame(
            strtolower($student->email),
            strtolower((string) $newGuardian->email)
        );

        $this->assertDatabaseHas('guardian_student', [
            'student_id' => $student->id,
            'guardian_id' => $previousGuardian->id,
            'is_active' => 0,
            'is_primary' => 0,
        ]);
        $this->assertDatabaseHas('guardian_student', [
            'student_id' => $student->id,
            'guardian_id' => $newGuardian->id,
            'is_active' => 1,
        ]);

        Mail::assertSent(AdultGuardianTransitionMail::class, 3);
        Mail::assertSent(AdultGuardianTransitionMail::class, function (AdultGuardianTransitionMail $mail) use ($student) {
            return $mail->hasTo($student->email);
        });
        Mail::assertSent(AdultGuardianTransitionMail::class, function (AdultGuardianTransitionMail $mail) use ($previousGuardian) {
            return $mail->hasTo($previousGuardian->email);
        });
        Mail::assertSent(AdultGuardianTransitionMail::class, function (AdultGuardianTransitionMail $mail) use ($teacher) {
            return $mail->hasTo($teacher->email);
        });

        $this->assertInfoOperationLogCount('student.guardian.self_assigned', 'student', 1, $student->id);
        $this->assertInfoOperationLogCount('student.guardian.self_assignment_email.sent', 'student', 3, $student->id);
    }

    private function assertInfoOperationLogCount(
        string $action,
        string $entity,
        int $expectedCount,
        ?int $entityId = null
    ): void {
        $query = OperationLog::query()
            ->where('action', $action)
            ->where('entity', $entity)
            ->where('level', 'INFO');

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        $this->assertSame(
            $expectedCount,
            $query->count(),
            sprintf(
                'Unexpected INFO operation log count for action [%s] entity [%s] entity_id [%s].',
                $action,
                $entity,
                $entityId !== null ? (string) $entityId : 'any'
            )
        );
    }

    /**
     * @return array{
     *     student: User,
     *     teacher: User,
     *     guardian: Guardian
     * }
     */
    private function createStudentScenario(string $suffix, string $birthDate): array
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
