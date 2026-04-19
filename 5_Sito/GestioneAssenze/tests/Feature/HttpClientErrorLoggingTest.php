<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HttpClientErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_warning_for_not_found_response(): void
    {
        Log::spy();

        $this->get('/percorso-non-esistente-404')->assertNotFound();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'HTTP request failed with client error'
                    && ($context['status'] ?? null) === 404
                    && ($context['method'] ?? null) === 'GET'
                    && ($context['path'] ?? null) === 'percorso-non-esistente-404';
            });
    }

    public function test_it_logs_warning_for_forbidden_response(): void
    {
        Log::spy();

        $student = User::factory()->create([
            'surname' => 'Studente',
            'role' => 'student',
        ]);

        $this->actingAs($student)
            ->get(route('teacher.monthly-reports'))
            ->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($student): bool {
                return $message === 'HTTP request failed with client error'
                    && ($context['status'] ?? null) === 403
                    && ($context['route_name'] ?? null) === 'teacher.monthly-reports'
                    && ($context['user_id'] ?? null) === $student->id;
            });
    }
}
