<?php

namespace App\Modules\MemberStatus\Data;

use Carbon\CarbonImmutable;

/**
 * One reward line as this module shows it.
 *
 * A flat, display-ready shape rather than the host's RewardLedger model: the
 * module's views and JSON never touch an application model, which is what keeps
 * the payment screen swappable and the coupling confined to one adapter.
 */
final class RewardRow
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $typeLabel,
        public readonly string $typeBadgeClass,
        public readonly string $period,
        public readonly string $sqft,
        public readonly string $rate,
        public readonly string $amount,
        public readonly bool $paid,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly ?CarbonImmutable $paidAt = null,
        public readonly ?string $paidBy = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type_label' => $this->typeLabel,
            'type_badge_class' => $this->typeBadgeClass,
            'period' => $this->period,
            'sqft' => $this->sqft,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'amount_formatted' => number_format((float) $this->amount, 2),
            'paid' => $this->paid,
            'status_label' => $this->statusLabel,
            'status_badge_class' => $this->statusBadgeClass,
            'paid_at' => $this->paidAt?->format('d M Y, H:i'),
            'paid_by' => $this->paidBy,
        ];
    }
}
