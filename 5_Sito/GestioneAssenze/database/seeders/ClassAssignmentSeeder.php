<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClassAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $classes = SchoolClass::query()
            ->orderBy('year')
            ->orderBy('section')
            ->orderBy('name')
            ->get();
        $students = User::query()
            ->where('role', 'student')
            ->orderBy('id')
            ->get();
        $teachers = User::query()
            ->where('role', 'teacher')
            ->orderBy('id')
            ->get();

        if ($classes->isEmpty()) {
            return;
        }

        if ($students->isNotEmpty()) {
            $classCount = $classes->count();
            $studentAssignments = [];

            foreach ($students as $index => $student) {
                $class = $classes->get($index % $classCount);
                if (! $class) {
                    continue;
                }

                $studentAssignments[$class->id][$student->id] = [
                    'start_date' => now()->subDays(($index % 240) + 1)->toDateString(),
                    'end_date' => null,
                ];
            }

            foreach ($classes as $class) {
                if (! isset($studentAssignments[$class->id])) {
                    continue;
                }

                $class->students()->syncWithoutDetaching($studentAssignments[$class->id]);
            }
        }

        if ($teachers->isNotEmpty()) {
            $teacherCount = $teachers->count();

            foreach ($classes as $index => $class) {
                $primaryTeacher = $teachers->get($index % $teacherCount);
                if (! $primaryTeacher) {
                    continue;
                }

                $syncData = [
                    $primaryTeacher->id => [
                        'start_date' => now()->subMonths(($index % 8) + 1)->toDateString(),
                        'end_date' => null,
                    ],
                ];

                if ($teacherCount > 1 && $index % 3 === 0) {
                    $secondaryTeacher = $teachers->get(($index + 1) % $teacherCount);
                    if ($secondaryTeacher) {
                        $syncData[$secondaryTeacher->id] = [
                            'start_date' => now()->subMonths(($index % 6) + 1)->toDateString(),
                            'end_date' => null,
                        ];
                    }
                }

                $class->teachers()->syncWithoutDetaching($syncData);
            }
        }
    }
}
