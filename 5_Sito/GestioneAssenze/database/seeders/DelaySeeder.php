<?php

namespace Database\Seeders;

use App\Models\Delay;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DelaySeeder extends Seeder
{
    public function run(): void
    {
        $class = SchoolClass::query()
            ->whereHas('teachers')
            ->with(['students', 'teachers'])
            ->first();
        $students = $class?->students ?? User::query()->where('role', 'student')->get();
        $recorder = $class?->teachers?->first()
            ?? User::query()->where('role', 'teacher')->first()
            ?? User::query()->where('role', 'admin')->first();

        if ($students->isEmpty() || $recorder === null) {
            return;
        }

        $rows = [
            [
                'delay_datetime' => Carbon::now()->subDays(1)->setTime(8, 15),
                'minutes' => 10,
                'notes' => 'Traffico',
                'status' => 'reported',
            ],
            [
                'delay_datetime' => Carbon::now()->subDays(5)->setTime(9, 5),
                'minutes' => 20,
                'notes' => 'Visita medica',
                'status' => 'justified',
            ],
            [
                'delay_datetime' => Carbon::now()->subDays(12)->setTime(8, 45),
                'minutes' => 15,
                'notes' => 'Mezzi in ritardo',
                'status' => 'registered',
            ],
        ];

        foreach ($rows as $index => $row) {
            $student = $students->get($index % $students->count());

            Delay::create([
                'student_id' => $student->id,
                'recorded_by' => $recorder->id,
                'delay_datetime' => $row['delay_datetime'],
                'minutes' => $row['minutes'],
                'justification_deadline' => Carbon::parse($row['delay_datetime'])->addWeekdays(5)->toDateString(),
                'notes' => $row['notes'],
                'status' => $row['status'],
                'count_in_semester' => true,
                'global' => false,
            ]);
        }
    }
}
