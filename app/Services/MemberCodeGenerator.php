<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next member code.
 *
 * Two members must never receive the same code, so the next sequence number is
 * read under a row lock inside the caller's transaction rather than with a
 * plain MAX(). The unique constraints on `member_code` and `sequence_number`
 * are the final backstop; a lost race is retried rather than surfaced.
 *
 * Soft-deleted members keep their sequence number — codes are never reissued.
 */
class MemberCodeGenerator
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @return array{sequence_number: int, member_code: string}
     */
    public function next(): array
    {
        $attempt = 0;

        do {
            $sequence = $this->nextSequenceNumber();
            $code = $this->format($sequence);

            // withTrashed: a soft-deleted member still owns its code.
            $taken = Member::withTrashed()
                ->where('member_code', $code)
                ->orWhere('sequence_number', $sequence)
                ->exists();

            if (! $taken) {
                return ['sequence_number' => $sequence, 'member_code' => $code];
            }
        } while (++$attempt < self::MAX_ATTEMPTS);

        throw new RuntimeException(
            'Could not allocate a unique member code after '.self::MAX_ATTEMPTS.' attempts.'
        );
    }

    /**
     * Format a sequence number using the admin-configured prefix.
     *
     * Note this reads the CURRENT prefix. Codes already issued are never
     * rewritten, so a prefix change applies only to members created afterwards.
     */
    public function format(int $sequence): string
    {
        $prefix = (string) config('members.code.prefix', '');
        $pad = (int) config('members.code.pad', 0);

        $number = $pad > 0
            ? str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT)
            : (string) $sequence;

        return $prefix.$number;
    }

    private function nextSequenceNumber(): int
    {
        $startAt = (int) config('members.code.start_at', 1);

        /** @var Builder $query */
        $query = DB::table('members');

        // lockForUpdate is meaningful only inside a transaction; callers create
        // members within one. Outside a transaction this degrades to a plain
        // read and the uniqueness checks above still protect us.
        $highest = $query
            ->when(
                DB::connection()->getDriverName() !== 'sqlite',
                fn (Builder $q) => $q->lockForUpdate()
            )
            ->max('sequence_number');

        return $highest === null
            ? $startAt
            : max((int) $highest + 1, $startAt);
    }
}
