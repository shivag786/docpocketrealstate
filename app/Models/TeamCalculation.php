<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leader's team sales for one period.
 *
 * Phase 7 populates the sales columns only. The target columns stay null until
 * the Target engine fills them in Phases 8-10.
 */
class TeamCalculation extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'own_sqft' => 'decimal:2',
            'direct_team_sqft' => 'decimal:2',
            'total_team_sqft' => 'decimal:2',
            'target_sqft' => 'decimal:2',
            'reward_amount' => 'decimal:2',
            'achieved' => 'boolean',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'leader_id');
    }

    /** @return BelongsTo<CalculationRun, $this> */
    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class);
    }

    /** @param  Builder<TeamCalculation>  $query */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }

    /**
     * Sales made by the leader's downline rather than the leader themselves.
     */
    public function downlineSqft(): string
    {
        return bcsub($this->total_team_sqft, $this->own_sqft, 2);
    }

    /**
     * A leader whose team produced nothing beyond their own sales.
     */
    public function isSoloContributor(): bool
    {
        return bccomp($this->total_team_sqft, $this->own_sqft, 2) === 0;
    }
}
