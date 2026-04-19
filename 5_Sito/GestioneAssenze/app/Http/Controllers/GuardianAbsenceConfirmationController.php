<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardianSignatureRequest;
use App\Models\Absence;
use App\Models\AbsenceConfirmationToken;
use App\Models\GuardianAbsenceConfirmation;
use App\Models\OperationLog;
use App\Services\AbsenceGuardianSignatureService;
use App\Support\StudentArchivePathBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GuardianAbsenceConfirmationController extends Controller
{
    public function show(string $token, AbsenceGuardianSignatureService $signatureService): Response
    {
        $state = $signatureService->getTokenState($token);
        /** @var AbsenceConfirmationToken|null $tokenRecord */
        $tokenRecord = $state['token'];

        $firstSignedConfirmation = null;
        if ($tokenRecord) {
            $firstSignedConfirmation = $signatureService->resolveFirstSignedConfirmation(
                $tokenRecord->absence_id
            );

            if ($firstSignedConfirmation) {
                $state['status'] = 'already_signed';
            }
        }

        $absence = $tokenRecord?->absence;
        $guardian = $tokenRecord?->guardian;
        if ($absence) {
            $absence->loadMissing(['student', 'medicalCertificates']);
        }
        $certificateRequirement = $absence?->resolveCertificateRequirementStatus();
        $signedAt = $firstSignedConfirmation?->confirmed_at ?? $firstSignedConfirmation?->signed_at;
        $signedBy = $this->resolveSignerName($firstSignedConfirmation);
        $studentName = trim((string) ($absence?->student?->name ?? '').' '.(string) ($absence?->student?->surname ?? ''));

        return Inertia::render('Guardian/AbsenceSignature', [
            'token' => $token,
            'status' => $state['status'],
            'signatureSuccess' => (bool) session('signature_success', false),
            'canSign' => $state['status'] === 'valid',
            'alreadySigned' => $state['status'] === 'already_signed',
            'signedAt' => $signedAt?->format('d/m/Y H:i'),
            'signedBy' => $signedBy,
            'absence' => $absence ? [
                'id' => 'A-'.str_pad((string) $absence->id, 4, '0', STR_PAD_LEFT),
                'student_name' => $studentName !== '' ? $studentName : '-',
                'start_date' => $absence->start_date?->format('d/m/Y'),
                'end_date' => $absence->end_date?->format('d/m/Y'),
                'hours' => (int) $absence->assigned_hours,
                'reason' => (string) ($absence->reason ?? '-'),
                'certificate_requirement' => $certificateRequirement,
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
        AbsenceGuardianSignatureService $signatureService
    ) {
        $validated = $request->validated();

        $state = $signatureService->getTokenState($token);
        /** @var AbsenceConfirmationToken|null $tokenRecord */
        $tokenRecord = $state['token'];

        if ($state['status'] !== 'valid' || ! $tokenRecord) {
            return redirect()
                ->route('guardian.absences.signature.show', ['token' => $token])
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

        $absence = Absence::query()
            ->with(['student.classes', 'student.guardians'])
            ->find($tokenRecord->absence_id);
        if (! $absence || ! $absence->student) {
            return redirect()
                ->route('guardian.absences.signature.show', ['token' => $token])
                ->withErrors([
                    'token' => 'Assenza non trovata.',
                ]);
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));
        $signaturePath = StudentArchivePathBuilder::storeBinaryForStudent(
            $signatureBinary,
            $absence->student,
            StudentArchivePathBuilder::CATEGORY_SIGNATURES,
            'png',
            [
                'context' => 'firma_assenza',
                'code' => 'a'.str_pad((string) $tokenRecord->absence_id, 4, '0', STR_PAD_LEFT)
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
                $lockedToken = AbsenceConfirmationToken::query()
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

                $firstSignedConfirmation = GuardianAbsenceConfirmation::query()
                    ->with('guardian')
                    ->where('absence_id', $lockedToken->absence_id)
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
                        ? 'Assenza gia firmata da '.$signerName.'.'
                        : 'Assenza gia firmata da un tutore.';

                    if ($signedAt) {
                        $message .= ' Firma registrata il '.$signedAt->format('d/m/Y H:i').'.';
                    }

                    throw ValidationException::withMessages([
                        'token' => $message,
                    ]);
                }

                $confirmation = GuardianAbsenceConfirmation::query()->firstOrNew([
                    'absence_id' => $lockedToken->absence_id,
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

                $signatureService->markTokenAsUsed($lockedToken);

                OperationLog::record(
                    null,
                    'absence.guardian.signature.confirmed',
                    'absence',
                    $lockedToken->absence_id,
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
            ->route('guardian.absences.signature.show', ['token' => $token])
            ->with('signature_success', true);
    }

    private function resolveTokenErrorMessage(string $status): string
    {
        return match ($status) {
            'expired' => 'Il link e scaduto. Richiedi un nuovo invio al docente.',
            'used' => 'Questo link e gia stato utilizzato.',
            'already_signed' => 'Questa assenza risulta gia firmata da un tutore.',
            default => 'Link non valido.',
        };
    }

    private function resolveSignerName(?GuardianAbsenceConfirmation $confirmation): ?string
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
