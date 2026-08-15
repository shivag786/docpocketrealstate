<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AJAX sponsor lookup for the member form.
 *
 * Returns a short, ranked list rather than the whole network — the member table
 * is expected to grow large and must never be dumped into a select element
 * (docs/04_UI_UX_SPECIFICATION.md).
 */
class SponsorSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'integer'],
        ]);

        $term = trim($validated['q'] ?? '');

        if (mb_strlen($term) < 2) {
            return ApiResponse::success([], 'Type at least 2 characters to search.');
        }

        $query = Member::query()
            ->search($term)
            ->select(['id', 'member_code', 'name', 'mobile', 'status', 'sponsor_id']);

        // When editing, a member may not become their own sponsor, nor may any
        // member from their own downline. Excluding them here keeps invalid
        // options off the screen; ValidSponsor still enforces it server-side.
        if (! empty($validated['exclude'])) {
            $member = Member::find($validated['exclude']);

            if ($member !== null) {
                $query->whereNotIn('id', [$member->id, ...$member->descendantIds()]);
            }
        }

        $results = $query
            ->orderBy('member_code')
            ->limit(config('members.search_limit'))
            ->get()
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'member_code' => $member->member_code,
                'name' => $member->name,
                'mobile' => $member->mobile,
                'status' => $member->status->value,
                'status_label' => $member->status->label(),
            ]);

        return ApiResponse::success($results);
    }
}
