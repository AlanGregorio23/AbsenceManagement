<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $faker = fake('it_IT');
        $users = [
            [
                'name' => 'Alan',
                'surname' => 'Gregorio',
                'email' => 'alan.gregorio@example.com',
                'role' => 'student',
                'birth_date' => '2008-03-11',
            ],
            [
                'name' => 'Sara',
                'surname' => 'Bianchi',
                'email' => 'sara.bianchi@example.com',
                'role' => 'student',
                'birth_date' => '2008-03-12',
            ],
            [
                'name' => 'Marco',
                'surname' => 'Conti',
                'email' => 'marco.conti@example.com',
                'role' => 'student',
                'birth_date' => '2007-09-21',
            ],
            [
                'name' => 'Giulia',
                'surname' => 'Verdi',
                'email' => 'giulia.verdi@example.com',
                'role' => 'student',
                'birth_date' => '2009-05-03',
            ],
            [
                'name' => 'Paolo',
                'surname' => 'Rossi',
                'email' => 'paolo.rossi@example.com',
                'role' => 'teacher',
                'birth_date' => '1984-02-17',
            ],
            [
                'name' => 'Marta',
                'surname' => 'Neri',
                'email' => 'marta.neri@example.com',
                'role' => 'teacher',
                'birth_date' => '1987-11-08',
            ],
            [
                'name' => 'Luca',
                'surname' => 'Galli',
                'email' => 'luca.galli@example.com',
                'role' => 'laboratory_manager',
                'birth_date' => '1982-07-01',
            ],
            [
                'name' => 'Admin',
                'surname' => 'Sistema',
                'email' => 'admin@example.com',
                'role' => 'admin',
                'birth_date' => '1980-01-01',
            ],
        ];

        foreach ($users as $row) {
            User::query()->firstOrCreate(
                ['email' => $row['email']],
                array_merge($row, [
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                ])
            );
        }

        for ($i = 1; $i <= 180; $i++) {
            User::query()->firstOrCreate(
                ['email' => sprintf('studente%03d@student.example.com', $i)],
                [
                    'name' => $faker->firstName(),
                    'surname' => $faker->lastName(),
                    'role' => 'student',
                    'birth_date' => $faker->dateTimeBetween('-21 years', '-14 years')->format('Y-m-d'),
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                ]
            );
        }

        for ($i = 1; $i <= 36; $i++) {
            User::query()->firstOrCreate(
                ['email' => sprintf('docente%03d@example.com', $i)],
                [
                    'name' => $faker->firstName(),
                    'surname' => $faker->lastName(),
                    'role' => 'teacher',
                    'birth_date' => $faker->dateTimeBetween('-65 years', '-28 years')->format('Y-m-d'),
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                ]
            );
        }

        for ($i = 1; $i <= 12; $i++) {
            User::query()->firstOrCreate(
                ['email' => sprintf('lab%03d@example.com', $i)],
                [
                    'name' => $faker->firstName(),
                    'surname' => $faker->lastName(),
                    'role' => 'laboratory_manager',
                    'birth_date' => $faker->dateTimeBetween('-65 years', '-28 years')->format('Y-m-d'),
                    'password' => Hash::make('Admin$00'),
                    'active' => true,
                ]
            );
        }
    }
}
