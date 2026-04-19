<?php

namespace Tests\Feature;

use App\Models\AdminSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginSecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_login_security_settings_within_allowed_range(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.settings@example.test',
        ]);

        $payload = AdminSettings::forEdit();
        $payload['reasons'] = collect($payload['reasons'])->values()->all();
        $payload['delay_rules'] = collect($payload['delay_rules'])->values()->all();
        if ($payload['reasons'] === []) {
            $payload['reasons'] = [[
                'id' => null,
                'name' => 'Motivi familiari',
                'counts_40_hours' => true,
            ]];
        }
        if ($payload['delay_rules'] === []) {
            $payload['delay_rules'] = [[
                'id' => null,
                'min_delays' => 0,
                'max_delays' => null,
                'actions' => [
                    ['type' => 'none'],
                ],
                'info_message' => null,
            ]];
        }
        $payload['login']['max_attempts'] = 7;
        $payload['login']['decay_seconds'] = 900;
        $payload['login']['forgot_password_max_attempts'] = 9;
        $payload['login']['forgot_password_decay_seconds'] = 120;
        $payload['login']['reset_password_max_attempts'] = 10;
        $payload['login']['reset_password_decay_seconds'] = 180;

        $response = $this->actingAs($admin)
            ->post(route('admin.settings.update'), $payload);

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.settings'));

        $this->assertDatabaseHas('login_security_settings', [
            'max_attempts' => 7,
            'decay_seconds' => 900,
            'forgot_password_max_attempts' => 9,
            'forgot_password_decay_seconds' => 120,
            'reset_password_max_attempts' => 10,
            'reset_password_decay_seconds' => 180,
        ]);

        $this->assertDatabaseHas('operation_logs', [
            'user_id' => $admin->id,
            'entity' => 'settings',
            'action' => 'admin.settings.updated',
            'level' => 'INFO',
        ]);
    }

    public function test_admin_cannot_set_login_security_outside_safe_range(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.settings.invalid@example.test',
        ]);

        $payload = AdminSettings::forEdit();
        $payload['reasons'] = collect($payload['reasons'])->values()->all();
        $payload['delay_rules'] = collect($payload['delay_rules'])->values()->all();
        if ($payload['reasons'] === []) {
            $payload['reasons'] = [[
                'id' => null,
                'name' => 'Motivi familiari',
                'counts_40_hours' => true,
            ]];
        }
        if ($payload['delay_rules'] === []) {
            $payload['delay_rules'] = [[
                'id' => null,
                'min_delays' => 0,
                'max_delays' => null,
                'actions' => [
                    ['type' => 'none'],
                ],
                'info_message' => null,
            ]];
        }
        $payload['login']['max_attempts'] = 2;
        $payload['login']['decay_seconds'] = 30;
        $payload['login']['forgot_password_max_attempts'] = 2;
        $payload['login']['forgot_password_decay_seconds'] = 30;
        $payload['login']['reset_password_max_attempts'] = 2;
        $payload['login']['reset_password_decay_seconds'] = 30;

        $response = $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->post(route('admin.settings.update'), $payload);

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.settings'));
        $response->assertSessionHasErrors([
            'login.max_attempts',
            'login.decay_seconds',
            'login.forgot_password_max_attempts',
            'login.forgot_password_decay_seconds',
            'login.reset_password_max_attempts',
            'login.reset_password_decay_seconds',
        ]);
    }
}
