<?php

namespace App\Modules\MemberStatus\Data;

use Carbon\CarbonImmutable;

/**
 * One valid property sale, reduced to what the module needs (spec §14).
 *
 * A SaleRecord that reaches this module has ALREADY been judged valid by the
 * PropertySaleProvider. Nothing downstream re-checks a sale's state, which is
 * why the provider is the single place where "valid sale" is defined.
 */
final class SaleRecord
{
    public function __construct(
        public readonly int|string $id,
        public readonly int|string $memberId,
        public readonly CarbonImmutable $soldAt,
    ) {}
}
