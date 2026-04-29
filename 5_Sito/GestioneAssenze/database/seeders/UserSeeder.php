<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@cpt.local'],
            [
                'name' => 'Admin',
                'surname' => 'CPT',
                'role' => 'admin',
                'birth_date' => '1980-01-01',
                'password' => Hash::make('Trevano26!'),
                'active' => true,
            ]
        );
    }
}
