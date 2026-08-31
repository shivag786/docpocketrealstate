<?php

namespace App\Modules\MemberStatus\Support;

/**
 * Which tables and columns the Eloquent adapters read (spec §12).
 *
 * The engine never sees any of this — it only ever sees MemberRecord and
 * SaleRecord. This map exists so that the two shipped adapters can be pointed
 * at differently named columns from config, instead of the host application
 * having to rename anything to suit the module.
 *
 * Everything named here is READ ONLY. The module issues no INSERT, UPDATE or
 * DELETE against these tables (spec §21, §37).
 */
final class SchemaMap
{
    public function __construct(
        public readonly string $membersTable = 'members',
        public readonly string $memberId = 'id',
        public readonly string $memberSponsor = 'sponsor_id',
        public readonly string $memberJoinedAt = 'joining_date',
        public readonly string $memberName = 'name',
        public readonly string $memberCode = 'member_code',
        public readonly string $memberMobile = 'mobile',
        /** Null when the members table does not use soft deletes. */
        public readonly ?string $memberDeletedAt = 'deleted_at',
        public readonly string $salesTable = 'registry_sales',
        public readonly string $saleId = 'id',
        public readonly string $saleMember = 'member_id',
        public readonly string $saleStatus = 'status',
        public readonly string $saleDate = 'registry_date',
        /** Null when the sales table does not use soft deletes. */
        public readonly ?string $saleDeletedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $members = (array) ($values['members'] ?? []);
        $sales = (array) ($values['sales'] ?? []);

        $default = new self;

        return new self(
            membersTable: (string) ($members['table'] ?? $default->membersTable),
            memberId: (string) ($members['id'] ?? $default->memberId),
            memberSponsor: (string) ($members['sponsor'] ?? $default->memberSponsor),
            memberJoinedAt: (string) ($members['joined_at'] ?? $default->memberJoinedAt),
            memberName: (string) ($members['name'] ?? $default->memberName),
            memberCode: (string) ($members['code'] ?? $default->memberCode),
            memberMobile: (string) ($members['mobile'] ?? $default->memberMobile),
            memberDeletedAt: array_key_exists('deleted_at', $members)
                ? ($members['deleted_at'] === null ? null : (string) $members['deleted_at'])
                : $default->memberDeletedAt,
            salesTable: (string) ($sales['table'] ?? $default->salesTable),
            saleId: (string) ($sales['id'] ?? $default->saleId),
            saleMember: (string) ($sales['member'] ?? $default->saleMember),
            saleStatus: (string) ($sales['status'] ?? $default->saleStatus),
            saleDate: (string) ($sales['date'] ?? $default->saleDate),
            saleDeletedAt: array_key_exists('deleted_at', $sales)
                ? ($sales['deleted_at'] === null ? null : (string) $sales['deleted_at'])
                : $default->saleDeletedAt,
        );
    }
}
