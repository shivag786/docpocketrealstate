<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberTreeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sponsor tree navigation.
 *
 * Every endpoint returns one level, or one page, at a time. Nothing here can
 * return the whole network in a single response.
 */
class TreeController extends Controller
{
    public function __construct(
        private readonly MemberTreeService $tree,
    ) {}

    /**
     * The tree page. Renders an empty shell; nodes arrive over AJAX.
     */
    public function index(Request $request): View
    {
        $focus = $request->filled('member')
            ? Member::find($request->query('member'))
            : null;

        return view('admin.tree.index', [
            'focus' => $focus,
            'focusPath' => $focus ? [...$this->tree->pathToRoot($focus), $focus->id] : [],
            'rootCount' => Member::roots()->count(),
            'memberCount' => Member::count(),
        ]);
    }

    /**
     * One level of the tree.
     *
     * `member_id` absent  -> the roots
     * `member_id` present -> that member's direct referrals
     */
    public function children(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'level' => ['nullable', 'integer', 'min:0'],
        ]);

        if (empty($validated['member_id'])) {
            $members = $this->tree->roots();
            $level = 0;
        } else {
            $parent = Member::findOrFail($validated['member_id']);
            $members = $this->tree->children($parent);
            $level = ($validated['level'] ?? $this->tree->levelOf($parent)) + 1;
        }

        return ApiResponse::success([
            'level' => $level,
            'nodes' => $members->map(fn (Member $m) => $this->tree->toNode($m, $level))->all(),
        ]);
    }

    /**
     * Locate a member and return the ancestor ids the UI must expand to reveal them.
     */
    public function focus(Member $member): JsonResponse
    {
        return ApiResponse::success([
            'member' => $this->tree->toNode(
                $member->loadCount('directReferrals'),
                $this->tree->levelOf($member)
            ),
            'path' => $this->tree->pathToRoot($member),
        ]);
    }

    /**
     * Tree search — returns matches with their level so staff can jump to one.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);

        $term = trim($validated['q']);

        if (mb_strlen($term) < 2) {
            return ApiResponse::success([], 'Type at least 2 characters to search.');
        }

        return ApiResponse::success(
            $this->tree->search($term, config('members.search_limit'))->all()
        );
    }

    /**
     * "View Full Downline" — every descendant as a paginated flat list.
     */
    public function downline(Request $request, Member $member): View
    {
        $validated = $request->validate([
            'max_level' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $downline = $this->tree->downline(
            $member,
            perPage: (int) config('members.per_page'),
            page: (int) ($validated['page'] ?? 1),
            maxLevel: isset($validated['max_level']) ? (int) $validated['max_level'] : null,
        )->withPath(route('admin.tree.downline', $member));

        return view('admin.tree.downline', [
            'member' => $member,
            'downline' => $downline,
            'maxLevel' => $validated['max_level'] ?? null,
            'totals' => $this->tree->branchTotals([$member->id])[$member->id],
        ]);
    }
}
