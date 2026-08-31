<?php

namespace App\Modules\MemberStatus\Models;

use App\Modules\MemberStatus\Enums\ActivityType;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The module's calculated status for one member (table `member_status_snapshot`).
 *
 * This is NOT `members.status`. Nothing in the host application reads it, and
 * this module never writes `members.status` (spec §21).
 *
 * @property int $id
 * @property int $member_id
 * @property CalculatedStatus $status
 * @property Carbon|null $last_activity_at
 * @property ActivityType|null $last_activity_type
 * @property int $days_since_activity
 */
class MemberStatusSnapshot extends Model
{
    protected $table = 'member_status_snapshot';

    protected $fillable = [
        'member_id', 'status', 'last_activity_at', 'last_activity_type',
        'last_activity_source_member_id', 'last_activity_sale_id',
        'reference_date', 'days_since_activity', 'status_changed_at', 'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CalculatedStatus::class,
            'last_activity_type' => ActivityType::class,
            'last_activity_at' => 'date',
            'reference_date' => 'date',
            'status_changed_at' => 'date',
            'calculated_at' => 'date',
            'days_since_activity' => 'integer',
        ];
    }

    /** @param  Builder<MemberStatusSnapshot>  $query */
    public function scopeWithStatus(Builder $query, ?CalculatedStatus $status): void
    {
        if ($status !== null) {
            $query->where($query->qualifyColumn('status'), $status);
        }
    }
}
