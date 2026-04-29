<?php

namespace Tests\Feature\Profiles;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProfileAvatarStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $testDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDiskRoot = rtrim(sys_get_temp_dir(), '\\/')
            .DIRECTORY_SEPARATOR
            .'gestioneassenze-profile-avatar-'
            .uniqid('', true);
        File::ensureDirectoryExists($this->testDiskRoot);

        config()->set('filesystems.default', 'local');
        config()->set('filesystems.disks.local.root', $this->testDiskRoot);
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        app('filesystem')->forgetDisk('local');
        File::deleteDirectory($this->testDiskRoot);

        parent::tearDown();
    }

    public function test_profile_avatar_is_stored_under_private_student_archive_folder(): void
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.avatar@example.test',
        ]);

        $response = $this->actingAs($student)->patch(route('profile.update'), [
            'name' => 'Alan',
            'email' => 'alan.avatar@example.test',
            'avatar' => UploadedFile::fake()->createWithContent(
                'avatar.png',
                base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8B9f8AAAAASUVORK5CYII='
                )
            ),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNotNull($student->avatar_path);
        $this->assertStringStartsWith(
            'archivio/'.$student->id.'/profilo/',
            (string) $student->avatar_path
        );
        $this->assertFileExists($this->absolutePath((string) $student->avatar_path));
    }

    public function test_profile_avatar_route_serves_owner_and_blocks_other_users(): void
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.avatar.route@example.test',
        ]);
        $avatarPath = 'archivio/'.$student->id.'/profilo/avatar-test.jpg';
        $absoluteAvatarPath = $this->absolutePath($avatarPath);
        File::ensureDirectoryExists(dirname($absoluteAvatarPath));
        File::put($absoluteAvatarPath, 'avatar-bytes');
        $student->forceFill([
            'avatar_path' => $avatarPath,
        ])->save();

        $ownerResponse = $this->actingAs($student)->get(
            route('profile.avatar.show', ['user' => $student->id])
        );
        $ownerResponse->assertStatus(200);

        $anotherUser = User::factory()->create([
            'name' => 'Giulia',
            'surname' => 'Bianchi',
            'role' => 'student',
            'email' => 'giulia.avatar.route@example.test',
        ]);
        $forbiddenResponse = $this->actingAs($anotherUser)->get(
            route('profile.avatar.show', ['user' => $student->id])
        );
        $forbiddenResponse->assertStatus(403);
    }

    public function test_removing_profile_avatar_clears_db_path_and_deletes_private_file(): void
    {
        $student = User::factory()->create([
            'name' => 'Alan',
            'surname' => 'Gregorio',
            'role' => 'student',
            'email' => 'alan.avatar.remove@example.test',
        ]);
        $avatarPath = 'archivio/'.$student->id.'/profilo/avatar-remove.jpg';
        $absoluteAvatarPath = $this->absolutePath($avatarPath);
        File::ensureDirectoryExists(dirname($absoluteAvatarPath));
        File::put($absoluteAvatarPath, 'avatar-remove');
        $student->forceFill([
            'avatar_path' => $avatarPath,
        ])->save();

        $response = $this->actingAs($student)->patch(route('profile.update'), [
            'name' => 'Alan',
            'email' => 'alan.avatar.remove@example.test',
            'remove_avatar' => '1',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNull($student->avatar_path);
        $this->assertFileDoesNotExist($absoluteAvatarPath);
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->testDiskRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
