<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserClassFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_users_by_partial_class_name(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.class-filter@example.test',
        ]);

        $matchingClass = SchoolClass::query()->create([
            'name' => 'INF4A',
            'section' => 'A',
            'year' => '4',
            'active' => true,
        ]);

        $otherClass = SchoolClass::query()->create([
            'name' => 'ELE3B',
            'section' => 'B',
            'year' => '3',
            'active' => true,
        ]);

        $matchingStudent = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Bernasconi',
            'role' => 'student',
            'email' => 'alan.class-filter@example.test',
        ]);

        $otherStudent = User::factory()->create([
            'name' => 'Luca',
            'surname' => 'Rossi',
            'role' => 'student',
            'email' => 'luca.class-filter@example.test',
        ]);

        $matchingStudent->classes()->attach($matchingClass->id, [
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => null,
        ]);

        $otherStudent->classes()->attach($otherClass->id, [
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users', [
            'class' => 'nf4',
        ]));

        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];
        $users = collect($props['utenti'] ?? []);

        $this->assertSame('nf4', $props['filters']['class'] ?? null);
        $this->assertNotNull($users->firstWhere('user_id', $matchingStudent->id));
        $this->assertNull($users->firstWhere('user_id', $otherStudent->id));
    }
}
