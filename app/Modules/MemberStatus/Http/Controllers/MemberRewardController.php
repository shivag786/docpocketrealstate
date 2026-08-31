<?php

namespace App\Modules\MemberStatus\Http\Controllers;

use App\Modules\MemberStatus\Contracts\RewardGateway;
use App\Modules\MemberStatus\Services\PaymentEligibilityService;
use App\Modules\MemberStatus\Services\RewardPanelService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The AJAX endpoints behind the payment panel.
 *
 * Every response uses the application's existing ApiResponse envelope, so the
 * front-end helper in resources/js/app.js handles them with no changes of its
 * own (`{ success, message, data, errors }`).
 *
 * SECURITY (spec §30). Three checks, in this order, on every payment:
 *
 *   1. the id in the URL is a real member, resolved through MemberProvider
 *   2. the reward actually belongs to THAT member — a swapped id cannot pay
 *      somebody else's reward
 *   3. the member is payable, asked of PaymentEligibilityService
 *
 * The third is the same question the button asks when deciding whether to
 * render itself disabled, so a hand-crafted POST is refused exactly as the UI
 * is. A disabled button is a courtesy; this is the rule.
 */
class MemberRewardController
{
    public function __construct(
        private readonly RewardPanelService $panel,
        private readonly RewardGateway $rewards,
        private readonly PaymentEligibilityService $eligibility,
    ) {}

    /**
     * Everything about one member's rewards — what the modal draws itself from.
     */
    public function show(int $member): JsonResponse
    {
        $panel = $this->panel->forMember($member);

        if ($panel === null) {
            return ApiResponse::notFound('That member no longer exists.');
        }

        return ApiResponse::success($panel);
    }

    /**
     * Confirm one reward as paid.
     */
    public function pay(Request $request, int $member, int $reward): JsonResponse
    {
        $panel = $this->panel->forMember($member);

        if ($panel === null) {
            return ApiResponse::notFound('That member no longer exists.');
        }

        if (! $this->rewards->belongsToMember($reward, $member)) {
            return ApiResponse::notFound('That reward does not belong to this member.');
        }

        $eligibility = $this->eligibility->check($member);

        if ($eligibility->blocked()) {
            // 422, not 403: it is the member's state that refuses, not the
            // operator's permission. The front end shows `message` as-is.
            return ApiResponse::error($eligibility->reason, null, 422);
        }

        try {
            $paid = $this->rewards->markPaid($reward, $request->user());
        } catch (RuntimeException $e) {
            // The host application's own refusals — an unfinished month, an
            // already-paid reward — arrive here with admin-readable messages.
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::success(
            $this->panel->forMember($member),
            sprintf('%s reward for %s confirmed as paid — Rs.%s.',
                $paid->typeLabel,
                $paid->period,
                number_format((float) $paid->amount, 2),
            ),
        );
    }

    /**
     * Confirm every unpaid reward this member has.
     *
     * Rewards are paid one at a time through the host's payment service rather
     * than in a bulk update, so each one keeps its own lock and its own audit
     * row. Some may legitimately refuse — a month that has not ended — and the
     * response says how many went through and why the rest did not.
     */
    public function payAll(Request $request, int $member): JsonResponse
    {
        $panel = $this->panel->forMember($member);

        if ($panel === null) {
            return ApiResponse::notFound('That member no longer exists.');
        }

        $eligibility = $this->eligibility->check($member);

        if ($eligibility->blocked()) {
            return ApiResponse::error($eligibility->reason, null, 422);
        }

        $unpaid = $this->rewards->unpaidIdsFor($member);

        if ($unpaid === []) {
            return ApiResponse::error('This member has nothing unpaid.', null, 422);
        }

        $confirmed = 0;
        $refusals = [];

        foreach ($unpaid as $rewardId) {
            try {
                $this->rewards->markPaid($rewardId, $request->user());
                $confirmed++;
            } catch (RuntimeException $e) {
                $refusals[$e->getMessage()] = true;
            }
        }

        if ($confirmed === 0) {
            return ApiResponse::error(
                'Nothing could be confirmed. '.implode(' ', array_keys($refusals)),
                null,
                422,
            );
        }

        $message = sprintf('%d reward%s confirmed as paid.', $confirmed, $confirmed === 1 ? '' : 's');

        if ($refusals !== []) {
            $message = sprintf(
                '%d of %d rewards confirmed. %s',
                $confirmed,
                count($unpaid),
                implode(' ', array_keys($refusals)),
            );
        }

        return ApiResponse::success($this->panel->forMember($member), $message);
    }
}
