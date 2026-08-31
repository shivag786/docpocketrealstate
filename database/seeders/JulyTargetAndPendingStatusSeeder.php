<?php

namespace Database\Seeders;

use App\Enums\SaleStatus;
use App\Enums\TargetLevel;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\TargetCalculation;
use App\Models\User;
use App\Modules\MemberStatus\MemberStatusServiceProvider;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use App\Services\CalculationRunService;
use App\Services\PeriodRecalculationService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Demo data for testing the PENDING member status (requested 2026-08-25).
 *
 * The scenario, as asked for:
 *
 *   RAHUL's branch     sells through July 2026. Rahul and exactly ONE of his
 *                      team reach the One Month Target (5,000 Sq.Ft.); everyone
 *                      else sells, but not enough. All of them end ACTIVE.
 *
 *   RESHMA's branch    sells nothing in July, and nothing in June either — in
 *                      fact nothing ever. They end PENDING.
 *
 *   AUGUST             a couple of small sales, purely so the month is rolled
 *                      up and the ladder is visible: the two July winners are
 *                      now measured against the TWO MONTH target automatically,
 *                      which is the rule "achieve one, the next one opens".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY RESHMA'S BRANCH GETS BACK-DATED JOINING DATES
 *
 * PENDING means "no qualifying activity for 90 to 179 days". The engine measures
 * a member who has never sold from their JOINING date, and never from before it
 * — a member cannot have been inactive before they existed. Every member in this
 * database joined 2026-08-19, six days ago, so as things stand nobody can be
 * anything but ACTIVE no matter what is inserted.
 *
 * So this seeder moves the joining date of the members in the story, and only
 * those. Reshma's branch is moved to ~110 days ago, which lands them inside the
 * PENDING window without falling through to INACTIVE at 180. Rahul's branch is
 * moved to just before July so that a July sale by them is not a sale by someone
 * who had not joined yet.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT TOUCHES
 *
 *   INSERTS  registry_sales rows for July 2026 (marked in `notes`)
 *   UPDATES  members.joining_date, for the members in the story only
 *   RUNS     the four engines for 2026-07 and cascades Target into 2026-08,
 *            through the application's own PeriodRecalculationService
 *   RUNS     the member status calculation, registering the module provider
 *            itself so this works whether or not the module has been wired in
 *
 * It never touches June, which holds a paid Company Club reward and is therefore
 * locked, and it re-runs cleanly: its own sales are deleted first.
 *
 *      php artisan db:seed --class=JulyTargetAndPendingStatusSeeder
 */
class JulyTargetAndPendingStatusSeeder extends Seeder
{
    private const PERIOD = '2026-07';

    /**
     * The month after the win.
     *
     * Rolled up so the ladder can be SEEN: a member who achieved Target 1 in
     * July is measured against Target 2 here without anybody doing anything.
     * The engine advances them; there is no switch and no admin step.
     */
    private const NEXT_PERIOD = '2026-08';

    /** Marks every row this seeder owns, so a re-run can clear them. */
    private const TAG = 'DEMO-JUL-2026';

    /** Rahul's branch joins before the July sales they make. */
    private const RAHUL_JOINED = '2026-06-01';

    /**
     * Reshma's branch joins ~110 days before 2026-08-25 — comfortably inside
     * PENDING (90–179) and well clear of INACTIVE (180+).
     */
    private const RESHMA_JOINED = '2026-05-08';

    public function run(): void
    {
        $admin = User::query()->where('role', UserRole::Admin)->first();

        if ($admin === null) {
            throw new RuntimeException('No admin user found. Run AdminUserSeeder first.');
        }

        $rahul = $this->memberNamed('rahul');
        $reshma = $this->memberNamed('reshma');

        $rahulBranch = $this->branchOf($rahul);
        $reshmaBranch = $this->branchOf($reshma);

        $this->command?->info('Seeding July 2026 demo data…');

        // BEFORE anything is deleted. Learned the hard way: this seeder deletes
        // its own sales and re-inserts them, and the months it writes have to be
        // rebuildable afterwards. If a reward in one of them has been marked
        // paid, that month is locked — and finding out AFTER the delete leaves
        // ledger rows pointing at sales that no longer exist.
        $this->assertPeriodsAreRebuildable();

        $this->clearPreviousRun();
        $this->backdateJoiningDates($rahulBranch, self::RAHUL_JOINED);
        $this->backdateJoiningDates($reshmaBranch, self::RESHMA_JOINED);

        $achiever = $this->sellThroughJuly($rahul, $rahulBranch, $admin);

        $this->command?->info('Recalculating '.self::PERIOD.' and every month after it…');
        app(PeriodRecalculationService::class)->recalculate(self::PERIOD, $admin);

        $this->reportTargets($rahul, $achiever);

        $this->openNextMonth($rahul, $achiever, $admin);

        $this->calculateMemberStatus();
        $this->reportStatuses($rahulBranch, $reshmaBranch);
    }

