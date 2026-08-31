<?php

namespace App\Models;

use App\Enums\CalculationRunStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Company Club calculation, as the history screen shows it.
 *
 * Rows are never deleted. A recalculation supersedes the previous run and clears
 * its detail rows, but the snapshot survives so the admin can always see what
 * the previous calculation said and when it was made.
 */
class CompanyClubCalculationRun extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CalculationRunStatus::class,
            'automatic' => 'boolean',
            'total_sqft' => 'decimal:2',
            'rate' => 'decimal:2',
            'pool_amount' => 'decimal:2',
            'equal_share' => 'decimal:2',
            'distributed_amount' => 'decimal:2',
            'residual_amount' => 'decimal:2',
        ];
    }

    /** @return HasMany<CompanyClubReward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(CompanyClubReward::class, 'company_club_run_id');
    }

    /** @return HasMany<CompanyClubEligibilityPath, $this> */
    public function paths(): HasMany
    {
        return $this->hasMany(CompanyClubEligibilityPath::class, 'company_club_run_id');
    }

    /** @return BelongsTo<CalculationRun, $this> */
    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @param  Builder<CompanyClubCalculationRun>  $query */
    public function scopeForPeriod(Builder $query, string $period): void
    {
        $query->where('period', $period);
    }

    /** @param  Builder<CompanyClubCalculationRun>  $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', CalculationRunStatus::Completed);
    }

    public function isCompleted(): bool
    {
        return $this->status === CalculationRunStatus::Completed;
    }

    /**
     * Whether the shares re-sum to the pool exactly.
     *
     * They usually do. When they do not, the difference is a rounding residual
     * of a few paise and is displayed rather than absorbed.
     */
    public function reconciles(): bool
    {
        return Money::isZero((string) $this->residual_amount);
    }
}
