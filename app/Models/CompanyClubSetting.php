<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Company Club configuration. Exactly one row exists.
 *
 * `current()` is the only way anything should read this: it creates the row from
 * the configured defaults on first use, so a fresh install and a seeded one
 * behave identically and no caller has to handle a missing row.
 *
 * Changing the display name is cosmetic by design. Changing the rate or the
 * level cap affects FUTURE runs only - every calculation freezes both onto its
 * own run row, so history stays reproducible.
 */
class CompanyClubSetting extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reward_rate' => 'decimal:2',
            'max_upline_levels' => 'integer',
        ];
    }

    /**
     * The single settings row, created from config on first use.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'display_name' => (string) config('rewards.company_club.display_name', 'Company Club'),
            'reward_rate' => Money::of(config('rewards.rates.company_club', 50)),
            'max_upline_levels' => (int) config('rewards.company_club.max_upline_levels', 5),
            'status' => 'active',
        ]);
    }

    /** The rate as an exact decimal string, never a float. */
    public function rate(): string
    {
        return Money::of($this->reward_rate);
    }

    public function maxLevels(): int
    {
        return (int) $this->max_upline_levels;
    }

    public function name(): string
    {
        return $this->display_name !== '' ? $this->display_name : 'Company Club';
    }
}
