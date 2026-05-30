<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Admin User', 'email' => 'admin@example.com', 'password' => 'admin', 'role' => 'admin'],
            ['name' => 'Organizer User', 'email' => 'organizer@example.com', 'password' => 'organizer', 'role' => 'admin'],
            ['name' => 'Regular User', 'email' => 'user@example.com', 'password' => 'user', 'role' => 'user'],
        ];

        // Assign roles
        foreach ($users as $seedUser) {
            $user = User::updateOrCreate(
                ['email' => $seedUser['email']],
                [
                    'name' => $seedUser['name'],
                    'password' => bcrypt($seedUser['password']),
                ],
            );

            $user->syncRoles([$seedUser['role']]);
        }
    }
}
