<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creation, update and deletion of members.
 *
 * Controllers orchestrate, services own the rules
 * (docs/03_DATABASE_AND_ARCHITECTURE.md).
 */
class MemberService
{
    public function __construct(
        private readonly MemberCodeGenerator $codes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Member
    {
        return DB::transaction(function () use ($data) {
            $allocated = $this->codes->next();

            // member_code and sequence_number are intentionally NOT fillable, so
            // that no form submission can set or alter them. The service assigns
            // them directly instead.
            $member = new Member;
            $member->fill($data);
            $member->member_code = $allocated['member_code'];
            $member->sequence_number = $allocated['sequence_number'];
            $member->save();

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Member $member, array $data): Member
    {
        // The member code is a permanent identifier; it is never reissued or
        // rewritten, including when the configured prefix changes.
        unset($data['member_code'], $data['sequence_number']);

        $sponsorChanged = array_key_exists('sponsor_id', $data)
            && (int) $data['sponsor_id'] !== (int) $member->sponsor_id;

        if ($sponsorChanged && ! $member->canChangeSponsor()) {
            throw new RuntimeException(
                'This member\'s sponsor can no longer be changed because sales have been recorded against them.'
            );
        }

        return DB::transaction(function () use ($member, $data) {
            $member->update($data);

            return $member->refresh();
        });
    }

    /**
     * Soft-delete a member.
     *
     * Blocked while direct referrals remain: removing a member mid-tree would
     * detach an entire branch and silently change every upline chain beneath it.
     * The referrals must be reassigned first, deliberately.
     */
    public function delete(Member $member): void
    {
        if ($member->directReferrals()->exists()) {
            throw new RuntimeException(
                'This member still has direct referrals. Reassign them to another sponsor before deleting.'
            );
        }

        DB::transaction(fn () => $member->delete());
    }

    /**
     * Reasons this member cannot currently be deleted.
     *
     * PHASE 4/5 EXTENSION POINT — add sales and reward-ledger checks here.
     *
     * @return array<int, string>
     */
    public function deletionBlockers(Member $member): array
    {
        $blockers = [];

        $referrals = $member->directReferrals()->count();

        if ($referrals > 0) {
            $blockers[] = $referrals === 1
                ? 'has 1 direct referral'
                : "has {$referrals} direct referrals";
        }

        return $blockers;
    }
}
