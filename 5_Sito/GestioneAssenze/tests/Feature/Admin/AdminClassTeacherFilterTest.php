<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminClassTeacherFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_classes_by_teacher_name_text(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.class-teacher-filter@example.test',
        ]);

        $matchingTeacher = User::factory()->create([
            'name' => 'Paolo',
            'surname' => 'Rossi',
            'role' => 'teacher',
            'email' => 'paolo.rossi.class-filter@example.test',
        ]);

        $otherTeacher = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Bianchi',
            'role' => 'teacher',
            'email' => 'luca.bianchi.class-filter@example.test',
        ]);

        $matchingClass = SchoolClass::query()->create([
            'name' => 'INF4',
            'section' => 'A',
            'year' => '4',
            'active' => true,
        ]);

        $otherClass = SchoolClass::query()->create([
            'name' => 'ELE3',
            'section' => 'B',
            'year' => '3',
            'active' => true,
        ]);

        $matchingTeacher->teachingClasses()->attach($matchingClass->id, [
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => null,
        ]);

        $otherTeacher->teachingClasses()->attach($otherClass->id, [
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.classes', [
            'teacher' => 'paolo rossi',
        ]));

        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];
        $classes = collect($props['classi'] ?? []);

        $this->assertSame('paolo rossi', $props['filters']['teacher'] ?? null);
        $this->assertNotNull($classes->firstWhere('class_id', $matchingClass->id));
        $this->assertNull($classes->firstWhere('class_id', $otherClass->id));
    }
}
