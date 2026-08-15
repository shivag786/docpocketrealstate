<?php

namespace App\Rules;

use App\Models\Member;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects sponsor assignments that would corrupt the network.
 *
 * docs/06_TESTING_AND_ACCEPTANCE.md requires:
 *   - self-sponsorship blocked
 *   - circular relationships blocked
 *   - a valid sponsor accepted
 *
 * A null sponsor is valid: root members are allowed and multiple independent
 * trees may exist.
 */
class ValidSponsor implements ValidationRule
{
    public function __construct(
        private readonly ?Member $member = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // root member
        }

        $sponsor = Member::find($value);

        if ($sponsor === null) {
            $fail('The selected sponsor does not exist.');

            return;
        }

        // Creating a new member: nothing below it yet, so existence is enough.
        if ($this->member === null) {
            return;
        }

        if ($sponsor->id === $this->member->id) {
            $fail('A member cannot be their own sponsor.');

            return;
        }

        // Circular: the proposed sponsor sits somewhere beneath this member, so
        // linking them would close a loop and make the upline walk non-terminating.
        if (in_array($sponsor->id, $this->member->descendantIds(), true)) {
            $fail(sprintf(
                'This would create a circular relationship: %s (%s) is already in %s\'s downline.',
                $sponsor->name,
                $sponsor->member_code,
                $this->member->name,
            ));
        }
    }
}
