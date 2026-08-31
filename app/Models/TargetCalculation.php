<?php

namespace App\Models;

use App\Enums\TargetLevel;
use App\Enums\TargetOutcome;
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
            'cumulative_sqft' => 'decimal:2',
            'own_sqft' => 'decimal:2',
            'shortfall_sqft' => 'decimal:2',
            'reward_amount' => 'decimal:2',
            'achieved' => 'boolean',
            'target_level' => TargetLevel::class,
            'window_months' => 'integer',
        ];
    }

    /**
     * Achieved, missed, or still inside an open window.
     *
     * NOT stored. `achieved` remains the single source of the binary verdict and
     * the once-ever guard hangs off it; the third state is the difference
     * between a window that has closed short and one that still has months left.
     * Storing it as well would be two columns able to disagree about the same
     * fact.
     */
    public function outcome(): TargetOutcome
    {
        if ($this->achieved) {
            return TargetOutcome::Achieved;
        }

        return $this->period < $this->window_end
            ? TargetOutcome::InProgress
            : TargetOutcome::Missed;
    }

    public function isInProgress(): bool
    {
        return $this->outcome() === TargetOutcome::InProgress;
    }

    /** How many months of the window are still to come, including none. */
    public function monthsRemaining(): int
    {
        if ($this->achieved || $this->period >= $this->window_end) {
            return 0;
        }

        [$y1, $m1] = array_map('intval', explode('-', $this->period));
        [$y2, $m2] = array_map('intval', explode('-', $this->window_end));

        return ($y2 * 12 + $m2) - ($y1 * 12 + $m1);
    }

    /** "2026-07 – 2026-08", or just the month on a one-month target. */
    public function windowLabel(): string
    {
        return $this->window_start === $this->window_end
            ? $this->window_start
            : $this->window_start.' – '.$this->window_end;
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

    /**
     * Not achieved — whether the window closed short or is still open.
     *
     * The "Not Reached" page wants both: a member two weeks into a two-month
     * window has not reached the target either, and hiding them until the window
     * closes would make the page silently incomplete.
     *
     * @param  Builder<TargetCalculation>  $query
     */
    public function scopeMissed(Builder $query): void
    {
        $query->where('achieved', false);
    }

    /**
     * Windows that closed short. Excludes attempts still running.
     *
     * @param  Builder<TargetCalculation>  $query
     */
    public function scopeClosedShort(Builder $query): void
    {
        $query->where('achieved', false)->whereColumn('period', '>=', 'window_end');
    }

    /** @param  Builder<TargetCalculation>  $query */
    public function scopeInProgress(Builder $query): void
    {
        $query->where('achieved', false)->whereColumn('period', '<', 'window_end');
    }

    /** @param  Builder<TargetCalculation>  $query */
    public function scopeAtLevel(Builder $query, TargetLevel|int $level): void
    {
        $query->where('target_level', $level instanceof TargetLevel ? $level->value : $level);
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
     * Measured on the WINDOW-TO-DATE total, because that is what the threshold
     * is tested against. On a one-month target it is the same figure as the
     * month's own.
     *
     * Presentation only — the verdict is the `achieved` column, never this.
     */
    public function progressPercent(): float
    {
        if (bccomp($this->target_sqft, '0', 2) === 0) {
            return 0.0;
        }

        $percent = (float) bcmul(bcdiv($this->cumulative_sqft, $this->target_sqft, 6), '100', 2);

        return min($percent, 100.0);
    }

    /**
     * Sq.Ft. above the threshold. Recorded for visibility only — it is discarded
     * and never carries into the next target (docs/02_BUSINESS_RULES.md §3.1).
     */
    public function surplusSqft(): string
    {
        $surplus = bcsub($this->cumulative_sqft, $this->target_sqft, 2);

        return bccomp($surplus, '0', 2) > 0 ? $surplus : '0.00';
    }
}
