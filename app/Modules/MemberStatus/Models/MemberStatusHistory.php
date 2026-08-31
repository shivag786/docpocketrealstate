<?php

namespace App\Modules\MemberStatus\Models;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded status transition (table `member_status_history`).
 *
 * Append-only: rows are written when a status changes and never updated, which
 * is why the table carries `created_at` and no `updated_at`.
 *
 * @property int $id
 * @property int $member_id
 * @property CalculatedStatus|null $old_status
 * @property CalculatedStatus $new_status
 * @property string $reason
 */
class MemberStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'member_status_history';

    protected $fillable = [
        'member_id', 'old_status', 'new_status', 'reason', 'effective_at', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_status' => CalculatedStatus::class,
            'new_status' => CalculatedStatus::class,
            'effective_at' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** @param  Builder<MemberStatusHistory>  $query */
    public function scopeForMember(Builder $query, int|string $memberId): void
    {
        $query->where('member_id', $memberId);
    }
}
