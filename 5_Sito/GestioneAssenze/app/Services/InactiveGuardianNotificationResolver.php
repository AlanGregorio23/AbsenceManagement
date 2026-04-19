<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

class InactiveGuardianNotificationResolver
{
    public const STUDENT_EVENT_KEY = 'student_notify_inactive_guardians';

    /**
     * @return Collection<int, Guardian>
     */
    public function resolveSigningGuardiansForStudent(User $student): Collection
    {
        if (! $student->hasRole('student')) {
            return collect();
        }

        $allGuardians = $this->resolveAllGuardiansForStudent($student);

        $activeGuardians = $allGuardians
            ->filter(fn (Guardian $guardian) => (bool) ($guardian->pivot?->is_active ?? false))
            ->filter(fn (Guardian $guardian) => $this->normalizeEmail((string) $guardian->email) !== '')
            ->values();

        if (! $student->isAdult()) {
            return $this->uniqueByEmail($activeGuardians);
        }

        $selfGuardians = $activeGuardians
            ->filter(fn (Guardian $guardian) => $this->isSelfGuardian($student, $guardian))
            ->values();

        if ($selfGuardians->isNotEmpty()) {
            return $this->uniqueByEmail($selfGuardians);
        }

        return $this->uniqueByEmail($activeGuardians);
    }

    /**
     * @return Collection<int, Guardian>
     */
    public function resolveForStudent(User $student, ?Collection $signingGuardians = null): Collection
    {
        if (! $this->shouldNotify($student)) {
            return collect();
        }

        return $this->resolveHistoricalGuardians($student, $signingGuardians);
    }

    /**
     * @return Collection<int, Guardian>
     */
    public function resolveForPreferenceChange(User $student, ?Collection $signingGuardians = null): Collection
    {
        if (! $student->hasRole('student') || ! $student->isAdult()) {
            return collect();
        }

        return $this->resolveHistoricalGuardians($student, $signingGuardians);
    }

    /**
     * @return Collection<int, Guardian>
     */
    private function resolveHistoricalGuardians(User $student, ?Collection $signingGuardians): Collection
    {
        $allGuardians = $this->resolveAllGuardiansForStudent($student);
        $signingGuardians = $signingGuardians ?? $this->resolveSigningGuardiansForStudent($student);
        $excludedEmails = $signingGuardians
            ->map(fn (Guardian $guardian) => $this->normalizeEmail((string) $guardian->email))
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();

        return $allGuardians
            ->filter(fn (Guardian $guardian) => ! $this->isSelfGuardian($student, $guardian))
            ->filter(fn (Guardian $guardian) => $this->normalizeEmail((string) $guardian->email) !== '')
            ->reject(function (Guardian $guardian) use ($excludedEmails) {
                $email = $this->normalizeEmail((string) $guardian->email);

                return $excludedEmails->contains($email);
            })
            ->unique(fn (Guardian $guardian) => $this->normalizeEmail((string) $guardian->email))
            ->values();
    }

    /**
     * @return Collection<int, Guardian>
     */
    private function resolveAllGuardiansForStudent(User $student): Collection
    {
        $student->loadMissing([
            'allGuardians' => function ($query) {
                $query->orderBy('guardians.name');
            },
        ]);

        return $student->allGuardians ?? collect();
    }

    private function isSelfGuardian(User $student, Guardian $guardian): bool
    {
        $guardianEmail = $this->normalizeEmail((string) $guardian->email);
        $studentEmail = $this->normalizeEmail((string) $student->email);

        return $guardianEmail !== '' && $guardianEmail === $studentEmail;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @param  Collection<int, Guardian>  $guardians
     * @return Collection<int, Guardian>
     */
    private function uniqueByEmail(Collection $guardians): Collection
    {
        return $guardians
            ->filter(fn (Guardian $guardian) => $this->normalizeEmail((string) $guardian->email) !== '')
            ->unique(fn (Guardian $guardian) => $this->normalizeEmail((string) $guardian->email))
            ->values();
    }

    private function shouldNotify(User $student): bool
    {
        if (! $student->hasRole('student')) {
            return false;
        }

        if (! $student->isAdult()) {
            return false;
        }

        return NotificationPreference::emailEnabledFor($student, self::STUDENT_EVENT_KEY);
    }
}
