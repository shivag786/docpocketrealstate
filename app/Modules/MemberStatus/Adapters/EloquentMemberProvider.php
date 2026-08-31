<?php

namespace App\Modules\MemberStatus\Adapters;

use App\Modules\MemberStatus\Contracts\MemberProvider;
use App\Modules\MemberStatus\Data\MemberRecord;
use App\Modules\MemberStatus\Support\StatusConfig;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MemberProvider backed by the host application's `members` table.
 *
 * READ ONLY. It uses the query builder rather than App\Models\Member on
 * purpose: the module must not inherit the model's scopes, casts, events or
 * future behaviour, and must never be in a position to save one.
 *
 * Which table and columns it reads comes from the schema map in
 * config/member_status.php, so the host application does not have to rename
 * anything to be usable here (spec §12).
 */
class EloquentMemberProvider implements MemberProvider
{
    public function __construct(
        private readonly StatusConfig $config,
    ) {}

    public function find(int|string $memberId): ?MemberRecord
    {
        $row = $this->query()->where($this->schemaColumn('memberId'), $memberId)->first();

        return $row === null ? null : $this->toRecord($row);
    }

    /**
     * @param  list<int|string>  $memberIds
     * @return array<int|string, MemberRecord>
     */
    public function findMany(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $records = [];

        foreach ($this->query()->whereIn($this->schemaColumn('memberId'), $memberIds)->get() as $row) {
            $record = $this->toRecord($row);
            $records[$record->id] = $record;
        }

        return $records;
    }

    /**
     * @param  callable(list<MemberRecord>): void  $callback
     */
    public function chunk(int $chunkSize, callable $callback): void
    {
        $schema = $this->config->schema();

        // chunkById, not chunk: the batch job may run for a long time and an
        // offset-based walk would skip or repeat rows if members are added
        // while it runs.
        $this->query()->orderBy($schema->memberId)->chunkById(
            max(1, $chunkSize),
            function ($rows) use ($callback) {
                $callback(array_map(fn ($row) => $this->toRecord($row), $rows->all()));
            },
            $schema->memberId,
            $schema->memberId,
        );
    }

    public function count(): int
    {
        return (int) $this->query()->count();
    }

    public function sponsorIdOf(int|string $memberId): int|string|null
    {
        $sponsorId = $this->query()
            ->where($this->schemaColumn('memberId'), $memberId)
            ->value($this->schemaColumn('memberSponsor'));

        return $sponsorId === null ? null : (int) $sponsorId;
    }

    /**
     * LEVEL 1 ONLY — a single `where sponsor_id = ?`.
     *
     * There is no recursion here and there must never be. The whole correctness
     * of the module rests on this query not walking the tree (spec §3, §13).
     *
     * @return list<int|string>
     */
    public function directReferralIds(int|string $memberId): array
    {
        return $this->query()
            ->where($this->schemaColumn('memberSponsor'), $memberId)
            ->pluck($this->schemaColumn('memberId'))
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The base query: live members only.
     */
    private function query(): Builder
    {
        $schema = $this->config->schema();

        $query = DB::table($schema->membersTable);

        // Soft-deleted members are not members. Excluding them here means no
        // caller has to remember to.
        if ($schema->memberDeletedAt !== null) {
            $query->whereNull($schema->memberDeletedAt);
        }

        return $query;
    }

    private function schemaColumn(string $property): string
    {
        return $this->config->schema()->{$property};
    }

    private function toRecord(object $row): MemberRecord
    {
        $schema = $this->config->schema();

        return MemberRecord::fromArray([
            'id' => (int) $row->{$schema->memberId},
            'sponsor_id' => $row->{$schema->memberSponsor} ?? null,
            'joined_at' => $row->{$schema->memberJoinedAt},
            'name' => $row->{$schema->memberName} ?? null,
            'code' => $row->{$schema->memberCode} ?? null,
            'mobile' => $row->{$schema->memberMobile} ?? null,
        ]);
    }
}
