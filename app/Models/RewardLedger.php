<?php

namespace App\Models;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single reward amount owed to a member.
 *
 * Append-only: rows are never updated in place. If a reward ever has to be
 * undone it must be a counter-entry tied to a new calculation run, so the
 * history of what was paid and why stays intact.
 *
 * The four reward types share this table but never share arithmetic — always
 * filter by `reward_type` (docs/02_BUSINESS_RULES.md §8).
 */
class RewardLedger extends Model
{
    protected $table = 'reward_ledger';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reward_type' => RewardType::class,
            'status' => LedgerStatus::class,
            // Decimal strings, never floats — these are money.
            'sqft' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<CalculationRun, $this> */
    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class);
    }

    /**
     * The record this reward was calculated from.
     *
     * Not a polymorphic relation: source_type stores a stable domain string
     * ('registry_sale'), not a class name, so refactoring a namespace can never
     * orphan a financial record.
     */
    public function source(): ?Model
    {
        return match ($this->source_type) {
            'registry_sale' => RegistrySale::find($this->source_id),
            default => null,
        };
    }

    /** @param  Builder<RewardLedger>  $query */
    public function scopeOfType(Builder $query, RewardType $type): void
    {
        $query->where('reward_type', $type);
    }

    /** @param  Builder<RewardLedger>  $query */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }
}
