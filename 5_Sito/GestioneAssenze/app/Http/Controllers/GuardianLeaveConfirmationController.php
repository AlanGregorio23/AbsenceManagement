<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardianSignatureRequest;
use App\Models\GuardianLeaveConfirmation;
use App\Models\Leave;
use App\Models\LeaveConfirmationToken;
use App\Models\OperationLog;
use App\Services\LeaveGuardianSignatureService;
use App\Support\StudentArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GuardianLeaveConfirmationController extends Controller
{
    public function show(string $token, LeaveGuardianSignatureService $signatureService): Response
    {
        $state = $signatureService->getTokenState($token);
        /** @var LeaveConfirmationToken|null $tokenRecord */
        $tokenRecord = $state['token'];

        $firstSignedConfirmation = null;
        if ($tokenRecord) {
            $firstSignedConfirmation = $signatureService->resolveFirstSignedConfirmation(
                $tokenRecord->leave_id
            );

            if ($firstSignedConfirmation) {
                $state['status'] = 'already_signed';
            }
        }

        $leave = $tokenRecord?->leave;
        $guardian = $tokenRecord?->guardian;
        if ($leave) {
            $leave->loadMissing(['student']);
        }
        $requestedLessonsLabel = $leave
            ? Leave::formatRequestedLessonsLabel(
                Leave::normalizeRequestedLessonsPayload($leave->requested_lessons),
                $leave->start_date?->toDateString(),
                $leave->end_date?->toDateString()
            )
            : '';
        $signedAt = $firstSignedConfirmation?->confirmed_at ?? $firstSignedConfirmation?->signed_at;
        $signedBy = $this->resolveSignerName($firstSignedConfirmation);
        $studentName = trim((string) ($leave?->student?->name ?? '').' '.(string) ($leave?->student?->surname ?? ''));

        return Inertia::render('Guardian/LeaveSignature', [
            'token' => $token,
            'status' => $state['status'],
            'signatureSuccess' => (bool) session('signature_success', false),
            'canSign' => $state['status'] === 'valid',
            'alreadySigned' => $state['status'] === 'already_signed',
            'signedAt' => $signedAt?->format('d/m/Y H:i'),
            'signedBy' => $signedBy,
            'leave' => $leave ? [
                'id' => 'C-'.str_pad((string) $leave->id, 4, '0', STR_PAD_LEFT),
                'student_name' => $studentName !== '' ? $studentName : '-',
                'start_date' => $leave->start_date?->format('d/m/Y'),
                'end_date' => $leave->end_date?->format('d/m/Y'),
                'hours' => (int) $leave->requested_hours,
                'requested_lessons_label' => $requestedLessonsLabel !== ''
                    ? $requestedLessonsLabel
                    : null,
                'destination' => (string) ($leave->destination ?? ''),
                'reason' => (string) ($leave->reason ?? '-'),
                'count_hours' => (bool) ($leave->count_hours ?? true),
            ] : null,
            'guardian' => $guardian ? [
                'name' => (string) ($guardian->name ?? '-'),
            ] : null,
            'oldInput' => [
                'full_name' => (string) old('full_name', $guardian?->name ?? ''),
                'consent' => (bool) old('consent', false),
                'signature_data' => (string) old('signature_data', ''),
            ],
        ]);
    }

    public function store(
        GuardianSignatureRequest $request,
        string $token,
        LeaveGuardianSignatureService $signatureService
    ) {
        $validated = $request->validated();

        $state = $signatureService->getTokenState($token);
        /** @var LeaveConfirmationToken|null $tokenRecord */
        $tokenRecord = $state['token'];

        if ($state['status'] !== 'valid' || ! $tokenRecord) {
            return redirect()
                ->route('guardian.leaves.signature.show', ['token' => $token])
                ->withErrors([
                    'token' => $this->resolveTokenErrorMessage($state['status']),
                ]);
        }

        $signatureBinary = $this->decodeSignatureData((string) $validated['signature_data']);
        if ($signatureBinary === null) {
            return back()
                ->withInput()
                ->withErrors(['signature_data' => 'Formato firma non valido. Riprova.']);
        }

        $leaveForArchive = Leave::query()
            ->with(['student.classes', 'student.guardians'])
            ->find($tokenRecord->leave_id);
        if (! $leaveForArchive || ! $leaveForArchive->student) {
            return redirect()
                ->route('guardian.leaves.signature.show', ['token' => $token])
                ->withErrors([
                    'token' => 'Congedo non trovato.',
                ]);
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));
        $signaturePath = StudentArchivePathBuilder::storeBinaryForStudent(
            $signatureBinary,
            $leaveForArchive->student,
            StudentArchivePathBuilder::CATEGORY_SIGNATURES,
            'png',
            [
                'context' => 'firma_congedo',
                'code' => 'c'.str_pad((string) $tokenRecord->leave_id, 4, '0', STR_PAD_LEFT)
                    .'_g'.str_pad((string) $tokenRecord->guardian_id, 4, '0', STR_PAD_LEFT),
                'guardian' => (string) ($tokenRecord->guardian?->name ?? $validated['full_name']),
            ]
        );

        try {
            DB::transaction(function () use (
                $request,
                $validated,
                $tokenRecord,
                $signaturePath,
                $signatureService
            ) {
                $lockedToken = LeaveConfirmationToken::query()
                    ->whereKey($tokenRecord->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedToken) {
                    throw ValidationException::withMessages([
                        'token' => 'Link non valido.',
                    ]);
                }

                if ($lockedToken->used_at || Carbon::parse($lockedToken->expires_at)->isPast()) {
                    throw ValidationException::withMessages([
                        'token' => 'Il link non e piu valido. Richiedi un nuovo invio alla scuola.',
                    ]);
                }

                $firstSignedConfirmation = GuardianLeaveConfirmation::query()
                    ->with('guardian')
                    ->where('leave_id', $lockedToken->leave_id)
                    ->where(function ($query) {
                        $query
                            ->whereIn('status', ['confirmed', 'approved', 'signed'])
                            ->orWhereNotNull('confirmed_at')
                            ->orWhereNotNull('signed_at');
                    })
                    ->orderByRaw('COALESCE(confirmed_at, signed_at) asc')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($firstSignedConfirmation) {
                    $signerName = $this->resolveSignerName($firstSignedConfirmation);
                    $signedAt = $firstSignedConfirmation->confirmed_at ?? $firstSignedConfirmation->signed_at;
                    $message = $signerName
                        ? 'Congedo gia firmato da '.$signerName.'.'
                        : 'Congedo gia firmato da un tutore.';

                    if ($signedAt) {
                        $message .= ' Firma registrata il '.$signedAt->format('d/m/Y H:i').'.';
                    }

                    throw ValidationException::withMessages([
                        'token' => $message,
                    ]);
                }

                $confirmation = GuardianLeaveConfirmation::query()->firstOrNew([
                    'leave_id' => $lockedToken->leave_id,
                    'guardian_id' => $lockedToken->guardian_id,
                ]);

                $signedAt = now();
                $confirmation->status = 'confirmed';
                $confirmation->confirmed_at = $signedAt;
                $confirmation->signed_at = $signedAt;
                $confirmation->signature_path = $signaturePath;
                $confirmation->ip_address = $request->ip();
                $confirmation->notes = json_encode([
                    'signer_name' => trim((string) $validated['full_name']),
                    'signature_mode' => 'drawn_canvas',
                    'user_agent' => (string) ($request->userAgent() ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $confirmation->save();

                $leave = Leave::query()->lockForUpdate()->find($lockedToken->leave_id);
                if (
                    $leave
                    && Leave::normalizeStatus($leave->status) === Leave::STATUS_AWAITING_GUARDIAN_SIGNATURE
                ) {
                    $leave->status = Leave::STATUS_SIGNED;
                    $leave->save();
                }

                $signatureService->markTokenAsUsed($lockedToken);

                OperationLog::record(
                    null,
                    'leave.guardian.signature.confirmed',
                    'leave',
                    $lockedToken->leave_id,
                    [
                        'guardian_id' => $lockedToken->guardian_id,
                        'signature_path' => $signaturePath,
                    ],
                    'INFO',
                    $request
                );
            });
        } catch (Throwable $exception) {
            if ($disk->exists($signaturePath)) {
                $disk->delete($signaturePath);
            }

            throw $exception;
        }

        return redirect()
            ->route('guardian.leaves.signature.show', ['token' => $token])
            ->with('signature_success', true);
    }

    private function resolveTokenErrorMessage(string $status): string
    {
        return match ($status) {
            'expired' => 'Il link e scaduto. Richiedi un nuovo invio alla scuola.',
            'used' => 'Questo link e gia stato utilizzato.',
            'already_signed' => 'Questo congedo risulta gia firmato da un tutore.',
            default => 'Link non valido.',
        };
    }

    private function resolveSignerName(?GuardianLeaveConfirmation $confirmation): ?string
    {
        if (! $confirmation) {
            return null;
        }

        $notes = json_decode((string) ($confirmation->notes ?? ''), true);
        $signerName = trim((string) ($notes['signer_name'] ?? ''));
        if ($signerName !== '') {
            return $signerName;
        }

        $guardianName = trim((string) ($confirmation->guardian?->name ?? ''));

        return $guardianName !== '' ? $guardianName : null;
    }

    private function decodeSignatureData(string $signatureData): ?string
    {
        if (! str_starts_with($signatureData, 'data:image/png;base64,')) {
            return null;
        }

        $base64 = substr($signatureData, strlen('data:image/png;base64,'));
        $decoded = base64_decode($base64, true);

        if ($decoded === false || strlen($decoded) === 0) {
            return null;
        }

        if (strlen($decoded) > 2 * 1024 * 1024) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($decoded);
        if (! $imageInfo || ($imageInfo['mime'] ?? '') !== 'image/png') {
            return null;
        }

        return $decoded;
    }
}
