<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the admin user if it does not already exist.
     * Safe to run multiple times (firstOrCreate is idempotent).
     * Pass plain-text password — the User model's 'hashed' cast handles bcrypt automatically.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@debris.monitor'],
            [
                'name'     => 'Admin',
                'password' => 'admin',
                'role'     => 'admin',
                'status'   => 'active',
            ]
        );
    }
}
