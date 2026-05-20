<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Project Manager', 'email' => 'pm@lhc.local', 'role' => 'manager'],
            ['name' => 'Business Owner', 'email' => 'owner@lhc.local', 'role' => 'owner'],
            ['name' => 'Catalogue Reviewer', 'email' => 'review@lhc.local', 'role' => 'reviewer'],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password'),
                    'role' => $user['role'],
                    'is_active' => true,
                ],
            );
        }
    }
}
