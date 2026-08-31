<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Wipes every business record so the system can be handed over empty.
 *
 * CLIENT REQUEST (2026-08-31): the client tests online with real data, then
 * resets once before go-live.
 *
 * Two rules govern everything here:
 *
 *  1. The table list is EXPLICIT and ordered children-first, so the delete runs
 *     inside one transaction with foreign keys still enforced. Disabling FK
 *     checks and truncating would be shorter and would also silently succeed
 *     when the schema has grown a table this list does not know about — leaving
 *     orphan rows pointing at members that no longer exist. A missed table here
 *     fails loudly instead, which is the outcome we want.
 *
 *  2. PRESERVES is a hard guard, not documentation. `users` must survive or the
 *     admin locks themselves out of the panel they just wiped, and the settings
 *     tables hold the company's own branding, which is configuration rather
 *     than data. Anything in that list is refused even if it is added to CLEARS
 *     by mistake.
 *
 * Member codes need no special handling: MemberCodeGenerator derives the next
 * sequence from MAX(sequence_number) on an empty `members` table, so numbering
 * restarts at the configured start_at (DPRS101) on its own.
 */
class SystemResetService
{
    /**
     * Every table emptied by a reset, ordered children before parents.
     *
     * @var list<string>
     */
    public const CLEARS = [
        // Company Club: paths and rewards both point at their run.
        'company_club_eligibility_paths',
        'company_club_rewards',
        'company_club_calculation_runs',

        // Rewards and the engines that produced them.
        'reward_ledger',
        'upline_calculations',
        'team_calculations',
        'target_calculations',
        'calculation_runs',

        // Member status module.
        'member_status_history',
        'member_status_snapshot',
        'member_status_activity',

        // The sales themselves, then the network, then the master data.
        'registry_sales',
        'members',
        'properties',
        'projects',
    ];

    /**
     * Tables a reset must never touch, whatever CLEARS says.
     *
     * @var list<string>
     */
    public const PRESERVES = [
        'users',
        'sessions',
        'company_settings',
        'company_club_settings',
        'migrations',
        'password_reset_tokens',
    ];

    /**
     * Empty every business table.
     *
     * @return array<string, int>  table => rows removed, in the order cleared
     */
    public function reset(User $performedBy): array
    {
        $counts = [];

        DB::transaction(function () use (&$counts) {
            foreach (self::CLEARS as $table) {
                if (in_array($table, self::PRESERVES, true)) {
                    // Unreachable unless someone edits CLEARS carelessly. That
                    // is exactly when a guard earns its keep.
                    continue;
                }

                // The member-status module's tables are optional; an install
                // without it should still be resettable.
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $counts[$table] = DB::table($table)->count();
                DB::table($table)->delete();
            }
        });

        // Cosmetic, and deliberately outside the transaction: ALTER TABLE is
        // DDL and would commit it early. If this fails the data is still gone
        // and only the id counters carry on from where they were, which changes
        // nothing the operator can see — member codes restart regardless.
        $this->restartIdentityCounters(array_keys($counts));

        Log::warning('System reset performed. All business data cleared.', [
            'user_id' => $performedBy->id,
            'user_email' => $performedBy->email,
            'rows_removed' => $counts,
        ]);

        return $counts;
    }

    /**
     * What a reset would remove right now, without removing it.
     *
     * Drives the confirmation screen: an operator about to wipe the system is
     * entitled to see exactly how much is about to go.
     *
     * @return array<string, int>
     */
    public function preview(): array
    {
        $counts = [];

        foreach (self::CLEARS as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $tables
     */
    private function restartIdentityCounters(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($tables as $table) {
            try {
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            } catch (\Throwable $e) {
                Log::info('Could not restart the id counter after a reset.', [
                    'table' => $table,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
