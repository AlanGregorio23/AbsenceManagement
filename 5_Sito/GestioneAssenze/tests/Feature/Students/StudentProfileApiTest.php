<?php

namespace Tests\Feature\Students;

use App\Models\Absence;
use App\Models\AbsenceSetting;
use App\Models\Delay;
use App\Models\DelayRule;
use App\Models\DelaySetting;
use App\Models\SchoolClass;
use App\Models\User;
use App\Models\UserStudentStatusSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_profile_api_aggregates_applicable_delay_rules_and_exposes_recovery_estimate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-13 08:00:00'));

        ['student' => $student, 'teacher' => $teacher] = $this->createActors();

        DelayRule::query()->create([
            'min_delays' => 4,
            'max_delays' => null,
            'actions' => [
                ['type' => 'conduct_penalty', 'detail' => '-0.5 nota di condotta'],
            ],
            'info_message' => null,
        ]);
        DelayRule::query()->create([
            'min_delays' => 5,
            'max_delays' => 6,
            'actions' => [
                ['type' => 'extra_activity_notice'],
            ],
            'info_message' => null,
        ]);

        foreach (range(1, 5) as $index) {
            Delay::query()->create([
                'student_id' => $student->id,
                'recorded_by' => $teacher->id,
                'delay_datetime' => Carbon::today()->subDays($index)->setTime(8, 0),
                'minutes' => 25,
                'notes' => 'Ingresso posticipato',
                'status' => Delay::STATUS_REGISTERED,
                'count_in_semester' => true,
                'global' => false,
                'converted_to_absence' => false,
            ]);
        }

        Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $teacher->id,
            'delay_datetime' => Carbon::parse('2025-11-21 08:00:00'),
            'minutes' => 40,
            'notes' => 'Ritardo semestre precedente',
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'global' => false,
            'converted_to_absence' => false,
        ]);

        $response = $this->actingAs($teacher)
            ->getJson(route('students.profile.api', $student));

        $response->assertOk();
        $response->assertJsonPath('data.stats.delays_registered_semester', 5);
        $response->assertJsonPath('data.stats.delays_outside_semester', 1);
        $response->assertJsonPath('data.stats.delays_unregistered_semester', 1);
        $response->assertJsonPath('data.delay_rule_insights.possible_conduct_penalty', true);
        $response->assertJsonPath(
            'data.delay_rule_insights.recovery_estimate.minutes_from_registered_delays',
            125
        );
        $response->assertJsonPath('data.delay_rule_insights.recovery_estimate.minutes', 180);
        $response->assertJsonPath('data.delay_rule_insights.recovery_estimate.activities_60_min', 3);
        $response->assertJsonPath('data.delay_rule_insights.semester_key', '2026-S2');
        $response->assertJsonPath('data.delay_rule_insights.semester_start', '2026-01-27');
        $response->assertJsonPath('data.delay_rule_insights.semester_end', '2026-07-31');

        $insights = $response->json('data.delay_rule_insights');
        $actions = collect($insights['actions'] ?? []);
        $actionLines = collect($insights['action_lines'] ?? []);

        $this->assertContains('-0.5 nota di condotta', $insights['conduct_penalty_details'] ?? []);
        $this->assertGreaterThan(0, (int) ($insights['recovery_estimate']['activities_60_min'] ?? 0));
        $this->assertContains('conduct_penalty', $actions->pluck('type')->all());
        $this->assertContains('extra_activity_notice', $actions->pluck('type')->all());
        $this->assertTrue(
            $actionLines->contains('Penalita condotta prevista: -0.5 nota di condotta')
        );
    }

    public function test_profile_api_uses_teacher_specific_status_thresholds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-13 08:00:00'));

        ['student' => $student, 'teacher' => $teacher] = $this->createActors();

        AbsenceSetting::query()->create([
            'max_annual_hours' => 40,
            'warning_threshold_hours' => 32,
            'guardian_signature_required' => true,
            'medical_certificate_days' => 3,
            'medical_certificate_max_days' => 5,
            'absence_countdown_days' => 10,
        ]);

        UserStudentStatusSetting::query()->create([
            'user_id' => $teacher->id,
            'absence_warning_percent' => 50,
            'absence_critical_percent' => 90,
            'delay_warning_percent' => 20,
            'delay_critical_percent' => 60,
        ]);

        DelayRule::query()->create([
            'min_delays' => 0,
            'max_delays' => 5,
            'actions' => [],
        ]);

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'reason' => 'Assenza',
            'status' => Absence::STATUS_ARBITRARY,
            'assigned_hours' => 20,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-21',
        ]);

        Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $teacher->id,
            'delay_datetime' => Carbon::today()->setTime(8, 0),
            'minutes' => 15,
            'notes' => 'Ingresso posticipato',
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'global' => false,
            'converted_to_absence' => false,
        ]);

        $response = $this->actingAs($teacher)
            ->getJson(route('students.profile.api', $student));

        $response->assertOk();
        $response->assertJsonPath('data.status.teacher_view.absence_code', 'yellow');
        $response->assertJsonPath('data.status.teacher_view.delay_code', 'yellow');
        $response->assertJsonPath('data.status_rules.teacher.absence_warning_percent', 50);
        $response->assertJsonPath('data.status_rules.teacher.delay_warning_percent', 20);
    }

    public function test_authorized_teacher_can_export_selected_student_profile_sections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-13 08:00:00'));

        ['student' => $student, 'teacher' => $teacher] = $this->createActors();

        Absence::query()->create([
            'student_id' => $student->id,
            'start_date' => '2026-03-11',
            'end_date' => '2026-03-11',
            'reason' => 'Medico',
            'status' => Absence::STATUS_JUSTIFIED,
            'assigned_hours' => 4,
            'counts_40_hours' => true,
            'medical_certificate_required' => false,
            'medical_certificate_deadline' => '2026-03-21',
        ]);

        Delay::query()->create([
            'student_id' => $student->id,
            'recorded_by' => $teacher->id,
            'delay_datetime' => Carbon::parse('2026-03-12 08:00:00'),
            'minutes' => 12,
            'notes' => 'Traffico',
            'status' => Delay::STATUS_REGISTERED,
            'count_in_semester' => true,
            'global' => false,
            'converted_to_absence' => false,
        ]);

        $response = $this->actingAs($teacher)->get(route('students.profile.export', [
            'student' => $student,
            'sections' => ['absences', 'delays'],
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]));

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('Assenze', $content);
        $this->assertStringContainsString('Ritardi', $content);
        $this->assertStringContainsString('A-0001', $content);
        $this->assertStringContainsString('R-0001', $content);
        $this->assertStringNotContainsString('Congedi', $content);
    }

    public function test_delay_semester_uses_configured_first_semester_end_date(): void
    {
        DelaySetting::query()->create([
            'minutes_threshold' => 15,
            'guardian_signature_required' => true,
            'deadline_active' => false,
            'deadline_business_days' => 5,
            'justification_business_days' => 5,
            'pre_expiry_warning_business_days' => 1,
            'pre_expiry_warning_percent' => 80,
            'first_semester_end_day' => 15,
            'first_semester_end_month' => 3,
        ]);

        $firstSemester = Delay::resolveSemester(Carbon::parse('2026-03-15 08:00:00'));
        $secondSemester = Delay::resolveSemester(Carbon::parse('2026-03-16 08:00:00'));

        $this->assertSame('2025-S1', $firstSemester->key);
        $this->assertSame('2025-08-01', $firstSemester->start->toDateString());
        $this->assertSame('2026-03-15', $firstSemester->end->toDateString());
        $this->assertSame('2026-S2', $secondSemester->key);
        $this->assertSame('2026-03-16', $secondSemester->start->toDateString());
        $this->assertSame('2026-07-31', $secondSemester->end->toDateString());
    }

    /**
     * @return array{student: User, teacher: User}
     */
    private function createActors(): array
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.profile@example.test',
            'birth_date' => '2008-03-11',
        ]);

        $teacher = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Docente',
            'role' => 'teacher',
            'email' => 'docente.profile@example.test',
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
        ];
    }
}
