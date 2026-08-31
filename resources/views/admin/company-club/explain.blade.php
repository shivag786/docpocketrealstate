@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', 'Why ' . $member->name . ' was paid — ' . $period)
@section('page-title', 'Why did ' . $member->name . ' receive this?')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.distribution', ['period' => $period]) }}">Distribution</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.members.show', $member) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-person me-1"></i>Member profile
    </a>
@endsection

@section('content')

    @include('admin.company-club._period-filter')

    @if (! $run)
        <div class="alert alert-light border">
            {{ $period }} has not been calculated, so there is nothing to explain yet.
        </div>
    @elseif (! $explanation)
        <div class="alert alert-light border">
            <strong>{{ $member->name }} received no {{ $settings->name() }} reward in {{ $period }}.</strong>
            <div class="small mt-2 mb-0">
                A member qualifies only by sitting within {{ $settings->maxLevels() }} <em>active</em>
                sponsor levels above somebody who sold that month. Common reasons for receiving
                nothing: nobody in their downline sold, the sellers below them were inactive,
                they are themselves inactive, or they sit more than {{ $settings->maxLevels() }}
                active levels above every seller.
            </div>
        </div>
    @else
        {{-- The headline: the formula, filled in. --}}
        <div class="card mb-3 border-primary">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <strong>{{ $member->name }}</strong>
                    <span class="badge text-bg-light border ms-1">{{ $member->member_code }}</span>
                    <span class="badge {{ $member->status->badgeClass() }} ms-1">
                        {{ $member->status->label() }}
                    </span>
                </div>
                <span class="badge text-bg-dark">{{ $run->run_code }}</span>
            </div>

            <div class="card-body">
                <div class="row g-3">
                    @foreach ([
                        [$settings->name() . ' pool', '₹' . Money::inr((string) $run->pool_amount), number_format((float) $run->total_sqft, 2) . ' Sq.Ft. × ₹' . Money::inr((string) $run->rate)],
                        ['Eligible recipients', number_format($run->eligible_count), 'unique members'],
                        ['This reward', '₹' . Money::inr((string) $explanation['reward']->amount), 'an equal share'],
                    ] as [$label, $value, $note])
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">{{ $label }}</div>
                                <div class="h5 mb-1">{{ $value }}</div>
                                <div class="text-muted" style="font-size:.78rem;">{{ $note }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="alert alert-light border mt-3 mb-0 text-center">
                    <span class="text-muted small d-block mb-1">Formula</span>
                    <span class="fs-5">
                        &#8377;{{ Money::inr((string) $run->pool_amount) }}
                        &divide; {{ $run->eligible_count }}
                        = <strong>&#8377;{{ Money::inr((string) $explanation['reward']->amount) }}</strong>
                    </span>
                    <div class="text-muted small mt-1">
                        The share is equal for every recipient. Being closer to the sale, or
                        qualifying through more branches, does not increase it.
                    </div>
                </div>
            </div>
        </div>

        {{-- Every path that qualified them. This is the duplicate rule made
             visible: one payout above, several reasons below. --}}
        <div class="card">
            <div class="card-header bg-white">
                <strong>Eligibility &mdash; {{ $explanation['paths']->count() . ' ' . Str::plural('path', $explanation['paths']->count()) }}</strong>
                @if ($explanation['paths']->count() > 1)
                    <div class="text-muted small mt-1">
                        {{ $member->name }} qualified through {{ $explanation['paths']->count() }}
                        separate selling branches and is still paid <strong>once</strong>. Every
                        path is kept so the reason can be audited.
                    </div>
                @endif
            </div>

            <div class="list-group list-group-flush">
                @foreach ($explanation['paths'] as $path)
                    <div class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <div>
                                <span class="text-muted small">Sale member</span>
                                <span class="badge text-bg-light border">{{ $path->saleMember?->member_code }}</span>
                                <strong>{{ $path->saleMember?->name }}</strong>
                                <span class="text-muted small ms-1">
                                    sold {{ number_format((float) $path->sale_member_sqft, 2) }} Sq.Ft.
                                </span>
                            </div>

                            <div class="text-nowrap">
                                <span class="badge text-bg-primary">Level {{ $path->upline_level }}</span>
                                @if ($path->skippedInactive())
                                    <span class="badge text-bg-warning"
                                          title="Depth {{ $path->chain_depth }} but level {{ $path->upline_level }} — inactive sponsors were skipped.">
                                        {{ $path->chain_depth - $path->upline_level }} inactive skipped
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- The walk, exactly as it stood when the money was
                             calculated. Skipped members are shown, not hidden —
                             they are the reason the level numbering looks the
                             way it does. --}}
                        <div class="d-flex flex-wrap align-items-center gap-1 small">
                            <span class="badge text-bg-secondary">
                                {{ $path->saleMember?->member_code }} (seller)
                            </span>

                            @foreach ($path->path_snapshot ?? [] as $step)
                                @php $stepMember = $explanation['snapshot_members'][$step['id']] ?? null; @endphp

                                <i class="bi bi-arrow-right text-muted"></i>

                                @if ($step['outcome'] === 'eligible')
                                    <span class="badge {{ $step['id'] === $member->id ? 'text-bg-primary' : 'text-bg-success' }}">
                                        {{ $stepMember?->member_code }}
                                        &mdash; Level {{ $step['level'] }}
                                        @if ($step['id'] === $member->id) (this member) @endif
                                    </span>
                                @elseif ($step['outcome'] === 'skipped-inactive')
                                    <span class="badge text-bg-light border text-decoration-line-through"
                                          title="Inactive — skipped, and does not use up a level.">
                                        {{ $stepMember?->member_code }} &mdash; inactive, skipped
                                    </span>
                                @else
                                    <span class="badge text-bg-light border"
                                          title="Beyond the {{ $settings->maxLevels() }} active-level limit.">
                                        {{ $stepMember?->member_code }} &mdash; beyond limit
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="card-footer bg-white small text-muted">
                {{ $settings->name() }} itself is never shown as a level. A member with no sponsor
                sits directly beneath it, and the walk simply stops there.
            </div>
        </div>
    @endif

    {{-- This member's Company Club history across every month. --}}
    @if ($rewards->isNotEmpty())
        <div class="card mt-3">
            <div class="card-header bg-white">
                <strong>{{ $member->name }}'s {{ $settings->name() }} rewards</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th>Run</th>
                            <th class="text-center">Level</th>
                            <th class="text-center">Paths</th>
                            <th class="text-end">Reward</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rewards as $reward)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.company-club.explain', [$member, 'period' => $reward->run->period]) }}">
                                        {{ $reward->run->period }}
                                    </a>
                                </td>
                                <td><span class="badge text-bg-light border">{{ $reward->run->run_code }}</span></td>
                                <td class="text-center">L{{ $reward->best_level }}</td>
                                <td class="text-center">{{ $reward->eligibility_path_count }}</td>
                                <td class="text-end fw-semibold">
                                    &#8377;{{ Money::inr((string) $reward->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
