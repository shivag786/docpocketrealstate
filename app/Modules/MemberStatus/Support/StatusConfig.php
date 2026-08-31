<?php

namespace App\Modules\MemberStatus\Support;

use InvalidArgumentException;

/**
 * The module's configuration, read once and validated.
 *
 * Every threshold in the engine comes from an instance of this object, so the
 * numbers 90 and 180 appear in exactly one place — the config file (spec §29).
 *
 * `resolve()` deliberately falls back to the module's own config file when the
 * application config has no `member_status` key. That is what lets the module
 * be constructed, run and tested before its service provider is registered
 * anywhere, which is the whole point of shipping it isolated.
 */
final class StatusConfig
{
    /**
     * @param  int  $activePeriodDays  days of inactivity before ACTIVE -> PENDING
     * @param  int  $pendingPeriodDays  further days before PENDING -> INACTIVE
     * @param  bool  $allowInactiveReactivation  whether new activity can lift INACTIVE
     * @param  bool  $measureNewMembersFromJoiningDate  never treat a member as inactive before they joined
     * @param  int  $newMemberGraceDays  extra days added to the joining date
     * @param  list<string>  $qualifyingSaleStatuses  sale states that count as valid
     * @param  SchemaMap  $schema  which host tables/columns the Eloquent adapters read
     * @param  list<string>  $paymentBlockedStatuses  calculated statuses that refuse payment
     * @param  bool  $blockPaymentWhenUnknown  whether a never-calculated member is blocked
     * @param  int  $chunkSize  members processed per batch
     */
    public function __construct(
        public readonly int $activePeriodDays = 90,
        public readonly int $pendingPeriodDays = 90,
        public readonly bool $allowInactiveReactivation = true,
        public readonly bool $measureNewMembersFromJoiningDate = true,
        public readonly int $newMemberGraceDays = 0,
        public readonly array $qualifyingSaleStatuses = ['approved'],
        public readonly ?SchemaMap $schema = null,
        public readonly array $paymentBlockedStatuses = ['PENDING', 'INACTIVE'],
        public readonly bool $blockPaymentWhenUnknown = false,
        public readonly int $chunkSize = 500,
        public readonly bool $loggingEnabled = true,
        public readonly ?string $logChannel = null,
    ) {
        if ($this->activePeriodDays < 1 || $this->pendingPeriodDays < 1) {
            throw new InvalidArgumentException(
                'Member status periods must be at least one day.'
            );
        }

        if ($this->qualifyingSaleStatuses === []) {
            throw new InvalidArgumentException(
                'At least one sale status must qualify, otherwise no member could ever be active.'
            );
        }

        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('The batch chunk size must be at least one.');
        }
    }

    /**
     * The host schema map, defaulted so callers never have to null-check it.
     */
    public function schema(): SchemaMap
    {
        return $this->schema ?? new SchemaMap;
    }

    /**
     * The day count at which a member becomes INACTIVE.
     *
     * Derived, never configured: a separate INACTIVE_THRESHOLD_DAYS setting
     * could be set to something that contradicts the two periods above it.
     */
    public function inactiveThresholdDays(): int
    {
        return $this->activePeriodDays + $this->pendingPeriodDays;
    }

    /**
     * Build from the application config, falling back to the module's own file.
     */
    public static function resolve(): self
    {
        $values = [];

        if (function_exists('config')) {
            /** @var array<string, mixed> $values */
            $values = (array) config('member_status', []);
        }

        if ($values === []) {
            /** @var array<string, mixed> $values */
            $values = require __DIR__.'/../Config/member_status.php';
        }

        return self::fromArray($values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $sales = (array) ($values['sales'] ?? []);
        $newMember = (array) ($values['new_member'] ?? []);
        $batch = (array) ($values['batch'] ?? []);
        $logging = (array) ($values['logging'] ?? []);
        $payment = (array) ($values['payment'] ?? []);

        return new self(
            activePeriodDays: (int) ($values['active_period_days'] ?? 90),
            pendingPeriodDays: (int) ($values['pending_period_days'] ?? 90),
            allowInactiveReactivation: (bool) ($values['allow_inactive_reactivation'] ?? true),
            measureNewMembersFromJoiningDate: (bool) ($newMember['measure_from_joining_date'] ?? true),
            newMemberGraceDays: (int) ($newMember['grace_days'] ?? 0),
            qualifyingSaleStatuses: array_values(array_map(
                'strval',
                (array) ($sales['qualifying_statuses'] ?? ['approved'])
            )),
            schema: SchemaMap::fromArray((array) ($values['schema'] ?? [])),
            paymentBlockedStatuses: array_values(array_map(
                'strval',
                (array) ($payment['blocked_statuses'] ?? ['PENDING', 'INACTIVE'])
            )),
            blockPaymentWhenUnknown: (bool) ($payment['block_when_unknown'] ?? false),
            chunkSize: (int) ($batch['chunk_size'] ?? 500),
            loggingEnabled: (bool) ($logging['enabled'] ?? true),
            logChannel: ($logging['channel'] ?? null) === null ? null : (string) $logging['channel'],
        );
    }

    /**
     * A copy with individual values replaced — used by tests and by the
     * command's overrides, so nothing has to mutate global config.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            activePeriodDays: (int) ($overrides['activePeriodDays'] ?? $this->activePeriodDays),
            pendingPeriodDays: (int) ($overrides['pendingPeriodDays'] ?? $this->pendingPeriodDays),
            allowInactiveReactivation: (bool) ($overrides['allowInactiveReactivation'] ?? $this->allowInactiveReactivation),
            measureNewMembersFromJoiningDate: (bool) ($overrides['measureNewMembersFromJoiningDate'] ?? $this->measureNewMembersFromJoiningDate),
            newMemberGraceDays: (int) ($overrides['newMemberGraceDays'] ?? $this->newMemberGraceDays),
            qualifyingSaleStatuses: (array) ($overrides['qualifyingSaleStatuses'] ?? $this->qualifyingSaleStatuses),
            schema: $overrides['schema'] ?? $this->schema,
            paymentBlockedStatuses: (array) ($overrides['paymentBlockedStatuses'] ?? $this->paymentBlockedStatuses),
            blockPaymentWhenUnknown: (bool) ($overrides['blockPaymentWhenUnknown'] ?? $this->blockPaymentWhenUnknown),
            chunkSize: (int) ($overrides['chunkSize'] ?? $this->chunkSize),
            loggingEnabled: (bool) ($overrides['loggingEnabled'] ?? $this->loggingEnabled),
            logChannel: array_key_exists('logChannel', $overrides)
                ? ($overrides['logChannel'] === null ? null : (string) $overrides['logChannel'])
                : $this->logChannel,
        );
    }
}
