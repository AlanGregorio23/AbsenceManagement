<?php

namespace Database\Seeders;

use App\Models\OperationLog;
use App\Models\User;
use Illuminate\Database\Seeder;

class OperationLogSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $faker = fake();
        $userIds = User::query()->pluck('id')->all();
        $interactionEntities = ['absence', 'user', 'student', 'class'];
        $interactionActions = [
            'absence.request.created',
            'absence.updated',
            'absence.approved',
            'admin.user.updated',
            'admin.class.updated',
            'admin.student.guardian.assigned',
            'admin.student.guardian.removed',
        ];

        $errorActions = [
            'absence.guardian_confirmation_email.failed',
            'absence.certificate.rejected',
            'absence.guardian_confirmation_email.missing_guardian',
            'absence.certificate.uploaded',
        ];

        $warningActions = [
            'absence.deadline.extended',
            'absence.guardian_confirmation_email.missing_guardian',
            'absence.certificate.rejected',
            'admin.user.deactivated',
        ];

        $infoCount = 700;
        $warningCount = 260;
        $errorCount = 220;

        $logs = [];
        for ($i = 0; $i < $infoCount; $i++) {
            $createdAt = $faker->dateTimeBetween('-30 days', 'now');

            $logs[] = [
                'user_id' => $faker->boolean(85) && count($userIds) > 0
                    ? $faker->randomElement($userIds)
                    : null,
                'level' => 'INFO',
                'action' => $faker->randomElement($interactionActions),
                'entity' => $faker->randomElement($interactionEntities),
                'entity_id' => $faker->boolean(80) ? $faker->numberBetween(1, 500) : null,
                'payload' => json_encode([
                    'seed_test' => 'interactions_limit',
                    'index' => $i + 1,
                    'note' => $faker->sentence(),
                    'source' => $faker->randomElement(['backend', 'api']),
                ]),
                'ip' => $faker->ipv4(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        for ($i = 0; $i < $warningCount; $i++) {
            $createdAt = $faker->dateTimeBetween('-30 days', 'now');

            $logs[] = [
                'user_id' => $faker->boolean(85) && count($userIds) > 0
                    ? $faker->randomElement($userIds)
                    : null,
                'level' => 'WARNING',
                'action' => $faker->randomElement($warningActions),
                'entity' => $faker->randomElement($interactionEntities),
                'entity_id' => $faker->boolean(80) ? $faker->numberBetween(1, 500) : null,
                'payload' => json_encode([
                    'seed_test' => 'error_logs_limit',
                    'index' => $i + 1,
                    'note' => $faker->sentence(),
                    'source' => 'backend',
                ]),
                'ip' => $faker->ipv4(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        for ($i = 0; $i < $errorCount; $i++) {
            $createdAt = $faker->dateTimeBetween('-30 days', 'now');

            $logs[] = [
                'user_id' => $faker->boolean(80) && count($userIds) > 0
                    ? $faker->randomElement($userIds)
                    : null,
                'level' => 'ERROR',
                'action' => $faker->randomElement($errorActions),
                'entity' => $faker->randomElement(['absence', 'medical_certificate']),
                'entity_id' => $faker->boolean(85) ? $faker->numberBetween(1, 500) : null,
                'payload' => json_encode([
                    'seed_test' => 'error_logs_limit',
                    'index' => $i + 1,
                    'error' => $faker->sentence(),
                    'source' => 'backend',
                ]),
                'ip' => $faker->ipv4(),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        OperationLog::insert($logs);
    }
}
