<?php

namespace App\Services;

use App\Enums\CalculationRunStatus;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The reward ledger, and whether it can be trusted.
 *
 * Phase 13's exit condition is "every amount is explainable". Two jobs follow
 * from that and both live here:
 *
 *  1. `source()` turns a stored (source_type, source_id) pair back into the
 *     record it came from, in words and with a link. Four engines write to one
 *     table and each means something different by "source", so the translation
 *     belongs in one place or every screen invents its own. It resolves a
 *     HIDDEN engine's rows too — an amount stays explainable even once its
 *     screens are switched off — but offers no link to a page that would 404.
 *  2. `reconcile()` re-checks a month's ledger against everything it is
 *     supposed to agree with — its runs, its sources, its own arithmetic — and
 *     reports what it finds.
 *
 * NOTHING HERE WRITES. Reconciliation that could repair what it measures would
 * be able to hide a fault by fixing it, and the operator would never learn that
 * the month had been wrong.
 *
 * A CHECK THAT CRIES WOLF IS WORSE THAN NO CHECK. The Calculation Center
 * learned this on live data (see PROJECT_STATE, "TARGET IS DELIBERATELY NOT
 * COMPARED"): a comparison that is wrong about a healthy month trains an
 * operator to ignore it. So every check below is stated per reward type, in the
 * terms that type's engine actually promises — never one rule imposed on four
 * engines that do different arithmetic.
 */
class RewardLedgerService
{
    /**
     * Rounding slack allowed per reward row when shares are summed back to
     * their pool.
     *
     * Upline and Company Club round each share independently (the ₹50,000 / 3
     * case in `Money`), so N shares can sit up to half a paisa each away from
     * the exact pool. One paisa per row is that bound with room to spare, and
     * anything wider is a real difference rather than rounding.
     */
    private const ROUNDING_SLACK_PER_ROW = '0.01';

    /**
     * Where one ledger row came from, in words.
     *
     * `resolved` is false when the record the row points at no longer exists.
     * That is a finding, not an error to throw: the amount was still paid and
     * the operator needs to see the row in order to act on it.
     *
     * @return array{label: string, detail: string, url: ?string, resolved: bool}
     */
    public function source(RewardLedger $row): array
    {
        return match ($row->source_type) {
            'registry_sale' => $this->registrySaleSource($row),
            'member_period' => $this->sellerSource($row),
            'target_calculation' => $this->targetSource($row),
            'company_club_pool' => $this->clubPoolSource($row),
            default => [
                'label' => $row->source_type.' #'.$row->source_id,
                'detail' => 'This source type is not known to the ledger.',
                'url' => null,
                'resolved' => false,
            ],
        };
    }

    /**
     * How this reward type turns its stored inputs into its amount.
     *
     * Printed beside every row and every entry, because `sqft × rate = amount`
     * is true for two of the four engines and quietly false for the other two —
     * an operator checking the multiplication on an upline row would otherwise
     * conclude the system had underpaid.
     */
    public function arithmetic(RewardType $type): string
    {
        return match ($type) {
            RewardType::Direct => 'Sq.Ft. × rate = amount, on every row.',
            RewardType::Target => 'Threshold Sq.Ft. × rate = amount. The Sq.Ft. is the threshold '
                .'the prize was paid on, not what the member sold.',
            RewardType::Upline => 'Sq.Ft. is the SELLER\'s month. Sq.Ft. × rate is the pool, and the '
                .'amount is one equal share of it — so the row does not multiply out.',
            RewardType::CompanyClub => 'Sq.Ft. is the WHOLE month\'s eligible total. Sq.Ft. × rate is '
                .'the single monthly pool, and the amount is one equal share of it.',
        };
    }

    /** Whether `sqft × rate = amount` is expected to hold row by row. */
    public function multipliesOut(RewardType $type): bool
    {
        return in_array($type, [RewardType::Direct, RewardType::Target], true);
    }

    /**
     * Reconcile one period.
     *
     * RECONCILIATION COVERS HIDDEN ENGINES TOO, deliberately. Everywhere else
     * an engine hidden by `rewards.visibility` disappears completely; here it
     * does not, because it is still calculating and still writing money, and a
     * reward that nothing checks is how money goes wrong quietly. Each summary
     * carries `hidden` so the screen can say which engines the operator will
     * not find anywhere else.
     *
     * @return array{
     *     period: string,
     *     types: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     checks: list<array<string, mixed>>,
     *     passed: int, failed: int, clean: bool, empty: bool
     * }
     */
    public function reconcile(string $period): array
    {
        $types = $this->typeSummaries($period);

        $checks = [
            $this->checkRunOwnership($period),
            $this->checkSourcesResolve($period),
            $this->checkRowArithmetic($period),
            $this->checkPoolsShareOut($period),
            $this->checkNoDuplicates($period),
            $this->checkRunTotals($types),
            $this->checkDirectAgainstSales($period),
            $this->checkPaymentEvidence($period),
        ];

        $failed = count(array_filter($checks, fn (array $check) => $check['status'] === 'failed'));
        $entries = array_sum(array_column($types, 'entries'));

        return [
            'period' => $period,
            'types' => $types,
            'totals' => [
                'entries' => $entries,
                'amount' => Money::sum(array_column($types, 'amount')),
                'paid_amount' => Money::sum(array_column($types, 'paid_amount')),
                'unpaid_amount' => Money::sum(array_column($types, 'unpaid_amount')),
                'paid' => array_sum(array_column($types, 'paid')),
                'unpaid' => array_sum(array_column($types, 'unpaid')),
                'members' => (int) RewardLedger::query()
                    ->forPeriod($period)
                    ->distinct()
                    ->count('member_id'),
            ],
            'checks' => $checks,
            'passed' => count(array_filter($checks, fn (array $check) => $check['status'] === 'passed')),
            'failed' => $failed,
            'clean' => $failed === 0,
            'empty' => $entries === 0,
        ];
    }

    /**
     * Every period that has ever produced a reward, newest first.
     *
     * @return list<string>
     */
    public function periods(): array
    {
        return RewardLedger::query()
            ->select('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period')
            ->all();
    }

    /**
     * One member's whole reward history, by type and by period.
     *
     * @return array{
     *     by_type: list<array<string, mixed>>,
     *     by_period: list<array<string, mixed>>,
     *     total: string, paid: string, unpaid: string, entries: int
     * }
     */
    public function memberStatement(Member $member): array
    {
        $byType = [];

        // Visible engines only. A hidden one is still being paid, so this
        // statement is deliberately not the whole of what the member is owed —
        // the reconciliation screen is where the full figure stays visible.
        foreach (RewardType::visible() as $type) {
            $row = RewardLedger::query()
                ->where('member_id', $member->id)
                ->ofType($type)
                ->selectRaw($this->summarySelect())
                ->first();

            $byType[] = [
                'type' => $type,
                'entries' => (int) $row->entries,
                'amount' => Money::of($row->amount),
                'paid_amount' => Money::of($row->paid_amount),
                'unpaid_amount' => Money::of($row->unpaid_amount),
            ];
        }

        $byPeriod = RewardLedger::query()
            ->where('member_id', $member->id)
            ->whereIn('reward_type', RewardType::visibleValues())
            ->selectRaw('period, '.$this->summarySelect())
            ->groupBy('period')
            ->orderByDesc('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'entries' => (int) $row->entries,
                'amount' => Money::of($row->amount),
                'paid_amount' => Money::of($row->paid_amount),
                'unpaid_amount' => Money::of($row->unpaid_amount),
            ])
            ->all();

        return [
            'by_type' => $byType,
            'by_period' => $byPeriod,
            'total' => Money::sum(array_column($byType, 'amount')),
            'paid' => Money::sum(array_column($byType, 'paid_amount')),
            'unpaid' => Money::sum(array_column($byType, 'unpaid_amount')),
            'entries' => array_sum(array_column($byType, 'entries')),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Source descriptors
    |--------------------------------------------------------------------------
    */

    /** @return array{label: string, detail: string, url: ?string, resolved: bool} */
    private function registrySaleSource(RewardLedger $row): array
    {
        $sale = RegistrySale::query()->with('member:id,member_code,name')->find($row->source_id);

        if ($sale === null) {
            return $this->missing('Registry sale #'.$row->source_id, 'sale');
        }

        return [
            'label' => 'Registry sale #'.$sale->id,
            'detail' => sprintf(
                '%s Sq.Ft. sold by %s on %s%s',
                number_format((float) $sale->sqft, 2),
                $sale->member?->member_code ?? 'a deleted member',
                $sale->registry_date->format('d M Y'),
                $sale->registry_reference ? ' · registry '.$sale->registry_reference : '',
            ),
            'url' => route('admin.sales.show', $sale->id),
            'resolved' => true,
        ];
    }

    /** @return array{label: string, detail: string, url: ?string, resolved: bool} */
    private function sellerSource(RewardLedger $row): array
    {
        $seller = Member::query()->find($row->source_id, ['id', 'member_code', 'name']);

        if ($seller === null) {
            return $this->missing('Seller #'.$row->source_id, 'member');
        }

        return [
            'label' => $seller->member_code.' — '.$row->period,
            'detail' => sprintf(
                "%s's own approved sales in %s (%s Sq.Ft.) formed the pool this share came out of.",
                $seller->name,
                $row->period,
                number_format((float) $row->sqft, 2),
            ),
            // The explorer 404s while Upline is hidden, so no link is offered
            // rather than one that dead-ends. The seller is still named: the
            // amount has to stay explainable even with its screens switched off.
            'url' => RewardType::Upline->isVisible()
                ? route('admin.rewards.upline.explain', ['member' => $seller->id, 'period' => $row->period])
                : null,
            'resolved' => true,
        ];
    }

    /** @return array{label: string, detail: string, url: ?string, resolved: bool} */
    private function targetSource(RewardLedger $row): array
    {
        $verdict = TargetCalculation::query()->find($row->source_id);

        if ($verdict === null) {
            return $this->missing('Target verdict #'.$row->source_id, 'verdict');
        }

        return [
            'label' => 'Target verdict #'.$verdict->id,
            'detail' => sprintf(
                'Threshold %s Sq.Ft. reached with %s Sq.Ft. of team sales in %s.',
                number_format((float) $verdict->target_sqft, 2),
                number_format((float) $verdict->achieved_sqft, 2),
                $row->period,
            ),
            'url' => route('admin.targets.show', ['member' => $row->member_id, 'period' => $row->period]),
            'resolved' => true,
        ];
    }

    /**
     * The Company Club source is the MONTH, not a record.
     *
     * `source_id` is 0 deliberately — one pool is formed from every eligible
     * sale in the period, so a foreign key to any single row would be a lie
     * (PROJECT_STATE, "Ledger integration needs no schema change"). It always
     * resolves, because the period always exists.
     *
     * @return array{label: string, detail: string, url: ?string, resolved: bool}
     */
    private function clubPoolSource(RewardLedger $row): array
    {
        return [
            'label' => 'The '.$row->period.' Company Club pool',
            'detail' => sprintf(
                'One pool for the whole month: %s eligible Sq.Ft. × ₹%s, shared equally. The source '
                .'is the month itself, not a single record.',
                number_format((float) $row->sqft, 2),
                number_format((float) $row->rate, 2),
            ),
            'url' => route('admin.company-club.explain', ['member' => $row->member_id, 'period' => $row->period]),
            'resolved' => true,
        ];
    }

    /** @return array{label: string, detail: string, url: ?string, resolved: bool} */
    private function missing(string $label, string $noun): array
    {
        return [
            'label' => $label,
            'detail' => "This {$noun} no longer exists. The amount stands, but nothing explains it.",
            'url' => null,
            'resolved' => false,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    */

    /**
     * Per-type figures for a period, beside what the run recorded.
     *
     * @return list<array<string, mixed>>
     */
    private function typeSummaries(string $period): array
    {
        $summaries = [];

        foreach (RewardType::cases() as $type) {
            $row = RewardLedger::query()
                ->forPeriod($period)
                ->ofType($type)
                ->selectRaw($this->summarySelect().', COALESCE(SUM(sqft), 0) as sqft')
                ->first();

            $run = CalculationRun::query()
                ->where('period', $period)
                ->where('run_type', $this->runTypeFor($type))
                ->where('status', CalculationRunStatus::Completed)
                ->latest('id')
                ->first();

            $amount = Money::of($row->amount);
            $runAmount = $run !== null ? Money::of($run->total_amount) : null;

            $summaries[] = [
                'type' => $type,
                'hidden' => ! $type->isVisible(),
                'entries' => (int) $row->entries,
                'sqft' => Money::of($row->sqft),
                'amount' => $amount,
                'paid' => (int) $row->paid_entries,
                'unpaid' => (int) $row->unpaid_entries,
                'paid_amount' => Money::of($row->paid_amount),
                'unpaid_amount' => Money::of($row->unpaid_amount),
                'run' => $run,
                'run_amount' => $runAmount,
                'agrees' => $runAmount === null || Money::compare($amount, $runAmount) === 0,
                'arithmetic' => $this->arithmetic($type),
            ];
        }

        return $summaries;
    }

    /**
     * Check 1 — every amount belongs to a completed run of its own month and type.
     *
     * This is the backbone of traceability. Recalculation deletes a period's
     * ledger rows and supersedes the old run in the same transaction, so a live
     * row pointing at a superseded, failed or foreign run means the two halves
     * of a rebuild came apart.
     *
     * @return array<string, mixed>
     */
    private function checkRunOwnership(string $period): array
    {
        $rows = DB::table('reward_ledger as l')
            ->leftJoin('calculation_runs as r', 'r.id', '=', 'l.calculation_run_id')
            ->where('l.period', $period)
            ->selectRaw('l.id, l.reward_type, l.amount, r.status as run_status, '
                .'r.period as run_period, r.run_type as run_type')
            ->orderBy('l.id')
            ->get();

        $offenders = [];

        foreach ($rows as $row) {
            $expectedRunType = $this->runTypeForValue($row->reward_type);

            $problem = match (true) {
                $row->run_status === null => 'the run is missing',
                $row->run_status !== CalculationRunStatus::Completed->value => 'the run is '.$row->run_status,
                $row->run_period !== $period => 'the run belongs to '.$row->run_period,
                $expectedRunType !== null && $row->run_type !== $expectedRunType => 'the run is a '
                    .$row->run_type.' run',
                default => null,
            };

            if ($problem === null) {
                continue;
            }

            $offenders[] = sprintf(
                'Ledger #%d (%s, ₹%s): %s.',
                $row->id,
                $row->reward_type,
                number_format((float) $row->amount, 2),
                $problem,
            );
        }

        return $this->result(
            key: 'run_ownership',
            title: 'Every amount belongs to a completed run of its own month',
            explains: 'A reward row carries the id of the run that created it. That run must be '
                .'completed, must be for this same month, and must be of the engine that pays this '
                .'reward type. A rebuild deletes the old rows and supersedes the old run together, '
                .'so a live row on a dead run means the two came apart.',
            failed: $offenders !== [],
            passMessage: 'Every row traces to the completed run that wrote it.',
            failMessage: count($offenders).' row(s) do not trace to a live run for this month.',
            offenders: $offenders,
        );
    }

    /**
     * Check 2 — every source record still exists.
     *
     * The Company Club pool is excluded by design, not by oversight: its source
     * is the month, which cannot go missing.
     *
     * @return array<string, mixed>
     */
    private function checkSourcesResolve(string $period): array
    {
        $offenders = [];

        $lookups = [
            'registry_sale' => fn (array $ids) => RegistrySale::query()->whereIn('id', $ids)->pluck('id')->all(),
            'member_period' => fn (array $ids) => Member::query()->whereIn('id', $ids)->pluck('id')->all(),
            'target_calculation' => fn (array $ids) => TargetCalculation::query()->whereIn('id', $ids)->pluck('id')->all(),
        ];

        foreach ($lookups as $sourceType => $existing) {
            $ids = RewardLedger::query()
                ->forPeriod($period)
                ->where('source_type', $sourceType)
                ->distinct()
                ->pluck('source_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids === []) {
                continue;
            }

            $found = array_map(fn ($id) => (int) $id, $existing($ids));

            foreach (array_diff($ids, $found) as $missing) {
                $offenders[] = $sourceType.' #'.$missing.' no longer exists.';
            }
        }

        $unknown = RewardLedger::query()
            ->forPeriod($period)
            ->whereNotIn('source_type', [...array_keys($lookups), 'company_club_pool'])
            ->distinct()
            ->pluck('source_type')
            ->all();

        foreach ($unknown as $sourceType) {
            $offenders[] = 'Source type "'.$sourceType.'" is not known to the ledger.';
        }

        return $this->result(
            key: 'sources',
            title: 'Every amount still points at the record it was calculated from',
            explains: 'A direct reward names its registry sale, an upline share names the seller '
                .'whose month formed the pool, and a target prize names the verdict that awarded it. '
                .'The Company Club pool is sourced from the whole month rather than one record, so it '
                .'has nothing that could go missing.',
            failed: $offenders !== [],
            passMessage: 'Every source record resolves.',
            failMessage: count($offenders).' source record(s) are missing or unrecognised.',
            offenders: $offenders,
        );
    }

    /**
     * Check 3 — the two engines that promise `sqft × rate = amount` keep it.
     *
     * Deliberately NOT applied to Upline or Company Club: both store the pool's
     * inputs on the row and pay a share of it, so the multiplication is not
     * meant to come out. Check 4 tests those instead.
     *
     * @return array<string, mixed>
     */
    private function checkRowArithmetic(string $period): array
    {
        $types = array_values(array_filter(
            RewardType::cases(),
            fn (RewardType $type) => $this->multipliesOut($type),
        ));

        $offenders = RewardLedger::query()
            ->forPeriod($period)
            ->whereIn('reward_type', array_map(fn (RewardType $type) => $type->value, $types))
            ->get(['id', 'reward_type', 'sqft', 'rate', 'amount'])
            ->filter(fn (RewardLedger $row) => Money::compare(
                Money::multiply((string) $row->sqft, (string) $row->rate),
                (string) $row->amount,
            ) !== 0)
            ->map(fn (RewardLedger $row) => sprintf(
                'Ledger #%d (%s): %s × %s = %s, but the row says ₹%s.',
                $row->id,
                $row->reward_type->label(),
                number_format((float) $row->sqft, 2),
                number_format((float) $row->rate, 2),
                number_format((float) Money::multiply((string) $row->sqft, (string) $row->rate), 2),
                number_format((float) $row->amount, 2),
            ))
            ->values()
            ->all();

        return $this->result(
            key: 'arithmetic',
            title: 'Direct and Target amounts multiply out exactly',
            explains: 'These two engines pay Sq.Ft. × rate on the row itself, so the multiplication '
                .'must come out to the paisa. Upline and Company Club deliberately do not: they store '
                .'the pool\'s inputs and pay an equal share of it, which the next check tests instead.',
            failed: $offenders !== [],
            passMessage: 'Every Direct and Target row multiplies out exactly.',
            failMessage: count($offenders).' row(s) do not multiply out.',
            offenders: $offenders,
        );
    }

    /**
     * Check 4 — every pool was shared out in full, to within rounding.
     *
     * Upline forms one pool per seller per month; the Company Club forms one
     * pool for the whole month. In both cases the shares are rounded
     * independently, so they need not re-sum to the pool exactly — `Money`
     * documents this and the residual is real money that is displayed rather
     * than swallowed. The tolerance is a paisa a row; anything wider is a
     * genuine shortfall.
     *
     * @return array<string, mixed>
     */
    private function checkPoolsShareOut(string $period): array
    {
        $offenders = [];

        // Upline: one pool per seller.
        $sellers = RewardLedger::query()
            ->forPeriod($period)
            ->ofType(RewardType::Upline)
            ->selectRaw('source_id, COUNT(*) as receivers, MAX(sqft) as sqft, '
                .'MAX(rate) as rate, COALESCE(SUM(amount), 0) as paid_out')
            ->groupBy('source_id')
            ->get();

        foreach ($sellers as $seller) {
            $pool = Money::multiply(Money::of($seller->sqft), Money::of($seller->rate));
            $difference = Money::subtract(Money::of($seller->paid_out), $pool);

            if (! $this->outsideSlack($difference, (int) $seller->receivers)) {
                continue;
            }

            $offenders[] = sprintf(
                'Upline pool for seller #%d: %s Sq.Ft. × ₹%s = ₹%s, but ₹%s reached %d upline(s) — '
                .'a difference of ₹%s.',
                $seller->source_id,
                number_format((float) $seller->sqft, 2),
                number_format((float) $seller->rate, 2),
                number_format((float) $pool, 2),
                number_format((float) $seller->paid_out, 2),
                $seller->receivers,
                number_format((float) $difference, 2),
            );
        }

        // Company Club: ONE pool for the month, never one per seller.
        $club = RewardLedger::query()
            ->forPeriod($period)
            ->ofType(RewardType::CompanyClub)
            ->selectRaw('COUNT(*) as recipients, COALESCE(MAX(sqft), 0) as sqft, '
                .'COALESCE(MAX(rate), 0) as rate, COALESCE(SUM(amount), 0) as paid_out')
            ->first();

        if ((int) $club->recipients > 0) {
            $pool = Money::multiply(Money::of($club->sqft), Money::of($club->rate));
            $difference = Money::subtract(Money::of($club->paid_out), $pool);

            if ($this->outsideSlack($difference, (int) $club->recipients)) {
                $offenders[] = sprintf(
                    'Company Club pool: %s Sq.Ft. × ₹%s = ₹%s, but ₹%s reached %d member(s) — '
                    .'a difference of ₹%s.',
                    number_format((float) $club->sqft, 2),
                    number_format((float) $club->rate, 2),
                    number_format((float) $pool, 2),
                    number_format((float) $club->paid_out, 2),
                    $club->recipients,
                    number_format((float) $difference, 2),
                );
            }
        }

        return $this->result(
            key: 'pools',
            title: 'Every pool was shared out in full',
            explains: 'Upline forms one pool per seller per month; the Company Club forms one pool for '
                .'the whole month. Each share is rounded on its own, so the shares can sit a few paise '
                .'either side of the pool — a paisa a row is allowed and anything wider is a real '
                .'difference.',
            failed: $offenders !== [],
            passMessage: 'Every pool balances against the shares paid out of it.',
            failMessage: count($offenders).' pool(s) do not balance.',
            offenders: $offenders,
        );
    }

    /**
     * Check 5 — no member was paid twice from the same source.
     *
     * The database already forbids this with a unique index. The check exists
     * because a reconciliation report that only tested things which cannot fail
     * would not be reconciliation — and an index can be dropped by a migration
     * without anybody noticing until money moves.
     *
     * @return array<string, mixed>
     */
    private function checkNoDuplicates(string $period): array
    {
        $offenders = DB::table('reward_ledger')
            ->where('period', $period)
            ->selectRaw('member_id, reward_type, source_type, source_id, COUNT(*) as copies')
            ->groupBy('member_id', 'reward_type', 'source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => sprintf(
                'Member #%d holds %d %s rewards from %s #%d.',
                $row->member_id,
                $row->copies,
                $row->reward_type,
                $row->source_type,
                $row->source_id,
            ))
            ->all();

        return $this->result(
            key: 'duplicates',
            title: 'No member was paid twice from the same source',
            explains: 'One member may hold at most one reward of a given type from a given source in a '
                .'month. A unique index on the ledger enforces it; this re-checks the data itself, '
                .'because an index can be dropped by a migration without anybody noticing until money '
                .'moves.',
            failed: $offenders !== [],
            passMessage: 'No duplicate rewards.',
            failMessage: count($offenders).' duplicated source(s).',
            offenders: $offenders,
        );
    }

    /**
     * Check 6 — each engine's ledger total equals the total its run recorded.
     *
     * The run snapshot is written from the engine's own running total as it
     * builds the rows, so this compares two independent accounts of the same
     * work: what the engine believed it produced, and what is in the table.
     *
     * @param  list<array<string, mixed>>  $types
     * @return array<string, mixed>
     */
    private function checkRunTotals(array $types): array
    {
        $offenders = [];

        foreach ($types as $summary) {
            if ($summary['run'] === null || $summary['agrees']) {
                continue;
            }

            $offenders[] = sprintf(
                '%s: the ledger holds ₹%s but run #%d recorded ₹%s.',
                $summary['type']->label(),
                number_format((float) $summary['amount'], 2),
                $summary['run']->id,
                number_format((float) $summary['run_amount'], 2),
            );
        }

        return $this->result(
            key: 'run_totals',
            title: 'Each engine\'s ledger total matches the total its run recorded',
            explains: 'Every run stores what it believed it produced. Comparing that against the sum of '
                .'the rows in the table is two independent accounts of the same work — they can only '
                .'differ if rows were added or removed outside the run that owns them.',
            failed: $offenders !== [],
            passMessage: 'Every completed run agrees with its rows.',
            failMessage: count($offenders).' engine(s) disagree with their run.',
            offenders: $offenders,
        );
    }

    /**
     * Check 7 — the Direct ledger equals the month's approved sales.
     *
     * ONLY Direct. It is the one engine whose ledger is a plain function of the
     * sales: every approved sale pays its own seller Sq.Ft. × ₹40 and nothing
     * else touches it. Upline divides through the network, Target pays a
     * threshold rather than what was sold, and the Company Club excludes
     * inactive sellers — comparing any of those against raw sales would report a
     * healthy month as broken, which is exactly the false alarm the Calculation
     * Center already had to remove once.
     *
     * @return array<string, mixed>
     */
    private function checkDirectAgainstSales(string $period): array
    {
        $rate = RewardType::Direct->rate();

        $saleSqft = Money::of((string) (RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->sum('sqft') ?: '0'));

        $expected = Money::multiply($saleSqft, $rate);

        $ledger = Money::of((string) (RewardLedger::query()
            ->forPeriod($period)
            ->ofType(RewardType::Direct)
            ->sum('amount') ?: '0'));

        $calculated = CalculationRun::query()
            ->where('period', $period)
            ->where('run_type', $this->runTypeFor(RewardType::Direct))
            ->where('status', CalculationRunStatus::Completed)
            ->exists();

        // A month nobody has calculated has no Direct rows, and that is correct
        // rather than a shortfall. Saying so is more use than a red cross.
        if (! $calculated) {
            return $this->result(
                key: 'direct_sales',
                title: 'The Direct ledger equals the month\'s approved sales',
                explains: $this->directCheckExplanation(),
                failed: false,
                passMessage: $period.' has not been calculated, so there is nothing to compare yet. '
                    .'Approved sales stand at '.number_format((float) $saleSqft, 2).' Sq.Ft.',
                failMessage: '',
                offenders: [],
                status: 'skipped',
            );
        }

        $difference = Money::subtract($ledger, $expected);

        return $this->result(
            key: 'direct_sales',
            title: 'The Direct ledger equals the month\'s approved sales',
            explains: $this->directCheckExplanation(),
            failed: ! Money::isZero($difference),
            passMessage: sprintf(
                '%s Sq.Ft. × ₹%s = ₹%s, and the ledger holds exactly that.',
                number_format((float) $saleSqft, 2),
                number_format((float) $rate, 0),
                number_format((float) $expected, 2),
            ),
            failMessage: sprintf(
                'The sales say ₹%s but the ledger holds ₹%s — a difference of ₹%s. The month\'s figures '
                .'are behind its sales; rebuild it from the Calculation Center.',
                number_format((float) $expected, 2),
                number_format((float) $ledger, 2),
                number_format((float) $difference, 2),
            ),
            offenders: [],
        );
    }

    private function directCheckExplanation(): string
    {
        return 'Direct is the only engine whose ledger is a plain function of the sales — every approved '
            .'sale pays its own seller and nothing else touches it. Upline divides through the network, '
            .'Target pays a threshold rather than what was sold, and the Company Club excludes inactive '
            .'sellers, so none of those can be compared against raw sales without raising a false alarm.';
    }

    /**
     * Check 8 — every confirmed payment names an admin and a date.
     *
     * Payment is the moment an amount stops being provisional and freezes its
     * whole month. An unattributed one leaves nobody to ask about it.
     *
     * @return array<string, mixed>
     */
    private function checkPaymentEvidence(string $period): array
    {
        $offenders = RewardLedger::query()
            ->forPeriod($period)
            ->where('status', LedgerStatus::Paid)
            ->where(fn ($query) => $query->whereNull('paid_at')->orWhereNull('paid_by'))
            ->get(['id', 'member_id', 'amount', 'paid_at', 'paid_by'])
            ->map(fn (RewardLedger $row) => sprintf(
                'Ledger #%d (₹%s) is marked paid but records no %s.',
                $row->id,
                number_format((float) $row->amount, 2),
                $row->paid_at === null ? 'date' : 'admin',
            ))
            ->all();

        return $this->result(
            key: 'payment_evidence',
            title: 'Every confirmed payment names an admin and a date',
            explains: 'Confirming a payment is what turns a provisional figure final and locks the month '
                .'against recalculation. An unattributed confirmation leaves nobody to ask.',
            failed: $offenders !== [],
            passMessage: 'Every paid amount is attributed.',
            failMessage: count($offenders).' paid amount(s) are unattributed.',
            offenders: $offenders,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<string>  $offenders
     * @return array<string, mixed>
     */
    private function result(
        string $key,
        string $title,
        string $explains,
        bool $failed,
        string $passMessage,
        string $failMessage,
        array $offenders,
        ?string $status = null,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'explains' => $explains,
            'status' => $status ?? ($failed ? 'failed' : 'passed'),
            'message' => $failed ? $failMessage : $passMessage,
            'offenders' => $offenders,
        ];
    }

    /** Is a residual wider than independent rounding could produce? */
    private function outsideSlack(string $difference, int $rows): bool
    {
        $absolute = Money::compare($difference, Money::zero()) < 0
            ? Money::multiply($difference, '-1')
            : $difference;

        $allowed = Money::multiply(self::ROUNDING_SLACK_PER_ROW, (string) max($rows, 1));

        return Money::compare($absolute, $allowed) > 0;
    }

    private function runTypeFor(RewardType $type): string
    {
        return match ($type) {
            RewardType::Direct => 'direct',
            RewardType::Upline => 'upline',
            RewardType::Target => 'target',
            RewardType::CompanyClub => 'company_club',
        };
    }

    /** The same mapping from a raw column value, which may be a string or a cast enum. */
    private function runTypeForValue(mixed $rewardType): ?string
    {
        $value = $rewardType instanceof RewardType ? $rewardType->value : (string) $rewardType;

        return RewardType::tryFrom($value) !== null
            ? $this->runTypeFor(RewardType::from($value))
            : null;
    }

    /** The paid / unpaid split, written once because several queries want it. */
    private function summarySelect(): string
    {
        $paid = LedgerStatus::Paid->value;

        return 'COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount, '
            ."SUM(CASE WHEN status = '{$paid}' THEN 1 ELSE 0 END) as paid_entries, "
            ."SUM(CASE WHEN status <> '{$paid}' THEN 1 ELSE 0 END) as unpaid_entries, "
            ."COALESCE(SUM(CASE WHEN status = '{$paid}' THEN amount ELSE 0 END), 0) as paid_amount, "
            ."COALESCE(SUM(CASE WHEN status <> '{$paid}' THEN amount ELSE 0 END), 0) as unpaid_amount";
    }
}
