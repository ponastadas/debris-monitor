<?php

namespace Database\Seeders;

use App\Models\AdminAccount;
use Illuminate\Database\Seeder;

class AdminAccountSeeder extends Seeder
{
    /**
     * Seed the initial admin account in the admin_accounts table.
     * Safe to run multiple times — firstOrCreate is idempotent.
     * The 'hashed' cast on AdminAccount handles bcrypt automatically.
     */
    public function run(): void
    {
        AdminAccount::firstOrCreate(
            ['email' => 'admin@debris.monitor'],
            [
                'name'      => 'Admin',
                'password'  => 'admin',
                'is_active' => true,
            ]
        );
    }
}
