<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the Verlinde Beveiliging BV team accounts.
     */
    public function run(): void
    {
        $password = Hash::make('secret123');

        $users = [
            [
                'name' => 'Dirk Verlinde',
                'email' => 'dirk@verlinde.be',
                'role' => UserRole::OWNER,
                'is_active' => true,
            ],
            [
                'name' => 'Tom De Coninck',
                'email' => 'tom@verlinde.be',
                'role' => UserRole::TECHNICIAN,
                'is_active' => true,
            ],
            [
                'name' => 'Wout Vanhoutte',
                'email' => 'wout@verlinde.be',
                'role' => UserRole::TECHNICIAN,
                'is_active' => true,
            ],
            [
                'name' => 'Jens Maes',
                'email' => 'jens@verlinde.be',
                'role' => UserRole::TECHNICIAN,
                'is_active' => true,
            ],
            [
                'name' => 'Brandtec (Subcontractor)',
                'email' => 'contact@brandtec.test',
                'role' => UserRole::TECHNICIAN,
                'is_active' => true,
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => $password,
                    'role' => $userData['role'],
                    'is_active' => $userData['is_active'],
                ]
            );
        }
    }
}