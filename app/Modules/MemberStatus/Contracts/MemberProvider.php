<?php

namespace App\Modules\MemberStatus\Contracts;

use App\Modules\MemberStatus\Data\MemberRecord;

/**
 * Where the module gets members and referral relationships from (spec §12-§13).
 *
 * The host application supplies an implementation. The module itself ships one
 * for the existing `members` table (Adapters\EloquentMemberProvider), which
 * READS that table and never writes to it.
 *
 * Only DIRECT relationships appear here. There is deliberately no
 * `descendantsOf()` or `uplineOf()`, because activity travels exactly one level
 * and an interface that could express more would invite a rule that must not
 * exist (spec §3).
 */
interface MemberProvider
{
    /**
     * One member, or null when the id is unknown.
     */
    public function find(int|string $memberId): ?MemberRecord;

    /**
     * Members for the given ids, keyed by id.
     *
     * Bulk on purpose: the batch job resolves a whole chunk in one query
     * rather than one query per member (spec §31).
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, MemberRecord>
     */
    public function findMany(array $memberIds): array;

    /**
     * Every member, in chunks, oldest id first.
     *
     * @param  int  $chunkSize  how many members per callback invocation
     * @param  callable(list<MemberRecord>): void  $callback
     */
    public function chunk(int $chunkSize, callable $callback): void;

    /**
     * How many members the provider would hand to `chunk()`.
     */
    public function count(): int;

    /**
     * The id of the member who personally referred this one, or null for a root.
     */
    public function sponsorIdOf(int|string $memberId): int|string|null;

    /**
     * Ids of the members this one personally referred — level 1 ONLY.
     *
     * For the tree Shiva -> A -> A1, `directReferralIds(Shiva)` returns [A].
     * A1 is not Shiva's direct referral and must never appear here (spec §13).
     *
     * @return list<int|string>
     */
    public function directReferralIds(int|string $memberId): array;
}
