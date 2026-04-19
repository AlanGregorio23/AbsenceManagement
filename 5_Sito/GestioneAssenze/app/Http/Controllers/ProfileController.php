<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationPreferencesUpdateRequest;
use App\Http\Requests\ProfileDeleteRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\StudentStatusSettingsUpdateRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserStudentStatusSetting;
use App\Services\AdultGuardianPreferenceNotificationService;
use App\Services\InactiveGuardianNotificationResolver;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'notificationPreferences' => NotificationPreference::settingsForUser($request->user()),
            'studentStatusSettings' => $this->resolveStudentStatusSettingsPayload($request->user()),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $updates = [
            'name' => $validated['name'],
        ];

        if (($validated['remove_avatar'] ?? false) && ! $request->hasFile('avatar')) {
            $updates['avatar_path'] = null;
        }

        if ($request->hasFile('avatar')) {
            $avatarFile = $request->file('avatar');
            $studentArchiveDirectory = 'archivio/'.max((int) $user->id, 0).'/profilo';
            $disk = Storage::disk('local');
            $extension = strtolower((string) $avatarFile->getClientOriginalExtension());
            if ($extension === '') {
                $extension = 'jpg';
            }

            $fileName = sprintf(
                'avatar-%d-%s.%s',
                (int) $user->id,
                Str::uuid()->toString(),
                $extension
            );

            $storedPath = $disk->putFileAs($studentArchiveDirectory, $avatarFile, $fileName);
            if (! is_string($storedPath) || trim($storedPath) === '') {
                return Redirect::route('profile.edit')->withErrors([
                    'avatar' => 'Impossibile salvare la foto profilo. Riprova.',
                ]);
            }

            $updates['avatar_path'] = ltrim(str_replace('\\', '/', $storedPath), '/');
        }

        $user->forceFill($updates)->save();

        return Redirect::route('profile.edit');
    }

    public function showAvatar(Request $request, User $user)
    {
        $viewer = $request->user();
        if (! $viewer || (int) $viewer->id !== (int) $user->id) {
            abort(403);
        }

        $avatarPath = ltrim(str_replace('\\', '/', trim((string) $user->avatar_path)), '/');
        if ($avatarPath === '') {
            abort(404);
        }

        if (str_starts_with($avatarPath, 'profile-avatars/')) {
            $legacyPath = public_path($avatarPath);
            if (File::exists($legacyPath)) {
                return response()->file($legacyPath);
            }

            abort(404);
        }

        if (! str_starts_with($avatarPath, 'archivio/')) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if ($disk->exists($avatarPath)) {
            return $disk->response($avatarPath);
        }

        $legacyArchivePath = public_path($avatarPath);
        if (File::exists($legacyArchivePath)) {
            return response()->file($legacyArchivePath);
        }

        abort(404);
    }

    public function updateNotifications(
        NotificationPreferencesUpdateRequest $request,
        AdultGuardianPreferenceNotificationService $adultGuardianPreferenceNotificationService
    ): RedirectResponse {
        $user = $request->user();
        $validated = $request->validated();
        $wasNotifyPreviousGuardiansEnabled = NotificationPreference::emailEnabledFor(
            $user,
            InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY
        );

        NotificationPreference::syncForUser(
            $user,
            $validated['preferences'] ?? []
        );

        $isNotifyPreviousGuardiansEnabled = NotificationPreference::emailEnabledFor(
            $user,
            InactiveGuardianNotificationResolver::STUDENT_EVENT_KEY
        );

        if (
            $user->hasRole('student')
            && $wasNotifyPreviousGuardiansEnabled !== $isNotifyPreviousGuardiansEnabled
        ) {
            $adultGuardianPreferenceNotificationService->notifyToggle(
                $user,
                $isNotifyPreviousGuardiansEnabled,
                $user,
                $request
            );
        }

        return Redirect::route('profile.edit')
            ->with('status', 'notification-preferences-updated');
    }

    public function updateStudentStatusSettings(
        StudentStatusSettingsUpdateRequest $request
    ): RedirectResponse {
        $user = $request->user();
        $validated = $request->validated();

        UserStudentStatusSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'absence_warning_percent' => (int) $validated['absence_warning_percent'],
                'absence_critical_percent' => (int) $validated['absence_critical_percent'],
                'delay_warning_percent' => (int) $validated['delay_warning_percent'],
                'delay_critical_percent' => (int) $validated['delay_critical_percent'],
            ]
        );

        return Redirect::route('profile.edit')
            ->with('status', 'student-status-settings-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        return Redirect::route('profile.edit')->withErrors([
            'account' => 'Auto-eliminazione account disabilitata. Contatta un admin.',
        ]);
    }

    private function resolveStudentStatusSettingsPayload(User $user): ?array
    {
        if (! in_array((string) $user->role, ['teacher', 'laboratory_manager'], true)) {
            return null;
        }

        $settings = UserStudentStatusSetting::query()
            ->where('user_id', $user->id)
            ->first();

        return [
            'absence_warning_percent' => (int) (
                $settings?->absence_warning_percent
                ?? UserStudentStatusSetting::DEFAULT_WARNING_PERCENT
            ),
            'absence_critical_percent' => (int) (
                $settings?->absence_critical_percent
                ?? UserStudentStatusSetting::DEFAULT_CRITICAL_PERCENT
            ),
            'delay_warning_percent' => (int) (
                $settings?->delay_warning_percent
                ?? UserStudentStatusSetting::DEFAULT_WARNING_PERCENT
            ),
            'delay_critical_percent' => (int) (
                $settings?->delay_critical_percent
                ?? UserStudentStatusSetting::DEFAULT_CRITICAL_PERCENT
            ),
        ];
    }
}
