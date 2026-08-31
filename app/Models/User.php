<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A back-office operator (Admin or Manager).
 *
 * Network members are a separate concept and do not log in.
 */
#[Fillable(['name', 'email', 'password', 'role', 'status'])]
// password_plain is hidden from serialisation even though it is stored
// readable on purpose: the client wants it visible in the database and on
// the Settings screen, NOT leaking into every JSON response and log line
// that happens to include a user.
#[Hidden(['password', 'password_plain', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Set the password, recording the readable copy alongside the hash.
     *
     * CLIENT DECISION (2026-08-31) — see the add_password_plain_to_users
     * migration for the trade-off this represents. Every path that changes a
     * password goes through here, so the two columns cannot drift apart: a hash
     * that says one thing and a readable copy that says another would be worse
     * than having no readable copy at all.
     *
     * `password` is still the only column authentication reads.
     */
    public function setPassword(string $plain): void
    {
        $this->forceFill([
            // The `hashed` cast turns this into a bcrypt hash on save.
            'password' => $plain,
            'password_plain' => $plain,
        ])->save();
    }

    /**
     * The readable password, or null for an account set before this was kept.
     *
     * A hash cannot be reversed, so accounts that predate the column have no
     * readable copy and never will until their password is next set.
     */
    public function readablePassword(): ?string
    {
        return $this->password_plain;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }
}
