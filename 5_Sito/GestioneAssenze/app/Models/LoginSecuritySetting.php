<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginSecuritySetting extends Model
{
    use HasFactory;

    public const MIN_MAX_ATTEMPTS = 3;

    public const MAX_MAX_ATTEMPTS = 10;

    public const MIN_DECAY_SECONDS = 60;

    public const MAX_DECAY_SECONDS = 1800;

    public const MIN_PASSWORD_RECOVERY_MAX_ATTEMPTS = 3;

    public const MAX_PASSWORD_RECOVERY_MAX_ATTEMPTS = 20;

    public const MIN_PASSWORD_RECOVERY_DECAY_SECONDS = 60;

    public const MAX_PASSWORD_RECOVERY_DECAY_SECONDS = 1800;

    protected $fillable = [
        'max_attempts',
        'decay_seconds',
        'forgot_password_max_attempts',
        'forgot_password_decay_seconds',
        'reset_password_max_attempts',
        'reset_password_decay_seconds',
    ];

    protected function casts(): array
    {
        return [
            'max_attempts' => 'integer',
            'decay_seconds' => 'integer',
            'forgot_password_max_attempts' => 'integer',
            'forgot_password_decay_seconds' => 'integer',
            'reset_password_max_attempts' => 'integer',
            'reset_password_decay_seconds' => 'integer',
        ];
    }

    public static function sanitizeMaxAttempts(?int $value): int
    {
        $base = (int) ($value ?? config('auth.login.max_attempts', 5));

        return min(
            self::MAX_MAX_ATTEMPTS,
            max(self::MIN_MAX_ATTEMPTS, $base)
        );
    }

    public static function sanitizeDecaySeconds(?int $value): int
    {
        $base = (int) ($value ?? config('auth.login.decay_seconds', 300));

        return min(
            self::MAX_DECAY_SECONDS,
            max(self::MIN_DECAY_SECONDS, $base)
        );
    }

    public static function sanitizeForgotPasswordMaxAttempts(?int $value): int
    {
        $base = (int) ($value ?? config('auth.password_recovery.forgot.max_attempts', 6));

        return min(
            self::MAX_PASSWORD_RECOVERY_MAX_ATTEMPTS,
            max(self::MIN_PASSWORD_RECOVERY_MAX_ATTEMPTS, $base)
        );
    }

    public static function sanitizeForgotPasswordDecaySeconds(?int $value): int
    {
        $base = (int) ($value ?? config('auth.password_recovery.forgot.decay_seconds', 60));

        return min(
            self::MAX_PASSWORD_RECOVERY_DECAY_SECONDS,
            max(self::MIN_PASSWORD_RECOVERY_DECAY_SECONDS, $base)
        );
    }

    public static function sanitizeResetPasswordMaxAttempts(?int $value): int
    {
        $base = (int) ($value ?? config('auth.password_recovery.reset.max_attempts', 6));

        return min(
            self::MAX_PASSWORD_RECOVERY_MAX_ATTEMPTS,
            max(self::MIN_PASSWORD_RECOVERY_MAX_ATTEMPTS, $base)
        );
    }

    public static function sanitizeResetPasswordDecaySeconds(?int $value): int
    {
        $base = (int) ($value ?? config('auth.password_recovery.reset.decay_seconds', 60));

        return min(
            self::MAX_PASSWORD_RECOVERY_DECAY_SECONDS,
            max(self::MIN_PASSWORD_RECOVERY_DECAY_SECONDS, $base)
        );
    }
}
