<?php

namespace Database\Seeders;

use App\Models\OperationLogSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FourteenMonthsLoadSeeder extends Seeder
{
    private const TARGET_STUDENTS = 200;

    private const MONTHS_TO_GENERATE = 14;

    private const PRACTICES_PER_SCHOOL_DAY = 40;

    private const ABSENCE_PERCENT = 50;

    private const DELAY_PERCENT = 35;

    private const CHUNK_SIZE = 1000;

    public function run(): void
    {
        $faker = fake('it_IT');

        $studentIds = $this->ensureStudentPool($faker);
        $teacherIds = User::query()
            ->where('role', 'teacher')
            ->pluck('id')
            ->all();
        $adminIds = User::query()
            ->where('role', 'admin')
            ->pluck('id')
            ->all();
        $labManagerIds = User::query()
            ->where('role', 'laboratory_manager')
            ->pluck('id')
            ->all();

        $operatorIds = array_values(array_unique(array_merge(
            $teacherIds,
            $adminIds,
            $labManagerIds
        )));

        if (empty($operatorIds)) {
            $fallbackAdmin = User::query()->firstOrCreate(
                ['email' => 'loadtest.admin@example.com'],
                [
                    'name' => 'Load',
                    'surname' => 'Admin',
                    'role' => 'admin',
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                ]
            );
            $operatorIds = [$fallbackAdmin->id];
        }

        OperationLogSetting::firstOrDefault();

        $absences = [];
        $delays = [];
        $leaves = [];
        $logs = [];
        $virtualAbsenceId = 1;

        $practiceCount = 0;
        $absenceCount = 0;
        $delayCount = 0;
        $leaveCount = 0;
        $logCount = 0;

        $startDate = Carbon::today()->subMonthsNoOverflow(self::MONTHS_TO_GENERATE)->startOfDay();
        $endDate = Carbon::today()->startOfDay();

        for ($day = $startDate->copy(); $day->lte($endDate); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            for ($slot = 0; $slot < self::PRACTICES_PER_SCHOOL_DAY; $slot++) {
                $practiceType = $this->pickPracticeType();
                $studentId = $studentIds[array_rand($studentIds)];
                $actorId = $operatorIds[array_rand($operatorIds)];
                $createdAt = $this->randomSchoolTime($day);

                if ($practiceType === 'absence') {
                    $absence = $this->buildAbsenceRow($studentId, $actorId, $createdAt, $faker);
                    $absences[] = $absence['row'];
                    $absenceCount++;
                    $practiceCount++;

                    $absenceLogs = $this->buildAbsenceLogs(
                        $studentId,
                        $actorId,
                        $createdAt,
                        $absence['meta'],
                        $virtualAbsenceId,
                        $faker
                    );

                    foreach ($absenceLogs as $logRow) {
                        $logs[] = $logRow;
                    }

                    $logCount += count($absenceLogs);
                    $virtualAbsenceId++;
                } elseif ($practiceType === 'delay') {
                    $delays[] = $this->buildDelayRow($studentId, $actorId, $createdAt, $faker);
                    $delayCount++;
                    $practiceCount++;
                } else {
                    $leaves[] = $this->buildLeaveRow($studentId, $actorId, $createdAt, $faker);
                    $leaveCount++;
                    $practiceCount++;
                }

                $this->flushChunkIfNeeded('absences', $absences);
                $this->flushChunkIfNeeded('delays', $delays);
                $this->flushChunkIfNeeded('leaves', $leaves);
                $this->flushChunkIfNeeded('operation_logs', $logs);
            }
        }

        $this->flushChunk('absences', $absences);
        $this->flushChunk('delays', $delays);
        $this->flushChunk('leaves', $leaves);
        $this->flushChunk('operation_logs', $logs);

        if ($this->command) {
            $this->command->info(
                'FourteenMonthsLoadSeeder completato: '
                .$practiceCount.' pratiche totali ('
                .$absenceCount.' assenze, '
                .$delayCount.' ritardi, '
                .$leaveCount.' congedi) e '
                .$logCount.' operation_logs generati.'
            );
        }
    }

    private function ensureStudentPool(\Faker\Generator $faker): array
    {
        $studentIds = User::query()
            ->where('role', 'student')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $missing = self::TARGET_STUDENTS - count($studentIds);
        if ($missing > 0) {
            $rows = [];
            for ($i = 0; $i < $missing; $i++) {
                $suffix = strtoupper(bin2hex(random_bytes(4)));
                $rows[] = [
                    'name' => $faker->firstName(),
                    'surname' => $faker->lastName(),
                    'email' => 'load.student.'.$suffix.'@student.example.com',
                    'role' => 'student',
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('users')->insert($rows);
        }

        return User::query()
            ->where('role', 'student')
            ->orderBy('id')
            ->limit(self::TARGET_STUDENTS)
            ->pluck('id')
            ->all();
    }

    private function pickPracticeType(): string
    {
        $roll = random_int(1, 100);

        if ($roll <= self::ABSENCE_PERCENT) {
            return 'absence';
        }

        if ($roll <= self::ABSENCE_PERCENT + self::DELAY_PERCENT) {
            return 'delay';
        }

        return 'leave';
    }

    private function randomSchoolTime(Carbon $day): Carbon
    {
        return $day->copy()->setTime(
            random_int(7, 17),
            random_int(0, 59),
            random_int(0, 59)
        );
    }

    /**
     * @return array{row:array<string,mixed>,meta:array<string,mixed>}
     */
    private function buildAbsenceRow(
        int $studentId,
        int $actorId,
        Carbon $createdAt,
        \Faker\Generator $faker
    ): array {
        $durationDays = random_int(1, 3);
        $startDate = $createdAt->copy()->toDateString();
        $endDate = $createdAt->copy()->addDays($durationDays - 1)->toDateString();
        $assignedHours = random_int(1, max(1, $durationDays * 8));

        $statusRoll = random_int(1, 100);
        $status = $statusRoll <= 25
            ? 'reported'
            : ($statusRoll <= 80 ? 'justified' : 'arbitrary');

        $medicalRequired = $durationDays >= 3;
        $hasCertificate = $medicalRequired && random_int(1, 100) <= 62;
        $certificateAccepted = $hasCertificate && random_int(1, 100) <= 72;
        $guardianSigned = random_int(1, 100) <= 78;

        $counts40Hours = $status === 'arbitrary'
            ? true
            : (! $certificateAccepted && random_int(1, 100) <= 80);

        $deadline = Carbon::parse($endDate)->addDays(10)->toDateString();
        $teacherComment = null;
        if ($status === 'justified') {
            $teacherComment = 'Validata dal docente.';
        } elseif ($status === 'arbitrary') {
            $teacherComment = 'Assenza rifiutata o scaduta.';
        }

        return [
            'row' => [
                'student_id' => $studentId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $faker->randomElement([
                    'Motivi familiari',
                    'Visita medica',
                    'Influenza',
                    'Trasporto in ritardo',
                    'Impegno personale',
                ]),
                'status' => $status,
                'assigned_hours' => $assignedHours,
                'counts_40_hours' => $counts40Hours,
                'counts_40_hours_comment' => $counts40Hours
                    ? null
                    : 'Esclusa dal conteggio per certificato/richiesta docente.',
                'medical_certificate_deadline' => $deadline,
                'medical_certificate_required' => $medicalRequired,
                'approved_without_guardian' => $status === 'justified' && ! $guardianSigned,
                'teacher_comment' => $teacherComment,
                'certificate_rejection_comment' => $hasCertificate && ! $certificateAccepted
                    ? 'Documento non valido, richiedere nuovo caricamento.'
                    : null,
                'hours_decided_at' => in_array($status, ['justified', 'arbitrary'], true)
                    ? $createdAt->copy()->addHours(random_int(3, 96))
                    : null,
                'hours_decided_by' => in_array($status, ['justified', 'arbitrary'], true)
                    ? $actorId
                    : null,
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => $createdAt->copy()->addMinutes(random_int(1, 40))->toDateTimeString(),
            ],
            'meta' => [
                'guardian_signed' => $guardianSigned,
                'status' => $status,
                'medical_required' => $medicalRequired,
                'has_certificate' => $hasCertificate,
                'certificate_accepted' => $certificateAccepted,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<int,array<string,mixed>>
     */
    private function buildAbsenceLogs(
        int $studentId,
        int $actorId,
        Carbon $createdAt,
        array $meta,
        int $virtualAbsenceId,
        \Faker\Generator $faker
    ): array {
        $logs = [];
        $ip = $faker->ipv4();
        $baseTimestamp = $createdAt->copy();

        $logs[] = $this->buildLogRow(
            $actorId,
            'INFO',
            'absence.request.created',
            'absence',
            $virtualAbsenceId,
            [
                'student_id' => $studentId,
                'source' => 'load-test',
            ],
            $ip,
            $baseTimestamp->copy()->addSeconds(15)
        );

        $emailRoll = random_int(1, 100);
        if ($emailRoll <= 8) {
            $logs[] = $this->buildLogRow(
                $actorId,
                'WARNING',
                'absence.guardian_confirmation_email.missing_guardian',
                'absence',
                $virtualAbsenceId,
                [
                    'student_id' => $studentId,
                ],
                $ip,
                $baseTimestamp->copy()->addMinutes(2)
            );
        } elseif ($emailRoll <= 18) {
            $logs[] = $this->buildLogRow(
                $actorId,
                'ERROR',
                'absence.guardian_confirmation_email.failed',
                'absence',
                $virtualAbsenceId,
                [
                    'student_id' => $studentId,
                    'error' => 'SMTP timeout simulato load test',
                ],
                $ip,
                $baseTimestamp->copy()->addMinutes(2)
            );
        } else {
            $logs[] = $this->buildLogRow(
                $actorId,
                'INFO',
                'absence.guardian_confirmation_email.sent',
                'absence',
                $virtualAbsenceId,
                [
                    'student_id' => $studentId,
                    'guardian_id' => random_int(1, 400),
                    'token_id' => random_int(1, 999999),
                ],
                $ip,
                $baseTimestamp->copy()->addMinutes(2)
            );
        }

        if ((bool) ($meta['guardian_signed'] ?? false)) {
            $logs[] = $this->buildLogRow(
                null,
                'INFO',
                'absence.guardian.signature.confirmed',
                'absence',
                $virtualAbsenceId,
                [
                    'student_id' => $studentId,
                    'guardian_id' => random_int(1, 400),
                ],
                $ip,
                $baseTimestamp->copy()->addHours(random_int(1, 48))
            );
        }

        $status = (string) ($meta['status'] ?? 'reported');
        if ($status === 'justified') {
            $logs[] = $this->buildLogRow(
                $actorId,
                'INFO',
                (bool) ($meta['guardian_signed'] ?? false)
                    ? 'absence.approved'
                    : 'absence.approved_without_guardian',
                'absence',
                $virtualAbsenceId,
                [
                    'student_id' => $studentId,
                    'with_guardian_signature' => (bool) ($meta['guardian_signed'] ?? false),
                ],
                $ip,
                $baseTimestamp->copy()->addHours(random_int(6, 120))
            );
        } elseif ($status === 'arbitrary') {
            $logs[] = $this->buildLogRow(
                $actorId,
                'INFO',
                'absence.rejected',
                'absence',
                $virtualAbsenceId,
                [
                    'student_id' => $studentId,
                ],
                $ip,
                $baseTimestamp->copy()->addHours(random_int(6, 120))
            );
        }

        if ((bool) ($meta['has_certificate'] ?? false)) {
            $logs[] = $this->buildLogRow(
                $actorId,
                'INFO',
                'absence.certificate.uploaded',
                'medical_certificate',
                random_int(1, 999999),
                [
                    'absence_id' => $virtualAbsenceId,
                    'source' => 'student_documents',
                ],
                $ip,
                $baseTimestamp->copy()->addHours(random_int(2, 72))
            );

            $logs[] = $this->buildLogRow(
                $actorId,
                'INFO',
                (bool) ($meta['certificate_accepted'] ?? false)
                    ? 'absence.certificate.accepted'
                    : 'absence.certificate.rejected',
                'medical_certificate',
                random_int(1, 999999),
                [
                    'absence_id' => $virtualAbsenceId,
                ],
                $ip,
                $baseTimestamp->copy()->addHours(random_int(12, 168))
            );
        }

        return $logs;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDelayRow(
        int $studentId,
        int $actorId,
        Carbon $createdAt,
        \Faker\Generator $faker
    ): array {
        $minutes = random_int(3, 65);
        $statusRoll = random_int(1, 100);
        $status = $statusRoll <= 35
            ? 'reported'
            : ($statusRoll <= 85 ? 'justified' : 'registered');
        $deadline = Carbon::parse($createdAt)->addWeekdays(5)->toDateString();

        return [
            'student_id' => $studentId,
            'recorded_by' => $actorId,
            'delay_datetime' => $createdAt->toDateTimeString(),
            'minutes' => $minutes,
            'justification_deadline' => $deadline,
            'notes' => $faker->randomElement([
                'Traffico',
                'Trasporto pubblico in ritardo',
                'Visita breve',
                'Problema familiare',
                'Ritardo ingresso autorizzato',
            ]),
            'status' => $status,
            'teacher_comment' => $status === 'reported'
                ? null
                : ($status === 'justified'
                    ? 'Ritardo validato dal docente (simulazione).'
                    : 'Ritardo registrato per scadenza/decisione docente (simulazione).'),
            'validated_at' => $status === 'reported'
                ? null
                : $createdAt->copy()->addHours(random_int(2, 48))->toDateTimeString(),
            'validated_by' => $status === 'reported' ? null : $actorId,
            'auto_arbitrary_at' => $status === 'registered' && random_int(1, 100) <= 50
                ? $createdAt->copy()->addHours(random_int(24, 96))->toDateTimeString()
                : null,
            'count_in_semester' => true,
            'exclusion_comment' => null,
            'global' => random_int(1, 100) <= 5,
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->copy()->addMinutes(random_int(0, 20))->toDateTimeString(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLeaveRow(
        int $studentId,
        int $actorId,
        Carbon $createdAt,
        \Faker\Generator $faker
    ): array {
        $start = $createdAt->copy()->addDays(random_int(-5, 12))->startOfDay();
        $durationDays = random_int(1, 2);
        $end = $start->copy()->addDays($durationDays - 1);
        $status = $faker->randomElement([
            'awaiting_guardian_signature',
            'signed',
            'pre_approved',
            'documentation_requested',
            'in_review',
            'registered',
            'rejected',
        ]);

        return [
            'student_id' => $studentId,
            'created_by' => $actorId,
            'created_at_custom' => $createdAt->toDateTimeString(),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'requested_hours' => random_int(1, 8),
            'reason' => $faker->randomElement([
                'Visita specialistica',
                'Impegno familiare',
                'Pratica amministrativa',
                'Attivita extrascolastica',
                'Motivo personale',
            ]),
            'status' => $status,
            'count_hours' => random_int(1, 100) <= 82,
            'hours_decision_at' => in_array($status, ['registered', 'rejected'], true)
                ? $createdAt->copy()->addHours(random_int(3, 72))->toDateTimeString()
                : null,
            'hours_decision_by' => in_array($status, ['registered', 'rejected'], true)
                ? $actorId
                : null,
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->copy()->addMinutes(random_int(5, 90))->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildLogRow(
        ?int $userId,
        string $level,
        string $action,
        string $entity,
        ?int $entityId,
        array $payload,
        string $ip,
        Carbon $createdAt
    ): array {
        return [
            'user_id' => $userId,
            'level' => $level,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $ip,
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function flushChunkIfNeeded(string $table, array &$rows): void
    {
        if (count($rows) < self::CHUNK_SIZE) {
            return;
        }

        $this->flushChunk($table, $rows);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function flushChunk(string $table, array &$rows): void
    {
        if (empty($rows)) {
            return;
        }

        DB::table($table)->insert($rows);
        $rows = [];
    }
}
