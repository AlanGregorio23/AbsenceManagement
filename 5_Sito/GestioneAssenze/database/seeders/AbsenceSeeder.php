<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AbsenceSeeder extends Seeder
{
    public function run(): void
    {
        $class = SchoolClass::query()
            ->whereHas('teachers')
            ->with('students')
            ->first();
        $students = $class?->students ?? User::query()->where('role', 'student')->get();

        if ($students->isEmpty()) {
            return;
        }

        $rows = [
            [
                'start_date' => Carbon::now()->subDays(2)->toDateString(),
                'end_date' => Carbon::now()->subDays(2)->toDateString(),
                'reason' => 'Mal di testa',
                'status' => 'justified',
                'assigned_hours' => 4,
            ],
            [
                'start_date' => Carbon::now()->subDays(8)->toDateString(),
                'end_date' => Carbon::now()->subDays(8)->toDateString(),
                'reason' => 'Visita medica',
                'status' => 'reported',
                'assigned_hours' => 2,
            ],
            [
                'start_date' => Carbon::now()->subDays(15)->toDateString(),
                'end_date' => Carbon::now()->subDays(15)->toDateString(),
                'reason' => 'Influenza',
                'status' => 'arbitrary',
                'assigned_hours' => 6,
            ],
        ];

        foreach ($rows as $index => $row) {
            $student = $students->get($index % $students->count());

            Absence::create([
                'student_id' => $student->id,
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'reason' => $row['reason'],
                'status' => $row['status'],
                'assigned_hours' => $row['assigned_hours'],
                'medical_certificate_required' => false,
                'medical_certificate_deadline' => Carbon::parse($row['start_date'])->addDays(5),
            ]);
        }
    }
}
