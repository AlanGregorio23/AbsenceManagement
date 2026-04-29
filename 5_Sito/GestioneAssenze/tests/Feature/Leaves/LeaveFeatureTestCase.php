<?php

namespace Tests\Feature\Leaves;

use App\Models\AbsenceSetting;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Tests\Feature\Support\LocalDiskFeatureTestCase;

abstract class LeaveFeatureTestCase extends LocalDiskFeatureTestCase
{
    protected function localTestDiskPrefix(): string
    {
        return 'gestioneassenze-leave-workflow-';
    }

    protected function createAbsenceSetting(
        int $absenceCountdownDays,
        int $leaveNoticeWorkingHours = 0
    ): AbsenceSetting {
        return AbsenceSetting::query()->create([
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'guardian_signature_required' => true,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => $absenceCountdownDays,
            'leave_request_notice_working_hours' => $leaveNoticeWorkingHours,
        ]);
    }

    /**
     * @return array{
     *     student: User,
     *     guardian: Guardian,
     *     laboratoryManager: User,
     *     teacher: User
     * }
     */
    protected function createWorkflowActors(string $suffix = 'main'): array
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => "alan.leave.$suffix@example.test",
        ]);
        $guardian = Guardian::query()->create([
            'name' => 'Mario Gregorio',
            'email' => "tutore.leave.$suffix@example.test",
        ]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => 'Padre',
            'is_primary' => true,
        ]);

        $laboratoryManager = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Laboratorio',
            'role' => 'laboratory_manager',
            'email' => "lab.leave.$suffix@example.test",
        ]);

        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => "docente.leave.$suffix@example.test",
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
            'guardian' => $guardian,
            'laboratoryManager' => $laboratoryManager,
            'teacher' => $teacher,
        ];
    }
}
