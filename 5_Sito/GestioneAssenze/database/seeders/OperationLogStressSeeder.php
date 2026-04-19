<?php

namespace Database\Seeders;

use App\Models\OperationLogSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OperationLogStressSeeder extends Seeder
{
    private const DEFAULT_TARGET_LOGS = 50000;

    private const DEFAULT_MONTHS_SPAN = 14;

    private const DEFAULT_CHUNK_SIZE = 1500;

    private const INFO_ACTIONS = [
        'absence.request.created',
        'absence.updated',
        'absence.quick.updated',
        'absence.approved',
        'absence.approved_without_guardian',
        'absence.guardian_confirmation_email.sent',
        'absence.guardian.signature.confirmed',
        'absence.certificate.uploaded',
        'absence.certificate.accepted',
        'admin.user.updated',
        'admin.student.guardian.assigned',
        'admin.class.updated',
    ];

    private const WARNING_ACTIONS = [
        'absence.deadline.extended',
        'absence.guardian_confirmation_email.missing_guardian',
        'absence.certificate.rejected',
    ];

    private const ERROR_ACTIONS = [
        'absence.guardian_confirmation_email.failed',
        'absence.certificate.rejected',
    ];

    private const ENTITIES = [
        'absence',
        'medical_certificate',
        'guardian_absence_confirmation',
        'user',
        'student',
        'class',
    ];

    public function run(): void
    {
        $targets = $this->resolveTargets();
        $monthsSpan = max(
            1,
            (int) env('LOG_STRESS_MONTHS', self::DEFAULT_MONTHS_SPAN)
        );
        $chunkSize = max(
            250,
            (int) env('LOG_STRESS_CHUNK', self::DEFAULT_CHUNK_SIZE)
        );
        $truncateFirst = $this->resolveBooleanEnv('LOG_STRESS_TRUNCATE_FIRST', false);

        OperationLogSetting::firstOrDefault();
        $faker = fake('it_IT');

        if ($truncateFirst) {
            DB::table('operation_logs')->truncate();
        }

        $userIds = User::query()->pluck('id')->all();
        if (empty($userIds)) {
            $fallbackAdmin = User::query()->firstOrCreate(
                ['email' => 'log.stress.admin@example.com'],
                [
                    'name' => 'Log',
                    'surname' => 'Stress',
                    'role' => 'admin',
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                ]
            );

            $userIds = [(int) $fallbackAdmin->id];
        }

        $currentTotal = (int) DB::table('operation_logs')->count();
        $sequence = $currentTotal + 1;

        foreach ($targets as $targetTotal) {
            if ($targetTotal <= $currentTotal) {
                if ($this->command) {
                    $this->command->line(
                        'Target '.$targetTotal.' gia raggiunto (totale attuale: '.$currentTotal.').'
                    );
                }

                continue;
            }

            $toCreate = $targetTotal - $currentTotal;
            $inserted = $this->insertLogs(
                $toCreate,
                $monthsSpan,
                $chunkSize,
                $userIds,
                $sequence,
                $faker
            );

            $currentTotal += $inserted;
            $sequence += $inserted;

            if ($this->command) {
                $this->command->info(
                    'Target '.$targetTotal.' raggiunto: '
                    .$inserted.' log aggiunti (totale '.$currentTotal.').'
                );
            }
        }

        if ($this->command) {
            $targetsText = implode(', ', $targets);
            $this->command->info(
                'OperationLogStressSeeder completato. Target richiesti: '
                .$targetsText.'. Totale finale: '.$currentTotal.'.'
            );
        }
    }

    private function resolveTargets(): array
    {
        $rawTargets = trim((string) env('LOG_STRESS_TARGETS', ''));

        if ($rawTargets !== '') {
            $targets = collect(explode(',', $rawTargets))
                ->map(fn (string $value) => (int) trim($value))
                ->filter(fn (int $value) => $value > 0)
                ->map(fn (int $value) => max(40000, $value))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (! empty($targets)) {
                return $targets;
            }
        }

        return [
            max(40000, (int) env('LOG_STRESS_TARGET', self::DEFAULT_TARGET_LOGS)),
        ];
    }

    private function resolveBooleanEnv(string $key, bool $default): bool
    {
        $value = env($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    /**
     * @param  array<int,int>  $userIds
     */
    private function insertLogs(
        int $logsToInsert,
        int $monthsSpan,
        int $chunkSize,
        array $userIds,
        int $startSequence,
        \Faker\Generator $faker
    ): int {
        $startDate = Carbon::today()->subMonthsNoOverflow($monthsSpan)->startOfDay();
        $endDate = Carbon::today()->endOfDay();

        $buffer = [];
        $inserted = 0;

        for ($index = 0; $index < $logsToInsert; $index++) {
            $sequence = $startSequence + $index;
            $level = $this->pickLevel();
            $action = $this->pickActionByLevel($level);
            $entity = self::ENTITIES[array_rand(self::ENTITIES)];
            $createdAt = $this->randomSafeDateTime($startDate, $endDate);

            $buffer[] = [
                'user_id' => random_int(1, 100) <= 88
                    ? $userIds[array_rand($userIds)]
                    : null,
                'level' => $level,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => random_int(1, 100) <= 82
                    ? random_int(1, 250000)
                    : null,
                'payload' => json_encode([
                    'seed_test' => 'operation_logs_stress',
                    'sequence' => $sequence,
                    'source' => random_int(1, 100) <= 75 ? 'backend' : 'api',
                    'note' => $faker->sentence(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip' => $faker->ipv4(),
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => $createdAt->toDateTimeString(),
            ];

            if (count($buffer) >= $chunkSize) {
                DB::table('operation_logs')->insert($buffer);
                $inserted += count($buffer);
                $buffer = [];
            }
        }

        if (! empty($buffer)) {
            DB::table('operation_logs')->insert($buffer);
            $inserted += count($buffer);
        }

        return $inserted;
    }

    private function randomSafeDateTime(Carbon $startDate, Carbon $endDate): Carbon
    {
        $daysRange = max(
            0,
            $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay())
        );
        $day = $startDate->copy()->startOfDay()->addDays(random_int(0, $daysRange));
        $candidate = $day->copy()->setTime(
            random_int(6, 23),
            random_int(0, 59),
            random_int(0, 59)
        );

        if ($candidate->lt($startDate)) {
            return $startDate->copy();
        }

        if ($candidate->gt($endDate)) {
            return $endDate->copy();
        }

        return $candidate;
    }

    private function pickLevel(): string
    {
        $roll = random_int(1, 100);

        if ($roll <= 78) {
            return 'INFO';
        }

        if ($roll <= 93) {
            return 'WARNING';
        }

        return 'ERROR';
    }

    private function pickActionByLevel(string $level): string
    {
        if ($level === 'ERROR') {
            return self::ERROR_ACTIONS[array_rand(self::ERROR_ACTIONS)];
        }

        if ($level === 'WARNING') {
            return self::WARNING_ACTIONS[array_rand(self::WARNING_ACTIONS)];
        }

        return self::INFO_ACTIONS[array_rand(self::INFO_ACTIONS)];
    }
}
