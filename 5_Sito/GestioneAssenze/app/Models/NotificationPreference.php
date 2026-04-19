<?php

namespace App\Models;

use App\Support\NotificationTypeRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_key',
        'web_enabled',
        'email_enabled',
    ];

    protected function casts(): array
    {
        return [
            'web_enabled' => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function settingsForUser(User $user): array
    {
        $definitions = NotificationTypeRegistry::forRole($user->role);
        $storedValues = static::query()
            ->where('user_id', $user->id)
            ->whereIn('event_key', NotificationTypeRegistry::eventKeysForRole($user->role))
            ->get(['event_key', 'web_enabled', 'email_enabled'])
            ->keyBy('event_key');

        return array_map(function (array $definition) use ($storedValues, $user) {
            $eventKey = (string) $definition['key'];
            $storedValue = $storedValues->get($eventKey);

            return [
                ...$definition,
                'web_enabled' => $storedValue
                    ? (bool) $storedValue->web_enabled
                    : true,
                'default_web_enabled' => true,
                'email_enabled' => $storedValue
                    ? (bool) $storedValue->email_enabled
                    : NotificationTypeRegistry::defaultEmailEnabled($user->role, $eventKey),
            ];
        }, $definitions);
    }

    public static function webEnabledFor(User $user, string $eventKey): bool
    {
        if (! in_array($eventKey, NotificationTypeRegistry::eventKeysForRole($user->role), true)) {
            return true;
        }

        $storedValue = static::query()
            ->where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->value('web_enabled');

        if ($storedValue === null) {
            return true;
        }

        return (bool) $storedValue;
    }

    public static function emailEnabledFor(User $user, string $eventKey): bool
    {
        if (! NotificationTypeRegistry::supportsEmailForRole($user->role, $eventKey)) {
            return false;
        }

        $storedValue = static::query()
            ->where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->value('email_enabled');

        if ($storedValue === null) {
            return NotificationTypeRegistry::defaultEmailEnabled($user->role, $eventKey);
        }

        return (bool) $storedValue;
    }

    public static function syncForUser(User $user, array $preferences): void
    {
        $allowedEventKeys = NotificationTypeRegistry::eventKeysForRole($user->role);

        foreach ($allowedEventKeys as $eventKey) {
            $rawPreference = $preferences[$eventKey] ?? [];
            $webEnabled = true;
            $emailEnabled = false;

            if (is_array($rawPreference)) {
                $webEnabled = (bool) ($rawPreference['web_enabled'] ?? true);
                $emailEnabled = (bool) ($rawPreference['email_enabled'] ?? false);
            } else {
                // Backward compatibility for old payload: event_key => bool (email only).
                $emailEnabled = (bool) $rawPreference;
            }

            static::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'event_key' => $eventKey,
                ],
                [
                    'web_enabled' => $webEnabled,
                    'email_enabled' => $emailEnabled,
                ]
            );
        }

        if ($allowedEventKeys === []) {
            static::query()
                ->where('user_id', $user->id)
                ->delete();

            return;
        }

        static::query()
            ->where('user_id', $user->id)
            ->whereNotIn('event_key', $allowedEventKeys)
            ->delete();
    }
}
