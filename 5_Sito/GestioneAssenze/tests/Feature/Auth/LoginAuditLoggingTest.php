<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_admin_login_is_logged_with_admin_action(): void
    {
        $user = User::factory()->create([
            'email' => 'login.success.admin@example.test',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(302);

        $this->assertDatabaseHas('operation_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'auth.login.admin.succeeded',
            'level' => 'INFO',
        ]);
    }

    public function test_successful_teacher_login_is_logged_with_standard_action(): void
    {
        $user = User::factory()->create([
            'email' => 'login.success.teacher@example.test',
            'role' => 'teacher',
            'active' => true,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(302);

        $this->assertDatabaseHas('operation_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'auth.login.succeeded',
            'level' => 'INFO',
        ]);
    }

    public function test_failed_admin_login_is_logged(): void
    {
        $user = User::factory()->create([
            'email' => 'login.failed.admin@example.test',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'password-errata',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('operation_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'auth.login.admin.failed_invalid_credentials',
            'level' => 'INFO',
        ]);
    }

    public function test_failed_teacher_login_is_not_logged(): void
    {
        $user = User::factory()->create([
            'email' => 'login.failed.teacher@example.test',
            'role' => 'teacher',
            'active' => true,
        ]);

        $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'password-errata',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('operation_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'auth.login.failed_invalid_credentials',
            'level' => 'INFO',
        ]);
    }

    public function test_rate_limited_admin_login_attempt_is_logged(): void
    {
        config([
            'auth.login.max_attempts' => 3,
            'auth.login.max_attempts_per_ip' => 50,
            'auth.login.decay_seconds' => 300,
        ]);

        $user = User::factory()->create([
            'email' => 'login.blocked.admin@example.test',
            'role' => 'admin',
            'active' => true,
        ]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->from(route('login'))->post(route('login'), [
                'email' => $user->email,
                'password' => 'password-errata',
            ]);
        }

        $this->assertDatabaseHas('operation_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'auth.login.admin.blocked_rate_limited',
            'level' => 'INFO',
        ]);
    }
}
