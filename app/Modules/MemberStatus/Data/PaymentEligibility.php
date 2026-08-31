<?php

namespace App\Modules\MemberStatus\Data;

use App\Modules\MemberStatus\Enums\CalculatedStatus;

/**
 * Whether a member may be paid, and — when they may not — why.
 *
 * The reason travels with the decision on purpose. A disabled button that
 * cannot say what is wrong sends the admin hunting; this carries the sentence
 * the tooltip, the modal and the API error all show.
 */
final class PaymentEligibility
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?CalculatedStatus $status,
        public readonly ?string $reason = null,
        public readonly ?int $daysSinceActivity = null,
    ) {}

    public static function allow(?CalculatedStatus $status, ?int $daysSinceActivity = null): self
    {
        return new self(true, $status, null, $daysSinceActivity);
    }

    public static function block(?CalculatedStatus $status, string $reason, ?int $daysSinceActivity = null): self
    {
        return new self(false, $status, $reason, $daysSinceActivity);
    }

    public function blocked(): bool
    {
        return ! $this->allowed;
    }
}
