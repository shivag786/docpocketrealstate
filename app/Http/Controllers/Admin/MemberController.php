<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MemberStatus;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Models\TeamCalculation;
use App\Support\Money;
use App\Services\DirectRewardService;
use App\Services\MemberService;
use App\Services\MemberTreeService;
use App\Services\UplineRewardService;
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

    public function show(Member $member, MemberTreeService $tree): View
    {
        // sponsor_id is included so any onward chain walk stays intact.
        $member->load('sponsor:id,name,member_code,status,sponsor_id');

        return view('admin.members.show', [
            'member' => $member,
            'uplines' => $member->ancestors(),
            'level' => $tree->levelOf($member),
            'branch' => $tree->branchTotals([$member->id])[$member->id],
            'referrals' => $member->directReferrals()
                ->withCount('directReferrals')
                ->orderBy('sequence_number')
                ->paginate(config('members.per_page'), ['*'], 'referrals')
                ->withQueryString(),
            'deletionBlockers' => $this->members->deletionBlockers($member),
            'directRewards' => app(DirectRewardService::class)->forMember($member),
            'uplineRewards' => app(UplineRewardService::class)->forMember($member),
            // Live performance for the current month. Every engine that produces
            // these exists now, so the panel shows figures instead of the phase
            // that would one day deliver them.
            'performance' => $this->performance($member),
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

    /**
     * This month's figures for one member, straight from the engines.
     *
     * Read from the stored calculations rather than recomputed, so the profile
     * agrees with the reports. Every value may legitimately be zero — a member
     * with no sales this month is a real state, not a missing figure.
     *
     * @return array<string, mixed>
     */
    private function performance(Member $member): array
    {
        $period = now()->format('Y-m');

        $ownSqft = Money::of(
            RegistrySale::query()->approved()->forPeriod($period)->where('member_id', $member->id)->sum('sqft')
        );

        $team = TeamCalculation::query()->forPeriod($period)->where('leader_id', $member->id)->first();
        $target = TargetCalculation::query()->forPeriod($period)->where('member_id', $member->id)->first();

        $rewardFor = fn (RewardType $type) => Money::of(
            RewardLedger::query()
                ->ofType($type)
                ->forPeriod($period)
                ->where('member_id', $member->id)
                ->sum('amount')
        );

        return [
            'period' => $period,
            'own_sqft' => $ownSqft,
            'team_sqft' => Money::of($team?->total_team_sqft ?? 0),
            'target' => $target,
            'target_sqft' => Money::of(config('rewards.targets.1.sqft')),
            'direct' => $rewardFor(RewardType::Direct),
            'upline' => $rewardFor(RewardType::Upline),
            'target_reward' => $rewardFor(RewardType::Target),
            // A member who has already achieved Target 1 is no longer measured
            // against it, so the absence of a verdict is meaningful.
            'graduated' => TargetCalculation::query()
                ->where('member_id', $member->id)
                ->whereNotNull('achieved_level')
                ->exists(),
        ];
    }
}
