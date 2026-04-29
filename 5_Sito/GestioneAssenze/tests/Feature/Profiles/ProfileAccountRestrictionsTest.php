<?php

namespace Tests\Feature\Profiles;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAccountRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_change_own_email_from_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Mario',
            'surname' => 'Rossi',
            'role' => 'student',
            'email' => 'mario.rossi@example.test',
        ]);

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Mario',
                'email' => 'nuova.mail@example.test',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertSame('mario.rossi@example.test', $user->email);
    }

    public function test_user_cannot_delete_own_account_from_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Bianchi',
            'role' => 'student',
            'email' => 'giulia.bianchi@example.test',
        ]);

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('account');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'giulia.bianchi@example.test',
        ]);
    }

    public function test_admin_cannot_change_own_email_from_admin_user_management(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin@example.test',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.users'))
            ->patch(route('admin.users.update', $admin), [
                'name' => 'Admin',
                'surname' => 'Root',
                'email' => 'self-change@example.test',
                'role' => 'admin',
                'birth_date' => null,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');

        $admin->refresh();
        $this->assertSame('admin@example.test', $admin->email);
    }
}
