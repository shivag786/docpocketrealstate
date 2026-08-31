<?php

namespace App\Modules\MemberStatus\Adapters;

use App\Modules\MemberStatus\Contracts\PropertySaleProvider;
use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Data\SaleRecord;
use App\Modules\MemberStatus\Enums\ActivityType;
use App\Modules\MemberStatus\Support\SchemaMap;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PropertySaleProvider backed by the host application's `registry_sales` table.
 *
 * READ ONLY, and the single place where "valid property sale" is defined for
 * the module (spec §11):
 *
 *      valid = the sale's status is in the configured qualifying list
 *              AND the row is not soft-deleted
 *              AND the selling member still exists
 *
 * The host application currently has exactly one sale state — entering a sale
 * is approving it — so today that list is ['approved']. If cancellation or
 * reversal is ever added to the application, this module needs no code change:
 * the new state simply stays out of `member_status.sales.qualifying_statuses`.
 *
 * Every bulk method resolves a whole chunk of members in a fixed number of
 * queries. Nothing in here may be called in a loop over members (spec §31).
 */
class EloquentPropertySaleProvider implements PropertySaleProvider
{
    public function __construct(
        private readonly StatusConfig $config,
    ) {}

    /**
     * @return list<SaleRecord>
     */
    public function getOwnSales(int|string $memberId, ?CarbonImmutable $since = null): array
    {
        $schema = $this->schema();

        $rows = $this->validSales('s')
            ->where('s.'.$schema->saleMember, $memberId)
            ->when($since, fn (Builder $q) => $q->whereDate('s.'.$schema->saleDate, '>=', $since->toDateString()))
            ->orderByDesc('s.'.$schema->saleDate)
            ->orderByDesc('s.'.$schema->saleId)
            ->select([
                's.'.$schema->saleId.' as sale_id',
                's.'.$schema->saleMember.' as member_id',
                's.'.$schema->saleDate.' as sold_at',
            ])
            ->get();

        return $rows->map(fn ($row) => $this->toSale($row))->all();
    }

    /**
     * Sales by the member's DIRECT referrals only.
     *
     * One join on `sponsor_id`. No recursion, no CTE, nothing that could reach
     * a second level (spec §3).
     *
     * @return list<SaleRecord>
     */
    public function getDirectReferralSales(int|string $memberId, ?CarbonImmutable $since = null): array
    {
        $schema = $this->schema();

        $rows = $this->validSales('s')
            ->join($schema->membersTable.' as m', 'm.'.$schema->memberId, '=', 's.'.$schema->saleMember)
            ->where('m.'.$schema->memberSponsor, $memberId)
            ->when(
                $schema->memberDeletedAt !== null,
                fn (Builder $q) => $q->whereNull('m.'.$schema->memberDeletedAt)
            )
            ->when($since, fn (Builder $q) => $q->whereDate('s.'.$schema->saleDate, '>=', $since->toDateString()))
            ->orderByDesc('s.'.$schema->saleDate)
            ->orderByDesc('s.'.$schema->saleId)
            ->select([
                's.'.$schema->saleId.' as sale_id',
                's.'.$schema->saleMember.' as member_id',
                's.'.$schema->saleDate.' as sold_at',
            ])
            ->get();

        return $rows->map(fn ($row) => $this->toSale($row))->all();
    }

    public function getLastQualifyingActivity(
        int|string $memberId,
        ?CarbonImmutable $asOf = null,
    ): ?QualifyingActivity {
        return $this->getLastQualifyingActivityForMany([$memberId], $asOf)[$memberId] ?? null;
    }

    /**
     * @param  list<int|string>  $memberIds
     * @return array<int|string, QualifyingActivity>
     */
    public function getLastQualifyingActivityForMany(array $memberIds, ?CarbonImmutable $asOf = null): array
    {
        $latest = [];

        foreach ($this->getLatestActivityByTypeForMany($memberIds, $asOf) as $memberId => $byType) {
            $own = $byType[ActivityType::OwnSale->value] ?? null;
            $referral = $byType[ActivityType::DirectReferralSale->value] ?? null;

            // The newer of the two wins. On the same date the member's own sale
            // is preferred, because "you sold" is the more direct explanation
            // of why they are active.
            $activity = match (true) {
                $own === null => $referral,
                $referral === null => $own,
                $referral->activityDate->gt($own->activityDate) => $referral,
                default => $own,
            };

            if ($activity !== null) {
                $latest[$memberId] = $activity;
            }
        }

        return $latest;
    }

    /**
     * @param  list<int|string>  $memberIds
     * @return array<int|string, array<string, QualifyingActivity>>
     */
    public function getLatestActivityByTypeForMany(array $memberIds, ?CarbonImmutable $asOf = null): array
    {
        $memberIds = array_values(array_unique($memberIds));

        if ($memberIds === []) {
            return [];
        }

        $result = [];

        foreach ($this->latestOwnSales($memberIds, $asOf) as $memberId => $sale) {
            $result[$memberId][ActivityType::OwnSale->value] = QualifyingActivity::ownSale($memberId, $sale);
        }

        foreach ($this->latestReferralSales($memberIds, $asOf) as $memberId => $sale) {
            $result[$memberId][ActivityType::DirectReferralSale->value]
                = QualifyingActivity::directReferralSale($memberId, $sale);
        }

        return $result;
    }

