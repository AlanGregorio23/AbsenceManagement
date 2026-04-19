<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_locked_after_max_failed_attempts_for_same_email_and_ip(): void
    {
        config([
            'auth.login.max_attempts' => 3,
            'auth.login.max_attempts_per_ip' => 50,
            'auth.login.decay_seconds' => 300,
        ]);

        RateLimiter::clear('login-ip|127.0.0.1');

        $user = User::factory()->create([
            'email' => 'mario.rossi@example.test',
            'active' => true,
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->from(route('login'))->post(route('login'), [
                'email' => $user->email,
                'password' => 'password-sbagliata',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'password-sbagliata',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Troppi tentativi di accesso. Riprova tra ', session('errors')->get('email')[0]);
    }

    public function test_successful_login_clears_user_specific_failed_attempt_counter(): void
    {
        config([
            'auth.login.max_attempts' => 2,
            'auth.login.max_attempts_per_ip' => 50,
            'auth.login.decay_seconds' => 300,
        ]);

        RateLimiter::clear('login-ip|127.0.0.1');

        $user = User::factory()->create([
            'email' => 'giulia.bianchi@example.test',
            'active' => true,
        ]);

        $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'password-sbagliata',
        ])->assertSessionHasErrors('email');

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(302);

        $this->post(route('logout'))->assertStatus(302);

        $response = $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'password-sbagliata',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame('Le credenziali non corrispondono ai nostri record.', session('errors')->get('email')[0]);
    }

    public function test_login_is_locked_after_max_failed_attempts_from_same_ip_across_multiple_emails(): void
    {
        config([
            'auth.login.max_attempts' => 50,
            'auth.login.max_attempts_per_ip' => 3,
            'auth.login.decay_seconds' => 300,
        ]);

        RateLimiter::clear('login-ip|127.0.0.1');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->from(route('login'))->post(route('login'), [
                'email' => "utente{$attempt}@example.test",
                'password' => 'password-sbagliata',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->from(route('login'))->post(route('login'), [
            'email' => 'ultimo.tentativo@example.test',
            'password' => 'password-sbagliata',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Troppi tentativi di accesso. Riprova tra ', session('errors')->get('email')[0]);
    }
}
