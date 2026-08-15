<?php

namespace App\Models;

use App\Enums\MemberStatus;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A member of the sales network.
 *
 * Members are records, not users — they never authenticate. All data entry is
 * performed by Admin/Manager staff (docs/02_BUSINESS_RULES.md §7).
 */
#[Fillable(['name', 'mobile', 'email', 'address', 'sponsor_id', 'joining_date', 'status'])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'status' => MemberStatus::class,
        ];
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /** @return BelongsTo<Member, $this> */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sponsor_id');
    }

    /**
     * Members personally referred by this member (level 1 only).
     *
     * @return HasMany<Member, $this>
     */
    public function directReferrals(): HasMany
    {
        return $this->hasMany(self::class, 'sponsor_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** @param  Builder<Member>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active);
    }

    /** @param  Builder<Member>  $query */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('sponsor_id');
    }

    /**
     * Free-text search across the fields staff actually search by.
     *
     * @param  Builder<Member>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            $q->where('member_code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    // -----------------------------------------------------------------
    // Domain helpers
    // -----------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }

    public function isRoot(): bool
    {
        return $this->sponsor_id === null;
    }

    /**
     * A member with at least one referred member is a Team Leader.
     *
     * docs/02_BUSINESS_RULES.md §4.
     */
    public function isTeamLeader(): bool
    {
        return $this->directReferrals()->exists();
    }

    /**
     * Whether this member's sponsor may still be reassigned.
     *
     * Client-confirmed: a sponsor may be changed only while the member has no
     * sales, because re-parenting rewrites the upline chain and would otherwise
     * change payouts that have already been calculated.
     *
     * PHASE 4/5 EXTENSION POINT — registry_sales and reward_ledger do not exist
     * yet. When they do, add the corresponding existence checks here and
     * nowhere else; every caller already routes through this method.
     */
    public function canChangeSponsor(): bool
    {
        return true;
    }

    /**
     * The sponsor chain above this member, nearest first.
     *
     * Walks upward without recursion so a corrupt cycle cannot hang the request;
     * `max_depth_guard` and the visited-set are safety valves, not business rules.
     * The 5-level upline limit is a separate concern (config/rewards.php).
     *
     * @return Collection<int, Member>
     */
    public function ancestors(?int $limit = null): Collection
    {
        $ancestors = new Collection;
        $visited = [$this->id => true];
        $guard = (int) config('members.sponsor.max_depth_guard', 100);

        // Deliberately walks sponsor_id and re-queries full models rather than
        // traversing the loaded `sponsor` relation. A caller that eager-loads
        // that relation with a partial column list (e.g. "sponsor:id,name")
        // would otherwise silently truncate the chain, because the loaded model
        // has no sponsor_id to continue from. Phases 6 and 7 calculate money
        // from this chain, so it must not depend on how a caller loaded things.
        $nextId = $this->sponsor_id;

        while ($nextId !== null && $guard-- > 0) {
            if (isset($visited[$nextId])) {
                break; // defensive: a cycle should be impossible
            }

            $current = static::query()->find($nextId);

            if ($current === null) {
                break;
            }

            $visited[$nextId] = true;
            $ancestors->push($current);

            if ($limit !== null && $ancestors->count() >= $limit) {
                break;
            }

            $nextId = $current->sponsor_id;
        }

        return $ancestors;
    }

    /**
     * IDs of every member beneath this one, at any depth.
     *
     * Phase 2 needs this only to protect the tree during edits and deletes.
     * Phase 3 replaces it with a lazy-loading tree service, and Phase 7 with a
     * set-based team rollup — neither should call this on large networks.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $collected = [];
        $frontier = [$this->id];
        $guard = (int) config('members.sponsor.max_depth_guard', 100);

        while ($frontier !== [] && $guard-- > 0) {
            $frontier = static::query()
                ->whereIn('sponsor_id', $frontier)
                ->whereNotIn('id', $collected)
                ->pluck('id')
                ->all();

            $collected = array_merge($collected, $frontier);
        }

        return $collected;
    }
}
