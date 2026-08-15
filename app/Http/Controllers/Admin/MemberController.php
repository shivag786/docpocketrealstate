<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Models\Member;
use App\Services\MemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MemberController extends Controller
{
    public function __construct(
        private readonly MemberService $members,
    ) {}

    public function index(Request $request): View
    {
        $members = Member::query()
            ->with('sponsor:id,name,member_code')
            ->withCount('directReferrals')
            ->search($request->query('q'))
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->query('status'))
            )
            ->when(
                $request->query('sponsor') === 'root',
                fn ($query) => $query->roots()
            )
            ->latest('id')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        return view('admin.members.index', [
            'members' => $members,
            'statuses' => MemberStatus::options(),
            'filters' => $request->only(['q', 'status', 'sponsor']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.members.create', [
            'statuses' => MemberStatus::options(),
            'sponsor' => $request->filled('sponsor_id')
                ? Member::find($request->query('sponsor_id'))
                : null,
        ]);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = $this->members->create($request->validated());

        return redirect()
            ->route('admin.members.show', $member)
            ->with('success', "Member {$member->member_code} ({$member->name}) was created.");
    }

    public function show(Member $member): View
    {
        $member->load('sponsor:id,name,member_code,status');

        return view('admin.members.show', [
            'member' => $member,
            'uplines' => $member->ancestors(),
            'referrals' => $member->directReferrals()
                ->withCount('directReferrals')
                ->orderBy('member_code')
                ->paginate(config('members.per_page'), ['*'], 'referrals')
                ->withQueryString(),
            'deletionBlockers' => $this->members->deletionBlockers($member),
        ]);
    }

    public function edit(Member $member): View
    {
        $member->load('sponsor:id,name,member_code');

        return view('admin.members.edit', [
            'member' => $member,
            'statuses' => MemberStatus::options(),
            'canChangeSponsor' => $member->canChangeSponsor(),
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        try {
            $this->members->update($member, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.members.show', $member)
            ->with('success', "Member {$member->member_code} was updated.");
    }

    public function destroy(Member $member): RedirectResponse
    {
        try {
            $this->members->delete($member);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.members.index')
            ->with('success', "Member {$member->member_code} was deleted.");
    }
}
