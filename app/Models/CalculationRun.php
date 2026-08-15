<?php

namespace App\Models;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of one calculation engine for one period.
 *
 * Every ledger row points at the run that created it, so any amount can be
 * traced back to when it was produced and by whom.
 */
class CalculationRun extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_type' => CalculationRunType::class,
            'status' => CalculationRunStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_sqft' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /** @return HasMany<RewardLedger, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(RewardLedger::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @param  Builder<CalculationRun>  $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', CalculationRunStatus::Completed);
    }

    /** @param  Builder<CalculationRun>  $query */
    public function scopeFor(Builder $query, string $period, CalculationRunType $type): void
    {
        $query->where('period', $period)->where('run_type', $type);
    }

    public function isCompleted(): bool
    {
        return $this->status === CalculationRunStatus::Completed;
    }
}
