<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (seller -> recipient) route that made a member eligible.
 *
 * Several of these can point at the same recipient. That is the point: the
 * payout is single, the reasons are many, and the admin gets to see all of them.
 */
class CompanyClubEligibilityPath extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'path_snapshot' => 'array',
            'sale_member_sqft' => 'decimal:2',
            'upline_level' => 'integer',
            'chain_depth' => 'integer',
        ];
    }

    /** @return BelongsTo<CompanyClubCalculationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CompanyClubCalculationRun::class, 'company_club_run_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function saleMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'sale_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function eligibleMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'eligible_member_id');
    }

    /**
     * Whether an inactive sponsor was skipped on the way to this recipient.
     *
     * Depth counts database hops; level counts ACTIVE members. When they differ,
     * somebody in between was skipped.
     */
    public function skippedInactive(): bool
    {
        return $this->chain_depth > $this->upline_level;
    }
}
