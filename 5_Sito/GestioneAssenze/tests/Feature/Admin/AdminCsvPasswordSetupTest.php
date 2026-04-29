<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\PasswordSetupNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminCsvPasswordSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_user_email_requires_domain_with_tld(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.manual@example.test',
        ]);

        $invalidResponse = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.manual.store'), [
                'name' => 'Alan',
                'surname' => 'Localhost',
                'email' => 'alan@localhost',
                'role' => 'student',
                'birth_date' => '2008-05-01',
                'class_id' => '',
            ]);

        $invalidResponse->assertStatus(302);
        $invalidResponse->assertSessionHasErrors(['email']);
        $this->assertDatabaseMissing('users', [
            'email' => 'alan@localhost',
        ]);

        $validResponse = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.manual.store'), [
                'name' => 'Alan',
                'surname' => 'Cpt',
                'email' => 'alan@cpt.local',
                'role' => 'student',
                'birth_date' => '2008-05-01',
                'class_id' => '',
            ]);

        $validResponse->assertStatus(302);
        $validResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'email' => 'alan@cpt.local',
        ]);
    }

    public function test_csv_import_sends_a_non_expiring_password_setup_link_until_password_is_set(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.csv@example.test',
        ]);

        $csvContent = implode("\n", [
            'ID;Allievo;Data di nascita;Campo3;Classe;Campo5;NetworkID',
            '1;Rossi Mario;01.05.2008;;INF4A;;mario.rossi@edu.ti.ch',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.FromCSVStore'), [
                'file' => UploadedFile::fake()->createWithContent('studenti.csv', $csvContent),
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas(
            'success',
            'Import completato: 1 creati, 0 aggiornati. Email impostazione password inviate 1/1.'
        );

        $student = User::query()
            ->where('email', 'mario.rossi@student.edu.ti.ch')
            ->first();

        $this->assertNotNull($student);
        $this->assertDatabaseHas('password_setup_tokens', [
            'email' => $student->email,
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $student->email,
        ]);

        Notification::assertSentToTimes($student, PasswordSetupNotification::class, 1);

        $notification = Notification::sent($student, PasswordSetupNotification::class)->first();

        $this->assertInstanceOf(PasswordSetupNotification::class, $notification);

        $plainToken = $notification->token();
        $mail = $notification->toMail($student);

        $this->assertIsString($plainToken);
        $this->assertArrayHasKey('expiryMinutes', $mail->viewData);
        $this->assertNull($mail->viewData['expiryMinutes']);
        $this->assertNotEmpty($mail->viewData['resetUrl'] ?? null);

        Password::broker()->createToken($student);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $student->email,
        ]);

        $this->travel(120)->days();
        $this->post(route('logout'));

        $resetResponse = $this->from(route('password.reset', [
            'token' => $plainToken,
            'email' => $student->email,
        ]))->post(route('password.store'), [
            'token' => $plainToken,
            'email' => $student->email,
            'password' => 'NuovaPassword123!',
            'password_confirmation' => 'NuovaPassword123!',
        ]);

        $resetResponse->assertRedirect(route('login'));
        $resetResponse->assertSessionHas('status', trans(Password::PASSWORD_RESET, [], 'it'));

        $student->refresh();

        $this->assertTrue(Hash::check('NuovaPassword123!', $student->password));
        $this->assertDatabaseMissing('password_setup_tokens', [
            'email' => $student->email,
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $student->email,
        ]);
    }

    public function test_csv_import_skips_email_without_domain_tld(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.csv.invalid@example.test',
        ]);

        $csvContent = implode("\n", [
            'ID;Allievo;Data di nascita;Campo3;Classe;Campo5;NetworkID',
            '1;Localhost Alan;01.05.2008;;INF4A;;alan@localhost',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.FromCSVStore'), [
                'file' => UploadedFile::fake()->createWithContent('studenti.csv', $csvContent),
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas(
            'success',
            'Import completato: 0 creati, 0 aggiornati.'
        );

        $this->assertDatabaseMissing('users', [
            'email' => 'alan@student.localhost',
        ]);
    }

    public function test_csv_import_rejects_non_gagi_header(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.csv.header@example.test',
        ]);

        $csvContent = implode("\n", [
            'Nome;Cognome;Email',
            'Mario;Rossi;mario.rossi@edu.ti.ch',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.FromCSVStore'), [
                'file' => UploadedFile::fake()->createWithContent('studenti.csv', $csvContent),
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['file']);
        $this->assertDatabaseMissing('users', [
            'email' => 'mario.rossi@student.edu.ti.ch',
        ]);
    }

    public function test_csv_import_rejects_excel_file_renamed_as_csv(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'surname' => 'Root',
            'role' => 'admin',
            'email' => 'admin.csv.binary@example.test',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('admin.user.create'))
            ->post(route('admin.user.FromCSVStore'), [
                'file' => UploadedFile::fake()->createWithContent(
                    'studenti.csv',
                    "PK\x03\x04".str_repeat('x', 64)
                ),
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['file']);
    }
}
