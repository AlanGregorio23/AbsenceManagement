<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Redirect;

class NotificationController extends Controller
{
    public function markAsRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        if (
            $notification->notifiable_type !== get_class($request->user())
            || (int) $notification->notifiable_id !== (int) $request->user()?->id
        ) {
            abort(403);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return Redirect::back();
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications?->markAsRead();

        return Redirect::back();
    }
}
