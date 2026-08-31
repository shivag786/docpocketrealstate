<?php

namespace App\Models;

use App\Enums\LedgerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One member's Company Club payout from one run.
 *
 * One row per unique recipient, however many sale paths qualified them.
 */
class CompanyClubReward extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => LedgerStatus::class,
        ];
    }

    /** @return BelongsTo<CompanyClubCalculationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CompanyClubCalculationRun::class, 'company_club_run_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The reasons this member qualified.
     *
     * @return HasMany<CompanyClubEligibilityPath, $this>
     */
    public function paths(): HasMany
    {
        return $this->hasMany(CompanyClubEligibilityPath::class, 'company_club_run_id', 'company_club_run_id')
            ->where('eligible_member_id', $this->member_id);
    }
}
