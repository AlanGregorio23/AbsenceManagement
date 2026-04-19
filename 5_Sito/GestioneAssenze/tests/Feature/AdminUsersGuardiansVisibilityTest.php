<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersGuardiansVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_management_includes_historical_guardians_and_preference_state(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.guardians.visibility@example.test',
        ]);

        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Maggiorenne',
            'role' => 'student',
            'email' => 'alan.guardians.visibility@example.test',
            'birth_date' => '2007-02-01',
        ]);

        $previousGuardian = Guardian::query()->create([
            'name' => 'Genitore Storico',
            'email' => 'genitore.storico.visibility@example.test',
        ]);
        $selfGuardian = Guardian::query()->create([
            'name' => 'Alan Maggiorenne',
            'email' => $student->email,
        ]);

        $student->allGuardians()->attach($previousGuardian->id, [
            'relationship' => 'Padre',
            'is_primary' => false,
            'is_active' => false,
            'deactivated_at' => Carbon::parse('2026-03-01 10:00:00'),
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

        $response = $this->actingAs($admin)->get(route('admin.users'));

        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];
        $studentRow = collect($props['utenti'] ?? [])->firstWhere('user_id', $student->id);

        $this->assertNotNull($studentRow);
        $this->assertTrue((bool) ($studentRow['notify_previous_guardians_enabled'] ?? false));
        $this->assertSame(1, (int) ($studentRow['tutori_attivi'] ?? 0));
        $this->assertSame(1, (int) ($studentRow['tutori_inattivi'] ?? 0));

        $guardians = collect($studentRow['tutori'] ?? []);
        $this->assertSame(2, $guardians->count());

        $previousGuardianRow = $guardians->firstWhere('email', $previousGuardian->email);
        $selfGuardianRow = $guardians->firstWhere('email', $selfGuardian->email);

        $this->assertNotNull($previousGuardianRow);
        $this->assertNotNull($selfGuardianRow);
        $this->assertFalse((bool) ($previousGuardianRow['is_active'] ?? true));
        $this->assertTrue((bool) ($selfGuardianRow['is_active'] ?? false));
    }
}
