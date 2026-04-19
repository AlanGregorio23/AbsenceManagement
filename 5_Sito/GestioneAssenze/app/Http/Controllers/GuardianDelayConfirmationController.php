<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardianSignatureRequest;
use App\Models\Delay;
use App\Models\DelayConfirmationToken;
use App\Models\GuardianDelayConfirmation;
use App\Models\OperationLog;
use App\Services\DelayGuardianSignatureService;
use App\Support\StudentArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GuardianDelayConfirmationController extends Controller
{
    public function show(string $token, DelayGuardianSignatureService $signatureService): Response
    {
        $state = $signatureService->getTokenState($token);
        /** @var DelayConfirmationToken|null $tokenRecord */
        $tokenRecord = $state['token'];

        $firstSignedConfirmation = null;
        if ($tokenRecord) {
            $firstSignedConfirmation = $signatureService->resolveFirstSignedConfirmation(
                $tokenRecord->delay_id
            );

            if ($firstSignedConfirmation) {
                $state['status'] = 'already_signed';
            }
        }

        $delay = $tokenRecord?->delay;
        $guardian = $tokenRecord?->guardian;
        if ($delay) {
            $delay->loadMissing(['student']);
        }
        $signedAt = $firstSignedConfirmation?->confirmed_at ?? $firstSignedConfirmation?->signed_at;
        $signedBy = $this->resolveSignerName($firstSignedConfirmation);
        $studentName = trim((string) ($delay?->student?->name ?? '').' '.(string) ($delay?->student?->surname ?? ''));

        return Inertia::render('Guardian/DelaySignature', [
            'token' => $token,
            'status' => $state['status'],
            'signatureSuccess' => (bool) session('signature_success', false),
            'canSign' => $state['status'] === 'valid',
            'alreadySigned' => $state['status'] === 'already_signed'
                && ! (bool) session('signature_success', false),
            'signedAt' => $signedAt?->format('d/m/Y H:i'),
            'signedBy' => $signedBy,
            'delay' => $delay ? [
                'id' => 'R-'.str_pad((string) $delay->id, 4, '0', STR_PAD_LEFT),
                'student_name' => $studentName !== '' ? $studentName : '-',
                'delay_datetime' => $delay->delay_datetime?->format('d/m/Y H:i'),
                'minutes' => (int) $delay->minutes,
                'reason' => (string) ($delay->notes ?? '-'),
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
        DelayGuardianSignatureService $signatureService
    ) {
        $validated = $request->validated();

        $state = $signatureService->getTokenState($token);
        /** @var DelayConfirmationToken|null $tokenRecord */
        $tokenRecord = $state['token'];

        if ($state['status'] !== 'valid' || ! $tokenRecord) {
            return redirect()
                ->route('guardian.delays.signature.show', ['token' => $token])
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

        $delayForArchive = Delay::query()
            ->with(['student.classes', 'student.guardians'])
            ->find($tokenRecord->delay_id);
        if (! $delayForArchive || ! $delayForArchive->student) {
            return redirect()
                ->route('guardian.delays.signature.show', ['token' => $token])
                ->withErrors([
                    'token' => 'Ritardo non trovato.',
                ]);
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));
        $signaturePath = StudentArchivePathBuilder::storeBinaryForStudent(
            $signatureBinary,
            $delayForArchive->student,
            StudentArchivePathBuilder::CATEGORY_SIGNATURES,
            'png',
            [
                'context' => 'firma_ritardo',
                'code' => 'r'.str_pad((string) $tokenRecord->delay_id, 4, '0', STR_PAD_LEFT)
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
                $lockedToken = DelayConfirmationToken::query()
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
                        'token' => 'Il link non e piu valido. Richiedi un nuovo invio al docente.',
                    ]);
                }

                if (! Delay::query()
                    ->whereKey($lockedToken->delay_id)
                    ->lockForUpdate()
                    ->first()
                ) {
                    throw ValidationException::withMessages([
                        'token' => 'Ritardo non trovato.',
                    ]);
                }

                $firstSignedConfirmation = GuardianDelayConfirmation::query()
                    ->with('guardian')
                    ->where('delay_id', $lockedToken->delay_id)
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
                        ? 'Ritardo gia firmato da '.$signerName.'.'
                        : 'Ritardo gia firmato da un tutore.';

                    if ($signedAt) {
                        $message .= ' Firma registrata il '.$signedAt->format('d/m/Y H:i').'.';
                    }

                    throw ValidationException::withMessages([
                        'token' => $message,
                    ]);
                }

                $signedAt = now();
                try {
                    GuardianDelayConfirmation::query()->create([
                        'delay_id' => $lockedToken->delay_id,
                        'guardian_id' => $lockedToken->guardian_id,
                        'status' => 'confirmed',
                        'confirmed_at' => $signedAt,
                        'signed_at' => $signedAt,
                        'signature_path' => $signaturePath,
                        'ip_address' => $request->ip(),
                        'notes' => json_encode([
                            'signer_name' => trim((string) $validated['full_name']),
                            'signature_mode' => 'drawn_canvas',
                            'user_agent' => (string) ($request->userAgent() ?? ''),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                } catch (QueryException $exception) {
                    throw ValidationException::withMessages([
                        'token' => 'Questo ritardo risulta gia firmato da un tutore.',
                    ]);
                }

                $signatureService->markTokenAsUsed($lockedToken);

                OperationLog::record(
                    null,
                    'delay.guardian.signature.confirmed',
                    'delay',
                    $lockedToken->delay_id,
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
            ->route('guardian.delays.signature.show', ['token' => $token])
            ->with('signature_success', true);
    }

    private function resolveTokenErrorMessage(string $status): string
    {
        return match ($status) {
            'expired' => 'Il link e scaduto. Richiedi un nuovo invio al docente.',
            'used' => 'Questo link e gia stato utilizzato.',
            'already_signed' => 'Questo ritardo risulta gia firmato da un tutore.',
            default => 'Link non valido.',
        };
    }

    private function resolveSignerName(?GuardianDelayConfirmation $confirmation): ?string
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
