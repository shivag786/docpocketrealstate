<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's verdict against one target, for one period.
 *
 * Rows are written only for members actually MEASURED in the period. A member
 * who has already achieved the target is no longer measured against it, so the
 * absence of a row is meaningful — see TargetRewardService.
 */
class TargetCalculation extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_sqft' => 'decimal:2',
            'rate' => 'decimal:2',
            'achieved_sqft' => 'decimal:2',
            'own_sqft' => 'decimal:2',
            'shortfall_sqft' => 'decimal:2',
            'reward_amount' => 'decimal:2',
            'achieved' => 'boolean',
            'target_level' => 'integer',
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

    /** @param  Builder<TargetCalculation>  $query */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }

    /** @param  Builder<TargetCalculation>  $query */
    public function scopeAchieved(Builder $query): void
    {
        $query->where('achieved', true);
    }

    /** @param  Builder<TargetCalculation>  $query */
    public function scopeMissed(Builder $query): void
    {
        $query->where('achieved', false);
    }

    /** @param  Builder<TargetCalculation>  $query */
    public function scopeAtLevel(Builder $query, int $level): void
    {
        $query->where('target_level', $level);
    }

    /**
     * Sales made by the member's downline rather than by the member themselves.
     */
    public function downlineSqft(): string
    {
        return bcsub($this->achieved_sqft, $this->own_sqft, 2);
    }

    /**
     * How far through the target they got, capped at 100.
     *
     * Presentation only — the verdict is the `achieved` column, never this.
     */
    public function progressPercent(): float
    {
        if (bccomp($this->target_sqft, '0', 2) === 0) {
            return 0.0;
        }

        $percent = (float) bcmul(bcdiv($this->achieved_sqft, $this->target_sqft, 6), '100', 2);

        return min($percent, 100.0);
    }

    /**
     * Sq.Ft. sold above the threshold. Recorded for visibility only — it is
     * discarded and never carries into the next target
     * (docs/02_BUSINESS_RULES.md §3.1).
     */
    public function surplusSqft(): string
    {
        $surplus = bcsub($this->achieved_sqft, $this->target_sqft, 2);

        return bccomp($surplus, '0', 2) > 0 ? $surplus : '0.00';
    }
}
