<?php

namespace Tests\Feature\Delays;

use App\Models\AbsenceSetting;
use App\Models\DelaySetting;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Support\LocalDiskFeatureTestCase;

abstract class DelayFeatureTestCase extends LocalDiskFeatureTestCase
{
    protected function localTestDiskPrefix(): string
    {
        return 'gestioneassenze-delay-workflow-';
    }

    protected function createDelaySetting(
        bool $guardianSignatureRequired,
        bool $deadlineModeActive = false,
        int $deadlineBusinessDays = 5,
        int $preExpiryWarningPercent = 80
    ): DelaySetting {
        return DelaySetting::query()->create([
            'minutes_threshold' => 15,
            'guardian_signature_required' => $guardianSignatureRequired,
            'deadline_active' => $deadlineModeActive,
            'deadline_business_days' => $deadlineBusinessDays,
            'justification_business_days' => 5,
            'pre_expiry_warning_business_days' => 1,
            'pre_expiry_warning_percent' => $preExpiryWarningPercent,
        ]);
    }

    protected function createAbsenceSetting(bool $guardianSignatureRequired): AbsenceSetting
    {
        return AbsenceSetting::query()->create([
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'guardian_signature_required' => $guardianSignatureRequired,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => 5,
            'pre_expiry_warning_percent' => 80,
        ]);
    }

    /**
     * @return array{
     *     student: User,
     *     teacher: User,
     *     guardian: Guardian
     * }
     */
    protected function createWorkflowActors(string $suffix): array
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

    protected function extractTokenFromSignatureUrl(string $signatureUrl): string
    {
        return Str::afterLast($signatureUrl, '/');
    }
}