    public function findValidSale(int|string $saleId): ?SaleRecord
    {
        $schema = $this->schema();

        $row = $this->validSales('s')
            ->where('s.'.$schema->saleId, $saleId)
            ->select([
                's.'.$schema->saleId.' as sale_id',
                's.'.$schema->saleMember.' as member_id',
                's.'.$schema->saleDate.' as sold_at',
            ])
            ->first();

        return $row === null ? null : $this->toSale($row);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Each member's own newest valid sale, keyed by member id.
     *
     * Two-part query, one round trip: a grouped sub-select finds the newest
     * qualifying date per member, and the outer query joins back to it to
     * recover the sale itself. Loading every sale for the chunk and reducing
     * the set in memory would read a whole month of the business to answer a
     * question about one date.
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, SaleRecord>
     */
    private function latestOwnSales(array $memberIds, ?CarbonImmutable $asOf): array
    {
        $schema = $this->schema();

        $newestDate = $this->validSales('s2')
            ->whereIn('s2.'.$schema->saleMember, $memberIds)
            ->when($asOf, fn (Builder $q) => $q->whereDate('s2.'.$schema->saleDate, '<=', $asOf->toDateString()))
            ->groupBy('s2.'.$schema->saleMember)
            ->select([
                's2.'.$schema->saleMember.' as owner_id',
                DB::raw('MAX(s2.'.$schema->saleDate.') as newest_date'),
            ]);

        $rows = $this->validSales('s')
            ->joinSub($newestDate, 'newest', function (JoinClause $join) use ($schema) {
                $join->on('s.'.$schema->saleMember, '=', 'newest.owner_id')
                    ->on('s.'.$schema->saleDate, '=', 'newest.newest_date');
            })
            ->select([
                's.'.$schema->saleId.' as sale_id',
                's.'.$schema->saleMember.' as member_id',
                's.'.$schema->saleDate.' as sold_at',
                DB::raw('newest.owner_id as owner_id'),
            ])
            ->get();

        return $this->pickOnePerOwner($rows);
    }

    /**
     * The newest valid sale made by any DIRECT referral, keyed by SPONSOR id.
     *
     * The grouping column is the sponsor, so a sponsor with four referrals gets
     * one row: the newest sale among those four. A referral's referral cannot
     * appear, because `sponsor_id` is compared to the sponsor exactly once and
     * nothing here walks upward or downward from there.
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, SaleRecord>
     */
    private function latestReferralSales(array $memberIds, ?CarbonImmutable $asOf): array
    {
        $schema = $this->schema();

        $newestDate = $this->validSales('s2')
            ->join($schema->membersTable.' as m2', 'm2.'.$schema->memberId, '=', 's2.'.$schema->saleMember)
            ->whereIn('m2.'.$schema->memberSponsor, $memberIds)
            ->when(
                $schema->memberDeletedAt !== null,
                fn (Builder $q) => $q->whereNull('m2.'.$schema->memberDeletedAt)
            )
            ->when($asOf, fn (Builder $q) => $q->whereDate('s2.'.$schema->saleDate, '<=', $asOf->toDateString()))
            ->groupBy('m2.'.$schema->memberSponsor)
            ->select([
                'm2.'.$schema->memberSponsor.' as owner_id',
                DB::raw('MAX(s2.'.$schema->saleDate.') as newest_date'),
            ]);

        $rows = $this->validSales('s')
            ->join($schema->membersTable.' as m', 'm.'.$schema->memberId, '=', 's.'.$schema->saleMember)
            ->when(
                $schema->memberDeletedAt !== null,
                fn (Builder $q) => $q->whereNull('m.'.$schema->memberDeletedAt)
            )
            ->joinSub($newestDate, 'newest', function (JoinClause $join) use ($schema) {
                $join->on('m.'.$schema->memberSponsor, '=', 'newest.owner_id')
                    ->on('s.'.$schema->saleDate, '=', 'newest.newest_date');
            })
            ->select([
                's.'.$schema->saleId.' as sale_id',
                's.'.$schema->saleMember.' as member_id',
                's.'.$schema->saleDate.' as sold_at',
                DB::raw('newest.owner_id as owner_id'),
            ])
            ->get();

        return $this->pickOnePerOwner($rows);
    }

    /**
     * Collapse ties on the same date to a single sale — the highest id, which
     * is the one entered last. Only rows already sharing the newest date reach
     * this, so the set is tiny.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int|string, SaleRecord>
     */
    private function pickOnePerOwner(Collection $rows): array
    {
        $chosen = [];

        foreach ($rows as $row) {
            $ownerId = (int) $row->owner_id;
            $sale = $this->toSale($row);

            if (! isset($chosen[$ownerId]) || $sale->id > $chosen[$ownerId]->id) {
                $chosen[$ownerId] = $sale;
            }
        }

        return $chosen;
    }

    /**
     * The validity filter — the definition of a sale that counts (spec §11).
     */
    private function validSales(string $alias): Builder
    {
        $schema = $this->schema();

        $query = DB::table($schema->salesTable.' as '.$alias)
            ->whereIn($alias.'.'.$schema->saleStatus, $this->config->qualifyingSaleStatuses);

        if ($schema->saleDeletedAt !== null) {
            $query->whereNull($alias.'.'.$schema->saleDeletedAt);
        }

        return $query;
    }

    private function schema(): SchemaMap
    {
        return $this->config->schema();
    }

    private function toSale(object $row): SaleRecord
    {
        return new SaleRecord(
            id: (int) $row->sale_id,
            memberId: (int) $row->member_id,
            soldAt: CarbonImmutable::parse($row->sold_at)->startOfDay(),
        );
    }
}
