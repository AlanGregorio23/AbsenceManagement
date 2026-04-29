<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SchoolHolidayPdfExtractor
{
    /**
     * @return array{dates:array<int,string>,metadata:array<string,mixed>}
     */
    public function extract(string $absolutePdfPath): array
    {
        $scriptPath = base_path('scripts/calendar_pdf_probe.py');
        if (! is_file($scriptPath)) {
            throw new RuntimeException('Script parser calendario non trovato.');
        }
        if (! is_file($absolutePdfPath)) {
            throw new RuntimeException('File PDF non trovato per analisi calendario.');
        }

        $process = new Process([
            ...$this->pythonCommand(),
            $scriptPath,
            '--json',
            $absolutePdfPath,
        ], base_path());
        $process->setTimeout(90);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ($error === '') {
                $error = trim($process->getOutput());
            }
            if ($error === '') {
                $error = 'Errore sconosciuto parser calendario.';
            }

            throw new RuntimeException($error);
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            throw new RuntimeException('Parser calendario non ha restituito output.');
        }

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Output parser calendario non valido.');
        }

        $rawDates = $decoded['dates'] ?? null;
        if (! is_array($rawDates)) {
            throw new RuntimeException('Nessuna data vacanza trovata nel PDF.');
        }

        $dates = collect($rawDates)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($dates === []) {
            throw new RuntimeException('Nessuna data vacanza valida trovata nel PDF.');
        }

        $metadata = is_array($decoded['metadata'] ?? null)
            ? $decoded['metadata']
            : [];
        $metadata = $this->normalizeCalendarMetadata($metadata);

        return [
            'dates' => $dates,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function pythonCommand(): array
    {
        $this->ensureProjectVirtualEnvironment();

        $candidates = [];
        $venvPython = $this->venvPythonBinary();
        if ($venvPython !== null) {
            $candidates[] = [$venvPython];
        }
        $candidates = array_merge($candidates, $this->systemPythonCandidates());

        $usableWithPypdf = $this->resolveUsableCommand(
            $candidates,
            true
        );
        if ($usableWithPypdf !== null) {
            return $usableWithPypdf;
        }

        $usableWithoutPypdf = $this->resolveUsableCommand(
            $candidates,
            false
        );
        if ($usableWithoutPypdf !== null) {
            throw new RuntimeException(
                'Python trovato ma modulo "pypdf" assente. Ricrea scripts/.venv o installa le dipendenze Python del progetto.'
            );
        }

        throw new RuntimeException(
            'Interprete Python non trovato. Installa Python 3 e ricrea scripts/.venv.'
        );
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function normalizeCalendarMetadata(array $metadata): array
    {
        $schoolYear = trim((string) ($metadata['school_year'] ?? ''));
        if (preg_match('/^\d{4}-\d{4}$/', $schoolYear) !== 1) {
            throw new RuntimeException('Anno scolastico non rilevato nel PDF calendario.');
        }

        [$startYear, $endYear] = array_map('intval', explode('-', $schoolYear, 2));
        if ($endYear !== $startYear + 1) {
            throw new RuntimeException('Anno scolastico non coerente nel PDF calendario.');
        }

        $firstSemesterEnd = $this->normalizeMetadataDate(
            $metadata['first_semester_end_date'] ?? null,
            'fine primo semestre'
        );
        $secondSemesterStart = $this->normalizeMetadataDate(
            $metadata['second_semester_start_date'] ?? null,
            'inizio secondo semestre'
        );

        $metadata['school_year'] = $schoolYear;
        $metadata['school_year_start'] = $startYear;
        $metadata['school_year_end'] = $endYear;
        $metadata['first_semester_end_date'] = $firstSemesterEnd;
        $metadata['second_semester_start_date'] = $secondSemesterStart;

        return $metadata;
    }

    private function normalizeMetadataDate(mixed $value, string $label): string
    {
        $date = trim((string) ($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException("Data {$label} non rilevata nel PDF calendario.");
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException("Data {$label} non valida nel PDF calendario.");
        }

        return $date;
    }

    private function ensureProjectVirtualEnvironment(): void
    {
        $venvPython = $this->venvPythonBinary();
        if ($venvPython !== null && $this->commandIsUsable([$venvPython], true)) {
            return;
        }

        $bootstrapCommand = $this->resolveUsableCommand(
            $this->systemPythonCandidates(),
            false
        );
        if ($bootstrapCommand === null) {
            return;
        }

        $venvDirectory = base_path('scripts/.venv');
        $this->runBestEffort(
            new Process(
                [
                    ...$bootstrapCommand,
                    '-m',
                    'venv',
                    $venvDirectory,
                ],
                base_path()
            ),
            180
        );

        $createdVenvPython = $this->venvPythonBinary();
        if ($createdVenvPython === null || ! $this->commandIsUsable([$createdVenvPython], false)) {
            return;
        }

        $requirementsPath = base_path('scripts/requirements.txt');
        if (! is_file($requirementsPath)) {
            return;
        }

        $this->runBestEffort(
            new Process(
                [
                    $createdVenvPython,
                    '-m',
                    'pip',
                    'install',
                    '-r',
                    $requirementsPath,
                ],
                base_path()
            ),
            240
        );
    }

    /**
     * @param  array<int,array<int,string>>  $candidates
     * @return array<int,string>|null
     */
    private function resolveUsableCommand(array $candidates, bool $requirePypdf): ?array
    {
        foreach ($candidates as $candidate) {
            if ($this->commandIsUsable($candidate, $requirePypdf)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $command
     */
    private function commandIsUsable(array $command, bool $requirePypdf): bool
    {
        $firstToken = $command[0] ?? null;
        if ($firstToken === null || ! $this->commandFirstTokenLooksResolvable($firstToken)) {
            return false;
        }

        try {
            $versionCheck = new Process([...$command, '--version'], base_path());
            $versionCheck->setTimeout(10);
            $versionCheck->run();

            if (! $versionCheck->isSuccessful()) {
                return false;
            }

            if (! $requirePypdf) {
                return true;
            }

            $moduleCheck = new Process([...$command, '-c', 'import pypdf'], base_path());
            $moduleCheck->setTimeout(15);
            $moduleCheck->run();

            return $moduleCheck->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function commandFirstTokenLooksResolvable(string $token): bool
    {
        $looksLikePath = str_contains($token, '\\')
            || str_contains($token, '/')
            || str_contains($token, ':');

        if ($looksLikePath) {
            return is_file($token);
        }

        return true;
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function systemPythonCandidates(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                ['py', '-3'],
                ['python'],
            ];
        }

        return [
            ['python3'],
            ['python'],
        ];
    }

    private function runBestEffort(Process $process, int $timeoutSeconds): void
    {
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (Throwable) {
            // Fall back to other candidates if bootstrapping is not possible.
        }
    }

    private function venvPythonBinary(): ?string
    {
        $candidatePaths = $this->venvPythonCandidatePaths();

        foreach ($candidatePaths as $candidatePath) {
            if (is_file($candidatePath)) {
                return $candidatePath;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function venvPythonCandidatePaths(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                base_path('scripts/.venv/Scripts/python.exe'),
            ];
        }

        return [
            base_path('scripts/.venv/bin/python3'),
            base_path('scripts/.venv/bin/python'),
        ];
    }
}
