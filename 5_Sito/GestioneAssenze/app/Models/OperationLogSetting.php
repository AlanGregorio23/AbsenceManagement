<?php

namespace App\Models;

use App\Support\SystemDefaultSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationLogSetting extends Model
{
    use HasFactory;

    public const DEFAULT_RETENTION_DAYS = 425;

    protected $fillable = [
        'interaction_retention_days',
        'error_retention_days',
    ];

    protected function casts(): array
    {
        return [
            'interaction_retention_days' => 'integer',
            'error_retention_days' => 'integer',
        ];
    }

    public static function firstOrDefault(): self
    {
        $settings = static::query()->first();

        if ($settings) {
            return $settings;
        }

        return new static(SystemDefaultSettings::operationLogSettingDefaults());
    }

    public static function sanitizeRetentionDays(?int $value): int
    {
        return max((int) ($value ?? self::DEFAULT_RETENTION_DAYS), 1);
    }
}
