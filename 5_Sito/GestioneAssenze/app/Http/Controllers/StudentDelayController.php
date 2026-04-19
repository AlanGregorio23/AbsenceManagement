<?php

namespace App\Http\Controllers;

use App\Http\Requests\DelayRequest;
use App\Models\Delay;
use App\Models\DelaySetting;
use App\Models\OperationLog;
use App\Services\DelayGuardianSignatureService;
use App\Support\SystemSettingsResolver;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;

class StudentDelayController extends BaseController
{
    public function __construct()
    {
        $this->middleware('student');
    }

    public function create()
    {

        $setting = $this->resolveDelaySetting();

        return Inertia::render('Student/DelayCreate', [
            'settings' => [
                'minutes_threshold' => (int) $setting->minutes_threshold,
                'guardian_signature_required' => (bool) $setting->guardian_signature_required,
            ],
        ]);
    }

    public function store(
        DelayRequest $request,
        DelayGuardianSignatureService $delayGuardianSignatureService
    ) {

        $user = $request->user();
        $validated = $request->validated();

        $delaySetting = $this->resolveDelaySetting();
        $delayDate = Carbon::parse((string) $validated['delay_date'])->startOfDay();
        $delayMinutes = (int) $validated['delay_minutes'];
        $guardianSignatureRequired = (bool) $delaySetting->guardian_signature_required;
        $deadlineModeActive = Delay::deadlineModeActive($delaySetting);
        $justificationDeadline = ($guardianSignatureRequired || $deadlineModeActive)
            ? null
            : Delay::calculateJustificationDeadline($delayDate, $delaySetting);
        $motivation = trim((string) $validated['motivation']);

        $delay = Delay::query()->create([
            'student_id' => $user->id,
            'recorded_by' => $user->id,
            'delay_datetime' => $delayDate,
            'minutes' => $delayMinutes,
            'justification_deadline' => $justificationDeadline?->toDateString(),
            'notes' => $motivation,
            'status' => Delay::STATUS_REPORTED,
            'count_in_semester' => false,
            'global' => false,
        ]);

        OperationLog::record(
            $user,
            'delay.request.created',
            'delay',
            $delay->id,
            [
                'delay_datetime' => $delayDate->toDateString(),
                'minutes' => $delayMinutes,
                'reason' => $motivation,
                'justification_deadline' => $justificationDeadline?->toDateString(),
                'guardian_signature_required' => $guardianSignatureRequired,
                'deadline_mode_active' => $deadlineModeActive,
                'delay_minutes_in_request' => $delayMinutes,
            ],
            'INFO',
            $request
        );

        $delayGuardianSignatureService->sendReportedInfoEmails($delay, $user, $request);

        $successMessage = 'Segnalazione ritardo registrata.';
        if ($deadlineModeActive) {
            $successMessage .= ' Se il ritardo verra registrato dal docente, partira la scadenza configurata.';
        }

        return back()->with('success', $successMessage);
    }

    private function resolveDelaySetting(): DelaySetting
    {
        return SystemSettingsResolver::delaySetting();
    }
}
