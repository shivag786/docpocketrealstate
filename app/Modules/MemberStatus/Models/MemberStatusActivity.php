<?php

namespace App\Modules\MemberStatus\Models;

use App\Modules\MemberStatus\Enums\ActivityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A recorded qualifying event (table `member_status_activity`).
 *
 * Module-owned. It has no relationship to App\Models\Member or
 * App\Models\RegistrySale on purpose: those ids arrive through the provider
 * interfaces and the module must not assume which tables they came from.
 *
 * @property int $id
 * @property int $member_id
 * @property ActivityType $activity_type
 * @property int $source_member_id
 * @property int|null $sale_id
 * @property Carbon $activity_date
 */
class MemberStatusActivity extends Model
{
    protected $table = 'member_status_activity';

    protected $fillable = [
        'member_id', 'activity_type', 'source_member_id', 'sale_id', 'activity_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'activity_type' => ActivityType::class,
        ];
    }

    /** @param  Builder<MemberStatusActivity>  $query */
    public function scopeForMember(Builder $query, int|string $memberId): void
    {
        $query->where('member_id', $memberId);
    }

    /** @param  Builder<MemberStatusActivity>  $query */
    public function scopeOfType(Builder $query, ActivityType $type): void
    {
        $query->where('activity_type', $type);
    }
}
