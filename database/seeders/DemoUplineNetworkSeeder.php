<?php

namespace Database\Seeders;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\MemberService;
use App\Services\RegistrySaleService;
use Illuminate\Database\Seeder;

/**
 * Demonstration data for understanding the upline rule.
 *
 * Adds a deliberately shaped branch plus sales in two PAST months, so upline can
 * be calculated for a completed period and inspected.
 *
 * Every member created here has a name starting "Demo " and every sale a registry
 * number starting "DEMO-", so this data is easy to identify and remove:
 *
 *   DELETE FROM registry_sales WHERE registry_reference LIKE 'DEMO-%';
 *   DELETE FROM members WHERE name LIKE 'Demo %';
 *
 * (Delete sales before members — the foreign key is restricted on purpose.)
 *
 * Idempotent: running it twice will not duplicate anything.
 */
class DemoUplineNetworkSeeder extends Seeder
{
    /**
     * The branch, top (root) first. Each member sponsors the next.
     *
     * Demo C is INACTIVE on purpose: it demonstrates compression, where the walk
     * skips an inactive member and continues upward to find a replacement.
     */
    private const BRANCH = [
        ['key' => 'A', 'name' => 'Demo A Root', 'mobile' => '7000000001', 'status' => MemberStatus::Active],
        ['key' => 'B', 'name' => 'Demo B', 'mobile' => '7000000002', 'status' => MemberStatus::Active],
        ['key' => 'C', 'name' => 'Demo C (inactive)', 'mobile' => '7000000003', 'status' => MemberStatus::Inactive],
        ['key' => 'D', 'name' => 'Demo D', 'mobile' => '7000000004', 'status' => MemberStatus::Active],
        ['key' => 'E', 'name' => 'Demo E', 'mobile' => '7000000005', 'status' => MemberStatus::Active],
        ['key' => 'F', 'name' => 'Demo F', 'mobile' => '7000000006', 'status' => MemberStatus::Active],
        ['key' => 'G', 'name' => 'Demo G Seller', 'mobile' => '7000000007', 'status' => MemberStatus::Active],
    ];

    /**
     * Sales in two past months.
     *
     * G sits 6 links below the root, so its chain exceeds the 5-upline limit AND
     * contains the inactive C — the most instructive case.
     * E sits mid-tree with only 4 eligible uplines above it, showing the divisor
     * dropping below 5.
     */
    private const SALES = [
        ['member' => 'G', 'period' => '-2 months', 'day' => 12, 'sqft' => '1500.00'],
        ['member' => 'G', 'period' => '-1 month', 'day' => 8, 'sqft' => '1000.00'],
        ['member' => 'G', 'period' => '-1 month', 'day' => 21, 'sqft' => '500.00'],
        ['member' => 'E', 'period' => '-1 month', 'day' => 15, 'sqft' => '2000.00'],
        ['member' => 'F', 'period' => '-2 months', 'day' => 3, 'sqft' => '800.00'],
    ];

    public function run(): void
    {
        $operator = User::query()->orderBy('id')->first();

        if ($operator === null) {
            $this->command?->error('No user exists to attribute the sales to. Run AdminUserSeeder first.');

            return;
        }

        $members = $this->createBranch();
        $this->createSales($members, $operator);

        $this->command?->newLine();
        $this->command?->info('Demo upline branch ready:');

        foreach (self::BRANCH as $spec) {
            $member = $members[$spec['key']];
            $this->command?->line(sprintf(
                '  %-6s %-20s %s',
                $member->member_code,
                $member->name,
                $member->status->label()
            ));
        }
    }

    /**
     * @return array<string, Member>
     */
    private function createBranch(): array
    {
        $service = app(MemberService::class);
        $members = [];
        $sponsor = null;

        foreach (self::BRANCH as $spec) {
            $existing = Member::withTrashed()->firstWhere('mobile', $spec['mobile']);

            if ($existing !== null) {
                $members[$spec['key']] = $existing;
                $sponsor = $existing;

                continue;
            }

            $member = $service->create([
                'name' => $spec['name'],
                'mobile' => $spec['mobile'],
                'email' => null,
                'address' => null,
                'sponsor_id' => $sponsor?->id,
                'joining_date' => now()->subMonths(6)->format('Y-m-d'),
                'status' => $spec['status'],
            ]);

            $members[$spec['key']] = $member;
            $sponsor = $member;
        }

        return $members;
    }

    /**
     * @param  array<string, Member>  $members
     */
    private function createSales(array $members, User $operator): void
    {
        $service = app(RegistrySaleService::class);
        $index = 0;

        foreach (self::SALES as $spec) {
            $index++;
            $reference = 'DEMO-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);

            if (RegistrySale::where('registry_reference', $reference)->exists()) {
                continue;
            }

            $date = now()->modify($spec['period'])->setDate(
                (int) now()->modify($spec['period'])->format('Y'),
                (int) now()->modify($spec['period'])->format('m'),
                $spec['day'],
            );

            $service->record([
                'member_id' => $members[$spec['member']]->id,
                'project_id' => null,
                'property_id' => null,
                'registry_reference' => $reference,
                'registry_date' => $date->format('Y-m-d'),
                'sale_date' => $date->format('Y-m-d'),
                'sqft' => $spec['sqft'],
                'notes' => 'Demonstration data for the upline rule.',
            ], $operator);
        }
    }
}
