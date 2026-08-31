<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/**
 * Set a back-office password from the server.
 *
 * Used to set or replace a password from the server — including for an account
 * created before `users.password_plain` existed, which therefore has no
 * readable copy to look up.
 *
 * The password is asked for interactively rather than taken as an argument, so
 * it does not end up in the shell history or in `ps` output.
 */
class SetUserPassword extends Command
{
    protected $signature = 'app:set-password {email? : The account to change}';

    protected $description = 'Set a back-office account password (recovery for a forgotten one)';

    public function handle(): int
    {
        $email = $this->argument('email');

        if ($email === null) {
            $accounts = User::query()->orderBy('email')->pluck('email', 'email')->all();

            if ($accounts === []) {
                $this->error('There are no accounts. Run `php artisan db:seed --class=AdminUserSeeder` first.');

                return self::FAILURE;
            }

            $email = select('Which account?', $accounts);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email {$email}.");

            return self::FAILURE;
        }

        $new = password(
            label: "New password for {$user->email}",
            validate: fn (string $value) => match (true) {
                mb_strlen($value) < 8 => 'Use at least 8 characters.',
                ! preg_match('/[A-Za-z]/', $value) => 'Include at least one letter.',
                ! preg_match('/\d/', $value) => 'Include at least one number.',
                default => null,
            },
        );

        if (password(label: 'Type it again to confirm') !== $new) {
            $this->error('The two passwords did not match. Nothing was changed.');

            return self::FAILURE;
        }

        $user->setPassword($new);

        $this->info("Password updated for {$user->email}.");
        $this->line('A readable copy is kept in users.password_plain, at the client\'s request.');

        return self::SUCCESS;
    }
}
