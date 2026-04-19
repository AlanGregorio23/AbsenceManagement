<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SystemMessageNotification;
use Illuminate\Support\Collection;

class NotificationDispatcher
{
    public function notifyUser(?User $user, string $eventKey, array $content): void
    {
        if (! $user || ! (bool) $user->active) {
            return;
        }

        $user->notify(new SystemMessageNotification($eventKey, $content));
    }

    public function notifyUsers(iterable $users, string $eventKey, array $content, ?int $excludeUserId = null): void
    {
        $this->normalizeRecipients($users)
            ->reject(fn (User $user) => $excludeUserId !== null && (int) $user->id === $excludeUserId)
            ->each(function (User $user) use ($eventKey, $content) {
                $user->notify(new SystemMessageNotification($eventKey, $content));
            });
    }

    private function normalizeRecipients(iterable $users): Collection
    {
        return collect($users)
            ->filter(fn ($user) => $user instanceof User && (bool) $user->active)
            ->unique(fn (User $user) => (int) $user->id)
            ->values();
    }
}
