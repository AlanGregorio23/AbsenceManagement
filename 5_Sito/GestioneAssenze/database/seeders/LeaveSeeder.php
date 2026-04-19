<?php

namespace Database\Seeders;

use App\Models\Leave;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $class = SchoolClass::query()
            ->whereHas('teachers')
            ->with(['students', 'teachers'])
            ->first();
        $students = $class?->students ?? User::query()->where('role', 'student')->get();
        $creator = $class?->teachers?->first()
            ?? User::query()->where('role', 'teacher')->first()
            ?? User::query()->where('role', 'admin')->first();

        if ($students->isEmpty() || $creator === null) {
            return;
        }

        $rows = [
            [
                'start_date' => Carbon::now()->addDays(2)->toDateString(),
                'end_date' => Carbon::now()->addDays(2)->toDateString(),
                'requested_hours' => 2,
                'reason' => 'Visita specialistica',
                'status' => 'awaiting_guardian_signature',
            ],
            [
                'start_date' => Carbon::now()->addDays(4)->toDateString(),
                'end_date' => Carbon::now()->addDays(4)->toDateString(),
                'requested_hours' => 3,
                'reason' => 'Progetto esterno',
                'status' => 'pre_approved',
            ],
            [
                'start_date' => Carbon::now()->subDays(6)->toDateString(),
                'end_date' => Carbon::now()->subDays(6)->toDateString(),
                'requested_hours' => 3,
                'reason' => 'Impegno familiare',
                'status' => 'registered',
            ],
            [
                'start_date' => Carbon::now()->subDays(14)->toDateString(),
                'end_date' => Carbon::now()->subDays(14)->toDateString(),
                'requested_hours' => 1,
                'reason' => 'Motivo personale',
                'status' => 'rejected',
            ],
        ];

        foreach ($rows as $index => $row) {
            $student = $students->get($index % $students->count());

            Leave::create([
                'student_id' => $student->id,
                'created_by' => $creator->id,
                'created_at_custom' => Carbon::parse($row['start_date'])->setTime(8, 30),
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'requested_hours' => $row['requested_hours'],
                'reason' => $row['reason'],
                'status' => $row['status'],
                'count_hours' => true,
            ]);
        }
    }
}
