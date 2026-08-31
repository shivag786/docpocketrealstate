<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\MemberStatus;
use App\Enums\RewardType;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures for the dashboard.
 *
 * Every number here is READ FROM THE DATABASE. Nothing is estimated, projected or
 * placeholder — docs/07_CLAUDE_WORKFLOW_PROMPT.md §8 forbids inventing figures on
 * a financial dashboard, and the tiles previously showed a dash rather than guess.
 * They no longer need to: the engines that produce these values all exist.
 *
 * Reward totals come from `reward_ledger`, which is the paid-or-payable record.
 * Sales figures come from `registry_sales`, which is the fact the rewards derive
 * from. The two agree because every sale entry recalculates its month.
 */
class DashboardMetricsService
{
    /** How many months the trend chart looks back, including this one. */
    private const TREND_MONTHS = 6;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'members' => $this->members(),
            'sales' => $this->sales(),
            'rewards' => $this->rewards(),
            'targets' => $this->targets(),
            'trend' => $this->monthlyTrend(),
            'recentSales' => $this->recentSales(),
            'topSellers' => $this->topSellers(),
        ];
    }

    /**
     * @return array{total: int, active: int, inactive: int, leaders: int, joined_this_month: int}
     */
    public function members(): array
    {
        $byStatus = Member::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $active = (int) ($byStatus[MemberStatus::Active->value] ?? 0);
        $inactive = (int) ($byStatus[MemberStatus::Inactive->value] ?? 0);

        return [
            'total' => $active + $inactive,
            'active' => $active,
            'inactive' => $inactive,
            // A Team Leader is a member with at least one referral
            // (docs/02_BUSINESS_RULES.md §4).
            'leaders' => Member::query()->whereHas('directReferrals')->count(),
            'joined_this_month' => Member::query()
                ->whereYear('joining_date', now()->year)
                ->whereMonth('joining_date', now()->month)
                ->count(),
        ];
    }

    /**
     * @return array{
     *     today_count: int, today_sqft: string,
     *     month_count: int, month_sqft: string,
     *     total_count: int, total_sqft: string
     * }
     */
    public function sales(): array
    {
        $today = $this->salesAggregate(fn ($q) => $q->whereDate('registry_date', now()->toDateString()));
        $month = $this->salesAggregate(fn ($q) => $q->forPeriod(now()->format('Y-m')));
        $all = $this->salesAggregate(fn ($q) => $q);

        return [
            'today_count' => $today['count'],
            'today_sqft' => $today['sqft'],
            'month_count' => $month['count'],
            'month_sqft' => $month['sqft'],
            'total_count' => $all['count'],
            'total_sqft' => $all['sqft'],
        ];
    }

    /**
     * Reward totals by engine, for this month and overall.
     *
     * The four engines are never summed together into a single "rewards" figure
     * without saying so — docs/02_BUSINESS_RULES.md §8 keeps them separate. The
     * combined total is labelled as such in the view.
     *
     * @return array<string, array{month: string, total: string, paid: string, unpaid: string, entries: int}>
     */
    public function rewards(): array
    {
        $period = now()->format('Y-m');
        $rewards = [];

        // Company Club has its own overview and is deliberately not a
        // dashboard engine card. A hidden engine is skipped entirely: its money
        // is still being calculated, but the dashboard must not report a figure
        // for a reward the operator has no screen for.
        $engines = array_filter(
            [RewardType::Direct, RewardType::Upline, RewardType::Target],
            fn (RewardType $type) => $type->isVisible(),
        );

        foreach ($engines as $type) {
            $totals = RewardLedger::query()
                ->ofType($type)
                ->selectRaw('COUNT(*) as entries')
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as paid', [LedgerStatus::Paid->value])
                ->selectRaw('COALESCE(SUM(CASE WHEN period = ? THEN amount ELSE 0 END), 0) as month', [$period])
                ->first();

            $rewards[$type->value] = [
                'month' => Money::of($totals->month),
                'total' => Money::of($totals->total),
                'paid' => Money::of($totals->paid),
                'unpaid' => Money::subtract(Money::of($totals->total), Money::of($totals->paid)),
                'entries' => (int) $totals->entries,
            ];
        }

        return $rewards;
    }

    /**
     * @return array{achievers: int, amount: string, measured: int, pending: int}
     */
    public function targets(): array
    {
        $achievers = TargetCalculation::query()->where('achieved', true)->count();

        return [
            'achievers' => $achievers,
            'amount' => Money::of(
                TargetCalculation::query()->where('achieved', true)->sum('reward_amount')
            ),
            'measured' => TargetCalculation::query()->count(),
            // Members who reached the target but have not been paid yet.
            'pending' => RewardLedger::query()
                ->ofType(RewardType::Target)
                ->unpaid()
                ->count(),
        ];
    }

    /**
     * Sq.Ft. sold per month for the trend chart, oldest first.
     *
     * Months with no sales are included as zero rather than skipped — a gap in a
     * time series must read as "nothing happened", not as a missing month.
     *
     * @return Collection<int, array{period: string, label: string, sqft: string, amount: string}>
     */
    public function monthlyTrend(): Collection
    {
        $start = now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        $sold = RegistrySale::query()
            ->approved()
            ->where('registry_date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(registry_date, '%Y-%m') as period")
            ->selectRaw('COALESCE(SUM(sqft), 0) as sqft')
            ->groupBy('period')
            ->pluck('sqft', 'period');

        return collect(range(0, self::TREND_MONTHS - 1))
            ->map(function (int $offset) use ($start, $sold) {
                $month = $start->copy()->addMonths($offset);
                $period = $month->format('Y-m');
                $sqft = Money::of($sold[$period] ?? 0);

                return [
                    'period' => $period,
                    'label' => $month->format('M'),
                    'sqft' => $sqft,
                    // What that Sq.Ft. earned in direct reward — the one rate
                    // that applies to every sale without qualification.
                    'amount' => Money::multiply($sqft, RewardType::Direct->rate()),
                ];
            });
    }

    /**
     * @return Collection<int, RegistrySale>
     */
    public function recentSales(int $limit = 6): Collection
    {
        return RegistrySale::query()
            ->approved()
            ->with('member:id,member_code,name')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Members who sold the most this month.
     *
     * @return Collection<int, object>
     */
    public function topSellers(int $limit = 5): Collection
    {
        return RegistrySale::query()
            ->approved()
            ->forPeriod(now()->format('Y-m'))
            ->join('members', 'members.id', '=', 'registry_sales.member_id')
            ->selectRaw('members.id, members.member_code, members.name')
            ->selectRaw('COUNT(registry_sales.id) as sales')
            ->selectRaw('COALESCE(SUM(registry_sales.sqft), 0) as sqft')
            ->groupBy('members.id', 'members.member_code', 'members.name')
            ->orderByDesc('sqft')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  callable(Builder<RegistrySale>): mixed  $scope
     * @return array{count: int, sqft: string}
     */
    private function salesAggregate(callable $scope): array
    {
        $query = RegistrySale::query()->approved();
        $scope($query);

        $row = $query
            ->selectRaw('COUNT(*) as sale_count, COALESCE(SUM(sqft), 0) as total_sqft')
            ->first();

        return [
            'count' => (int) $row->sale_count,
            'sqft' => Money::of($row->total_sqft),
        ];
    }
}