    /**
     * Refuse to run at all if either month it writes is locked by a payment.
     *
     * A locked month cannot be recalculated, and this seeder replaces the sales
     * those figures were built from. Stopping here — before a single row is
     * touched — is the difference between "nothing happened" and a ledger that
     * no longer traces to a sale.
     */
    private function assertPeriodsAreRebuildable(): void
    {
        $runs = app(CalculationRunService::class);

        foreach ([self::PERIOD, self::NEXT_PERIOD] as $period) {
            if (! $runs->periodIsPaid($period)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s holds a reward that has been marked paid, so its figures can no longer be '
                ."rebuilt.\n\n"
                .'This seeder replaces the sales those figures were calculated from, so it will '
                ."not run against a locked month.\n\n"
                .'To re-seed, release that payment first (or point the seeder at a different month).',
                $period,
            ));
        }
    }

    // -----------------------------------------------------------------
    // The network
    // -----------------------------------------------------------------

    private function memberNamed(string $name): Member
    {
        $member = Member::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($member === null) {
            throw new RuntimeException(
                "No member named '{$name}' was found. This seeder expects the demo network "
                .'(DemoUplineNetworkSeeder) to be present.'
            );
        }

        return $member;
    }

    /**
     * A member and everyone beneath them, at any depth.
     *
     * @return Collection<int, Member>
     */
    private function branchOf(Member $root): Collection
    {
        $ids = array_merge([$root->id], $root->descendantIds());

        return Member::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, Member>  $branch
     */
    private function backdateJoiningDates(Collection $branch, string $date): void
    {
        Member::query()
            ->whereIn('id', $branch->pluck('id'))
            ->update(['joining_date' => $date, 'updated_at' => now()]);

        $this->command?->line(
            '  joining date → '.CarbonImmutable::parse($date)->format('d M Y')
            .' for '.$branch->count().' members ('.$branch->first()->name.'’s branch)'
        );
    }

    // -----------------------------------------------------------------
    // The sales
    // -----------------------------------------------------------------

    private function clearPreviousRun(): void
    {
        $deleted = RegistrySale::query()->where('notes', 'like', self::TAG.'%')->delete();

        if ($deleted > 0) {
            $this->command?->line("  cleared {$deleted} sales from a previous run of this seeder");
        }
    }

    /**
     * Give Rahul's whole branch a July, then top up ONE direct referral until
     * their team crosses the target.
     *
     * The top-up is calculated rather than hard-coded, so the outcome is the
     * same whatever shape the branch happens to be: everyone gets a small sale,
     * and exactly one of them is pushed over 5,000 — which necessarily carries
     * Rahul over it too, since Rahul's team contains theirs.
     *
     * @param  Collection<int, Member>  $branch
     */
    private function sellThroughJuly(Member $rahul, Collection $branch, User $admin): Member
    {
        // The chosen team member: Rahul's first direct referral.
        $achiever = $branch->first(fn (Member $m) => $m->sponsor_id === $rahul->id);

        if ($achiever === null) {
            throw new RuntimeException('Rahul has no direct referrals to make an achiever of.');
        }

        // Everyday sales, spread across July. Small and varied — none of them
        // large enough on their own to carry a branch over the threshold.
        $everyday = [820, 640, 450, 380, 310, 275, 240, 205, 180, 160];
        $day = 3;
        $index = 0;

        foreach ($branch as $member) {
            $sqft = $everyday[$index % count($everyday)];
            $index++;

            $this->sell($member, (string) $sqft, $this->julyDay($day), $admin);
            $day = $day % 26 + 2;
        }

        // What the achiever's own team is at now, and what it takes to cross.
        $teamSqft = $this->teamSqftFor($achiever);
        $threshold = TargetLevel::One->sqft();
        $topUp = Money::add(Money::subtract($threshold, $teamSqft), '500.00');

        if (Money::isPositive($topUp)) {
            $this->sell($achiever, $topUp, $this->julyDay(21), $admin, 'the target-clinching sale');
        }

        $this->command?->line(sprintf(
            '  %d July sales inserted. %s tops up %s Sq.Ft. to clear the %s Sq.Ft. target.',
            RegistrySale::query()->where('notes', 'like', self::TAG.'%')->count(),
            $achiever->name,
            number_format((float) $topUp, 0),
            number_format((float) $threshold, 0),
        ));

        return $achiever;
    }

    private function sell(Member $member, string $sqft, string $date, User $admin, string $note = ''): void
    {
        RegistrySale::query()->create([
            'member_id' => $member->id,
            'project_id' => null,
            'property_id' => null,
            'registry_reference' => null,
            'registry_date' => $date,
            'sale_date' => $date,
            'sqft' => $sqft,
            'status' => SaleStatus::Approved,
            'notes' => trim(self::TAG.' '.$note),
            'entered_by' => $admin->id,
        ]);
    }

    private function julyDay(int $day): string
    {
        return self::PERIOD.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The member's own July Sq.Ft. plus every descendant's — the same figure the
     * Team Sales engine will produce, computed here before it has run.
     */
    private function teamSqftFor(Member $member): string
    {
        $ids = array_merge([$member->id], $member->descendantIds());

        return Money::of(
            RegistrySale::query()
                ->approved()
                ->forPeriod(self::PERIOD)
                ->whereIn('member_id', $ids)
                ->sum('sqft')
        );
    }

    /**
     * Roll up the following month so the ladder is visible.
     *
     * Nothing here promotes anybody. The two July winners are measured against
     * the Two Month Target in August because the engine replays their history
     * and finds Target 1 already achieved — that is the whole mechanism, and
     * these two small sales exist only to give the month something to roll up.
     */
    private function openNextMonth(Member $rahul, Member $achiever, User $admin): void
    {
        $this->sell($rahul, '600', self::NEXT_PERIOD.'-05', $admin, 'opening the next window');
        $this->sell($achiever, '450', self::NEXT_PERIOD.'-09', $admin, 'opening the next window');

        app(PeriodRecalculationService::class)->recalculate(self::NEXT_PERIOD, $admin);

        $rows = TargetCalculation::query()
            ->where('period', self::NEXT_PERIOD)
            ->whereIn('member_id', [$rahul->id, $achiever->id])
            ->with('member:id,name,member_code')
            ->get();

        $this->command?->newLine();
        $this->command?->info('The ladder — '.self::NEXT_PERIOD.' (nobody was promoted by hand)');

        foreach ($rows as $row) {
            $this->command?->line(sprintf(
                '  %-10s %-14s now on %s — %s Sq.Ft. over %s, window %s',
                $row->member->member_code,
                $row->member->name,
                $row->target_level->shortLabel(),
                number_format((float) $row->target_sqft, 0),
                $row->window_months.' month'.($row->window_months === 1 ? '' : 's'),
                $row->windowLabel(),
            ));
        }
    }

    // -----------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------

    private function reportTargets(Member $rahul, Member $achiever): void
    {
        $achievers = TargetCalculation::query()
            ->where('period', self::PERIOD)
            ->where('achieved', true)
            ->with('member:id,name,member_code')
            ->get();

        $this->command?->newLine();
        $this->command?->info('One Month Target — '.self::PERIOD);

        foreach ($achievers as $row) {
            $this->command?->line(sprintf(
                '  ACHIEVED  %-10s %-18s team %9s Sq.Ft.  prize ₹%s',
                $row->member->member_code,
                $row->member->name,
                number_format((float) $row->achieved_sqft, 2),
                number_format((float) $row->reward_amount, 2),
            ));
        }

        $expected = [$rahul->id, $achiever->id];
        $actual = $achievers->pluck('member_id')->all();

        sort($expected);
        sort($actual);

        if ($expected !== $actual) {
            $this->command?->warn(
                '  Expected exactly Rahul and '.$achiever->name.' to achieve. '
                .'Check the branch shape — someone else crossed 5,000 Sq.Ft.'
            );
        }
    }

    /**
     * Calculate the isolated module's statuses.
     *
     * The provider is registered HERE rather than in bootstrap/providers.php,
     * which is still untouched — so this works whether or not the module has
     * been wired into the application yet.
     */
    private function calculateMemberStatus(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('member_status_snapshot')) {
            $this->command?->warn(
                'Member status tables are missing — run `php artisan migrate` and re-run this seeder.'
            );

            return;
        }

        try {
            app()->register(MemberStatusServiceProvider::class);
            app(StatusRecalculationService::class)->recalculateAll();
        } catch (Throwable $e) {
            $this->command?->warn('Could not calculate member status: '.$e->getMessage());
        }
    }

    /**
     * @param  Collection<int, Member>  $rahulBranch
     * @param  Collection<int, Member>  $reshmaBranch
     */
    private function reportStatuses(Collection $rahulBranch, Collection $reshmaBranch): void
    {
        if (! DB::getSchemaBuilder()->hasTable('member_status_snapshot')) {
            return;
        }

        $rows = DB::table('member_status_snapshot as s')
            ->join('members as m', 'm.id', '=', 's.member_id')
            ->whereIn('s.member_id', $reshmaBranch->pluck('id'))
            ->orderBy('m.id')
            ->get(['m.member_code', 'm.name', 's.status', 's.days_since_activity', 's.last_activity_at']);

        $this->command?->newLine();
        $this->command?->info('Member status — Reshma’s branch (the point of this fixture)');

        foreach ($rows as $row) {
            $this->command?->line(sprintf(
                '  %-9s %-10s %-18s %3d days since %s',
                $row->status,
                $row->member_code,
                $row->name,
                $row->days_since_activity,
                $row->last_activity_at ?? 'joining',
            ));
        }

        $counts = DB::table('member_status_snapshot')
            ->whereIn('member_id', $rahulBranch->pluck('id'))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->command?->newLine();
        $this->command?->info('Member status — Rahul’s branch');

        foreach ($counts as $status => $total) {
            $this->command?->line(sprintf('  %-9s %d members', $status, $total));
        }
    }
}
