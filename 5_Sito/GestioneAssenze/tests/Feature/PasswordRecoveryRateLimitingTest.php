<?php

namespace Tests\Feature;

use App\Models\LoginSecuritySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordRecoveryRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_route_uses_configured_rate_limit(): void
    {
        $settings = LoginSecuritySetting::query()->firstOrCreate([], [
            'max_attempts' => 5,
            'decay_seconds' => 300,
            'forgot_password_max_attempts' => 3,
            'forgot_password_decay_seconds' => 60,
            'reset_password_max_attempts' => 6,
            'reset_password_decay_seconds' => 60,
        ]);
        $settings->update([
            'forgot_password_max_attempts' => 3,
            'forgot_password_decay_seconds' => 60,
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('password.email'), [
                'email' => 'utente.forgot@example.test',
            ])->assertStatus(302);
        }

        $this->post(route('password.email'), [
            'email' => 'utente.forgot@example.test',
        ])->assertStatus(429);
    }

    public function test_reset_password_route_uses_configured_rate_limit(): void
    {
        $settings = LoginSecuritySetting::query()->firstOrCreate([], [
            'max_attempts' => 5,
            'decay_seconds' => 300,
            'forgot_password_max_attempts' => 6,
            'forgot_password_decay_seconds' => 60,
            'reset_password_max_attempts' => 3,
            'reset_password_decay_seconds' => 60,
        ]);
        $settings->update([
            'reset_password_max_attempts' => 3,
            'reset_password_decay_seconds' => 60,
        ]);

        $payload = [
            'token' => 'token-non-valido',
            'email' => 'utente.reset@example.test',
            'password' => 'password-nuova-valida',
            'password_confirmation' => 'password-nuova-valida',
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('password.store'), $payload)->assertStatus(302);
        }

        $this->post(route('password.store'), $payload)->assertStatus(429);
    }
}
