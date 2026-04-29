<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManualUserCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_manual_user_without_optional_fields(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.manual@example.test',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.manual.store'), [
                'name' => 'Mario',
                'surname' => 'Rossi',
                'email' => 'mario.rossi@example.test',
                'role' => 'student',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Utente creato. Email impostazione password inviata.');

        $createdUser = User::query()
            ->where('email', 'mario.rossi@example.test')
            ->first();

        $this->assertNotNull($createdUser);
        $this->assertSame('Mario', $createdUser->name);
        $this->assertSame('Rossi', $createdUser->surname);
        $this->assertSame('student', $createdUser->role);
        $this->assertNull($createdUser->birth_date);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'mario.rossi@example.test',
        ]);
        $this->assertDatabaseMissing('class_user', [
            'user_id' => $createdUser->id,
        ]);
    }

    public function test_admin_can_create_manual_student_with_class_assignment(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.manual.class@example.test',
        ]);

        $class = SchoolClass::query()->create([
            'name' => 'INF',
            'section' => 'A',
            'year' => '4',
            'active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.manual.store'), [
                'name' => 'Luca',
                'surname' => 'Bianchi',
                'email' => 'luca.bianchi@example.test',
                'role' => 'student',
                'birth_date' => '2008-06-01',
                'class_id' => $class->id,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Utente creato. Email impostazione password inviata.');

        $createdUser = User::query()
            ->where('email', 'luca.bianchi@example.test')
            ->first();

        $this->assertNotNull($createdUser);
        $this->assertSame('2008-06-01', $createdUser->birth_date?->toDateString());
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'luca.bianchi@example.test',
        ]);
        $this->assertDatabaseHas('class_user', [
            'class_id' => $class->id,
            'user_id' => $createdUser->id,
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => null,
        ]);
    }
}
