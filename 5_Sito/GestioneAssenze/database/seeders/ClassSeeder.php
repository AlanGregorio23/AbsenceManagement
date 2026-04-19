<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [];
        foreach (range(1, 4) as $year) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $section) {
                $classes[] = [
                    'name' => 'INF'.$year.$section,
                    'year' => (string) $year,
                    'section' => $section,
                    'active' => true,
                ];
            }
        }

        foreach ($classes as $row) {
            SchoolClass::query()->firstOrCreate(
                ['name' => $row['name']],
                $row
            );
        }
    }
}
