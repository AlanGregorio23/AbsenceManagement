<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class UserObserver
{
    public function updating(User $user): void
    {
        if (! $user->isDirty('avatar_path')) {
            return;
        }

        $oldAvatar = (string) $user->getOriginal('avatar_path');
        $newAvatar = (string) $user->avatar_path;

        if ($oldAvatar !== '' && $oldAvatar !== $newAvatar) {
            $this->deleteAvatarIfOwned($oldAvatar);
        }
    }

    public function deleted(User $user): void
    {
        $this->deleteAvatarIfOwned((string) $user->avatar_path);
    }

    private function deleteAvatarIfOwned(string $avatarPath): void
    {
        $normalizedPath = ltrim(str_replace('\\', '/', trim($avatarPath)), '/');
        if ($normalizedPath === '') {
            return;
        }

        $isLegacyAvatarPath = str_starts_with($normalizedPath, 'profile-avatars/');
        $isStudentArchiveAvatarPath = str_starts_with($normalizedPath, 'archivio/');

        if (! $isLegacyAvatarPath && ! $isStudentArchiveAvatarPath) {
            return;
        }

        if ($isStudentArchiveAvatarPath) {
            Storage::disk('local')->delete($normalizedPath);
        }

        $legacyPublicPath = public_path($normalizedPath);
        if (File::exists($legacyPublicPath)) {
            File::delete($legacyPublicPath);
        }
    }
}
