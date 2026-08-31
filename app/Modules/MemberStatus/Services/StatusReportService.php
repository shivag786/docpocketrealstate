<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Contracts\PropertySaleProvider;
use App\Modules\MemberStatus\Contracts\RewardGateway;
use App\Modules\MemberStatus\Enums\ActivityType;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusSnapshot;
use App\Modules\MemberStatus\Repositories\StatusSnapshotRepository;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the optional status report (spec §27).
 *
 * Reads the module's snapshot table and joins the member's name and code for
 * display only. It writes nothing, and it deliberately does not recalculate:
 * the report shows what the last run decided, so that what an admin sees is
 * exactly what is stored and auditable.
 */
class StatusReportService
{
    public function __construct(
        private readonly StatusConfig $config,
        private readonly PropertySaleProvider $sales,
        private readonly StatusSnapshotRepository $snapshots,
        private readonly RewardGateway $rewards,
        private readonly PaymentEligibilityService $eligibility,
    ) {}

    /**
     * One page of the report, newest inactivity first.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function page(?CalculatedStatus $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        $schema = $this->config->schema();

        $query = DB::table('member_status_snapshot as snap')
            ->leftJoin(
                $schema->membersTable.' as m',
                'm.'.$schema->memberId,
                '=',
                'snap.member_id'
            )
            ->select([
                'snap.member_id',
                'snap.status',
                'snap.last_activity_at',
                'snap.last_activity_type',
                'snap.days_since_activity',
                'snap.status_changed_at',
                'snap.calculated_at',
                'snap.reference_date',
                'm.'.$schema->memberName.' as member_name',
                'm.'.$schema->memberCode.' as member_code',
                'm.'.$schema->memberJoinedAt.' as joined_at',
            ]);

        if ($schema->memberDeletedAt !== null) {
            $query->whereNull('m.'.$schema->memberDeletedAt);
        }

        if ($status !== null) {
            $query->where('snap.status', $status->value);
        }

        $this->applySearch($query, $search, $schema->memberName, $schema->memberCode);

        // Longest inactivity first: the members an admin needs to look at are
        // the ones closest to dropping a status.
        $paginator = $query
            ->orderByDesc('snap.days_since_activity')
            ->orderBy('snap.member_id')
            ->paginate($perPage);

        $this->attachActivityBreakdown($paginator);
        $this->attachPaymentState($paginator);

        return $paginator;
    }

    /**
     * Counts per status across the whole snapshot table, for the filter pills.
     *
     * @return array<string, int>
     */
    public function totals(): array
    {
        return $this->snapshots->statusTotals();
    }

    public function lastCalculatedAt(): ?CarbonImmutable
    {
        $date = MemberStatusSnapshot::query()->max('calculated_at');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }

    private function applySearch(Builder $query, ?string $search, string $nameColumn, string $codeColumn): void
    {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term, $nameColumn, $codeColumn) {
            $q->where('m.'.$codeColumn, 'like', "%{$term}%")
                ->orWhere('m.'.$nameColumn, 'like', "%{$term}%");
        });
    }

    /**
     * Fill in the "Own Sale Activity" and "Direct Referral Activity" columns
     * for the rows on this page — two queries for the page, not two per row.
     *
     * @param  LengthAwarePaginator<int, object>  $paginator
     */
    private function attachActivityBreakdown(LengthAwarePaginator $paginator): void
    {
        $rows = $paginator->items();

        if ($rows === []) {
            return;
        }

        $memberIds = array_map(fn (object $row) => (int) $row->member_id, $rows);
        $byType = $this->sales->getLatestActivityByTypeForMany($memberIds);

        foreach ($rows as $row) {
            $activity = $byType[(int) $row->member_id] ?? [];

            $row->own_sale_at = ($activity[ActivityType::OwnSale->value] ?? null)?->activityDate;
            $row->referral_sale_at = ($activity[ActivityType::DirectReferralSale->value] ?? null)?->activityDate;
            $row->referral_source_id = ($activity[ActivityType::DirectReferralSale->value] ?? null)?->sourceMemberId;
        }
    }

    /**
     * Decide, for every row on the page, whether its Mark Paid button may be
     * pressed and how much is waiting — one query for the page, not one per row.
     *
     * The button's disabled state is derived from the SAME service the payment
     * endpoint consults, so the two can never drift apart (client rule,
     * 2026-08-25).
     *
     * @param  LengthAwarePaginator<int, object>  $paginator
     */
    private function attachPaymentState(LengthAwarePaginator $paginator): void
    {
        $rows = $paginator->items();

        if ($rows === []) {
            return;
        }

        $memberIds = array_map(fn (object $row) => (int) $row->member_id, $rows);
        $unpaid = $this->rewards->unpaidSummaryForMany($memberIds);

        foreach ($rows as $row) {
            $status = CalculatedStatus::from($row->status);

            $row->payable = ! $this->eligibility->blocks($status);
            $row->unpaid_count = $unpaid[(int) $row->member_id]['count'] ?? 0;
            $row->unpaid_amount = $unpaid[(int) $row->member_id]['amount'] ?? '0.00';
        }
    }
}
