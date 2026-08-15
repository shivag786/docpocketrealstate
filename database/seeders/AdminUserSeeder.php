<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first back-office operator.
 *
 * Idempotent: re-running the seeder will not duplicate or silently reset an
 * existing account's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@realstate.test');

        if (User::where('email', $email)->exists()) {
            $this->command?->warn("Admin user [{$email}] already exists — skipped.");

            return;
        }

        $password = env('ADMIN_PASSWORD', 'Admin@12345');

        User::create([
            'name' => env('ADMIN_NAME', 'System Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->command?->info("Admin user created: {$email}");
        $this->command?->warn('Change this password before any production use.');
    }
}
