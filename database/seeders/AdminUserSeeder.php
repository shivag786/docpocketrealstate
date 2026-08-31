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
        $email = env('ADMIN_EMAIL', 'admin@docpocketrealstate.com');

        if (User::where('email', $email)->exists()) {
            $this->command?->warn("Admin user [{$email}] already exists — skipped.");

            return;
        }

        $password = env('ADMIN_PASSWORD', 'Admin@12345');

        $user = User::create([
            'name' => env('ADMIN_NAME', 'System Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        // The readable copy the client asked for. Set here too, so a freshly
        // seeded install can look the password up straight away rather than
        // having to change it once first.
        $user->forceFill(['password_plain' => $password])->save();

        $this->command?->info("Admin user created: {$email}");
        $this->command?->warn('Change this password before any production use.');
    }
}
