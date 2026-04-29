<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordSetupNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordSetupService
{
    private const TABLE = 'password_setup_tokens';

    public function sendSetupLink(User $user): void
    {
        $email = $user->getEmailForPasswordReset();
        $plainToken = Str::random(64);

        DB::table(self::TABLE)->updateOrInsert(
            ['email' => $email],
            [
                'token_hash' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        try {
            $user->notify(new PasswordSetupNotification($plainToken));
        } catch (\Throwable $exception) {
            $this->deleteForEmail($email);

            throw $exception;
        }
    }

    public function resetPassword(string $email, string $token, string $password): ?User
    {
        $email = trim($email);
        $token = trim($token);

        if ($email === '' || $token === '') {
            return null;
        }

        return DB::transaction(function () use ($email, $token, $password) {
            $user = User::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return null;
            }

            $tokenHash = DB::table(self::TABLE)
                ->where('email', $email)
                ->lockForUpdate()
                ->value('token_hash');

            if (! is_string($tokenHash) || ! Hash::check($token, $tokenHash)) {
                return null;
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table(self::TABLE)
                ->where('email', $email)
                ->delete();

            Password::broker()->deleteToken($user);

            return $user;
        });
    }

    public function deleteForEmail(string $email): void
    {
        $email = trim($email);

        if ($email === '') {
            return;
        }

        DB::table(self::TABLE)
            ->where('email', $email)
            ->delete();
    }
}
