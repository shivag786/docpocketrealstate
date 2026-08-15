<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The working behind one upline share.
 *
 * Explains a reward_ledger row of type `upline`: whose sales made the pool, how
 * big it was, how many uplines qualified, and where this receiver sat.
 */
class UplineCalculation extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seller_sqft' => 'decimal:2',
            'pool_rate' => 'decimal:2',
            'pool_amount' => 'decimal:2',
            'receiver_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'seller_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'receiver_id');
    }

    /** @return BelongsTo<CalculationRun, $this> */
    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class);
    }

    /** @param  Builder<UplineCalculation>  $query */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }

    /**
     * True when inactive members were skipped to reach this receiver.
     */
    public function wasCompressed(): bool
    {
        return $this->chain_depth > $this->upline_level;
    }
}
