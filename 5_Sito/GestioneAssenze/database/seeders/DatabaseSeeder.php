<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ClassSeeder::class,
            ClassAssignmentSeeder::class,
            // AbsenceSeeder::class,
            // MedicalCertificateSeeder::class,
            // DelaySeeder::class,
            // LeaveSeeder::class,
            // AlanMonthlyReportDemoSeeder::class, // Demo completa report mensile Alan + invio email studente/tutore.
            // FourteenMonthsLoadSeeder::class, // Seeder stress test: 14 mesi, 40 pratiche/giorno.
            // OperationLogStressSeeder::class, // Seeder stress test: 50k+ operation_logs su 14 mesi.
            OperationLogSeeder::class,
            SystemRulesSeeder::class,
        ]);
    }
}
