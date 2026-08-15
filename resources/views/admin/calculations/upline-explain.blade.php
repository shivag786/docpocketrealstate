@extends('layouts.admin')

@section('title', 'Upline Explorer — ' . $member->member_code)
@section('page-title', 'Upline Explorer')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.calculations.upline.ledger') }}">Upline Rewards</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.members.show', $member) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-person-lines-fill me-1"></i>Member profile
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="period" class="form-label small mb-1">Period</label>
                    <input type="month" id="period" name="period" value="{{ $period }}"
                           max="{{ now()->format('Y-m') }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-funnel me-1"></i>Show period
                    </button>
                </div>
                @if ($periods->isNotEmpty())
                    <div class="col-12 col-md-5 text-md-end">
                        <span class="small text-muted me-2">Calculated periods:</span>
                        @foreach ($periods as $available)
                            <a href="{{ route('admin.calculations.upline.explain', [$member, 'period' => $available]) }}"
                               class="badge text-decoration-none {{ $available === $period ? 'text-bg-primary' : 'text-bg-light border' }}">
                                {{ $available }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </form>
        </div>
    </div>

    {{-- ============================================================
         1. The rule, stated once in plain terms.
    ============================================================= --}}
    <div class="card mb-3 border-primary-subtle">
        <div class="card-body">
            <h2 class="h6 mb-2"><i class="bi bi-info-circle me-1"></i>How an upline reward is produced</h2>
            <div class="upline-rule">
                <span class="upline-rule-step">
                    <strong>A member sells</strong>
                    <span class="text-muted d-block small">their own Sq.Ft. for the month</span>
                </span>
                <i class="bi bi-arrow-right upline-rule-arrow"></i>
                <span class="upline-rule-step">
                    <strong>Pool = Sq.Ft. &times; ₹{{ config('rewards.rates.upline') }}</strong>
                    <span class="text-muted d-block small">from the SELLER's Sq.Ft.</span>
                </span>
                <i class="bi bi-arrow-right upline-rule-arrow"></i>
                <span class="upline-rule-step">
                    <strong>Walk up the sponsors</strong>
                    <span class="text-muted d-block small">skip inactive, take up to {{ config('rewards.upline.max_levels') }} active</span>
                </span>
                <i class="bi bi-arrow-right upline-rule-arrow"></i>
                <span class="upline-rule-step">
                    <strong>Split equally</strong>
                    <span class="text-muted d-block small">pool ÷ eligible count</span>
                </span>
            </div>
        </div>
    </div>

    {{-- ============================================================
         2. Root → member path, exactly as requested.
    ============================================================= --}}
    <div class="card mb-3">
        <div class="card-header bg-white">
            <strong>Position in the network</strong>
            <span class="small text-muted">— from the root down to {{ $member->member_code }}</span>
        </div>
        <div class="card-body">
            <ol class="hierarchy">
                @foreach ($path as $ancestor)
                    <li class="hierarchy-item">
                        <span class="hierarchy-rail"></span>
                        <span class="hierarchy-node {{ $ancestor->isActive() ? '' : 'is-inactive' }}">
                            <span class="hierarchy-level">L{{ $loop->index }}</span>
                            <a href="{{ route('admin.calculations.upline.explain', [$ancestor, 'period' => $period]) }}"
                               class="fw-semibold text-decoration-none">{{ $ancestor->member_code }}</a>
                            <span class="ms-2">{{ $ancestor->name }}</span>
                            @unless ($ancestor->isActive())
                                <span class="badge text-bg-secondary ms-2">Inactive</span>
                            @endunless
                            @if ($loop->first)
                                <span class="badge text-bg-light border ms-2">Root</span>
                            @endif
                        </span>
                    </li>
                @endforeach

                <li class="hierarchy-item">
                    <span class="hierarchy-rail"></span>
                    <span class="hierarchy-node is-current">
                        <span class="hierarchy-level">L{{ $path->count() }}</span>
                        <span class="fw-semibold">{{ $member->member_code }}</span>
                        <span class="ms-2">{{ $member->name }}</span>
                        <span class="badge text-bg-primary ms-2">This member</span>
                    </span>
                </li>
            </ol>

            @if ($path->isEmpty())
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This is a root member — nobody sits above them, so their own sales
                    produce no upline reward for anyone.
                </p>
            @endif
        </div>
    </div>

    {{-- ============================================================
         3. Who gets paid when THIS member sells, and why.
    ============================================================= --}}
    <div class="card mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>When {{ $member->member_code }} sells &mdash; who receives</strong>
            <span class="small text-muted">the chain above, annotated</span>
        </div>

        <div class="card-body">
            @if (empty($chain))
                <p class="text-muted mb-0">
                    No sponsor above this member, so a sale by them pays no upline reward.
                </p>
            @else
                <div class="row g-2 mb-3">
                    @foreach ([
                        ['Own Sq.Ft. in ' . $period, number_format((float) $distribution['sqft'], 2)],
                        ['Pool generated', '₹' . number_format((float) $distribution['pool'], 2)],
                        ['Eligible uplines', $distribution['count'] ?: collect($chain)->where('eligible', true)->count()],
                        ['Each receives', '₹' . number_format((float) $distribution['share'], 2)],
                    ] as [$label, $value])
                        <div class="col-6 col-lg-3">
                            <div class="border rounded p-2">
                                <div class="stat-label">{{ $label }}</div>
                                <div class="fw-semibold">{{ $value }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sponsor depth</th>
                                <th>Member</th>
                                <th>Status</th>
                                <th>Upline level</th>
                                <th>Why</th>
                                <th class="text-end">Share in {{ $period }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($chain as $link)
                                <tr class="{{ $link['eligible'] ? '' : 'table-light text-muted' }}">
                                    <td><span class="badge text-bg-light border">+{{ $link['depth'] }}</span></td>
                                    <td>
                                        <a href="{{ route('admin.calculations.upline.explain', [$link['member'], 'period' => $period]) }}"
                                           class="fw-semibold text-decoration-none">
                                            {{ $link['member']->member_code }}
                                        </a>
                                        <div class="small text-muted">{{ $link['member']->name }}</div>
                                    </td>
                                    <td>
                                        <span class="badge {{ $link['member']->status->badgeClass() }}">
                                            {{ $link['member']->status->label() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($link['eligible'])
                                            <span class="badge text-bg-primary">L{{ $link['level'] }}</span>
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($link['eligible'])
                                            <i class="bi bi-check-circle text-success me-1"></i>
                                        @elseif (! $link['active'])
                                            <i class="bi bi-skip-forward text-warning me-1"></i>
                                        @else
                                            <i class="bi bi-x-circle text-muted me-1"></i>
                                        @endif
                                        {{ $link['reason'] }}
                                    </td>
                                    <td class="text-end fw-semibold">
                                        @php
                                            $paid = $distribution['rows']->firstWhere('receiver_id', $link['member']->id);
                                        @endphp
                                        {{ $paid ? '₹' . number_format((float) $paid->receiver_amount, 2) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($distribution['count'] === 0)
                    <p class="small text-muted mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Nothing was paid in {{ $period }} — either this member recorded no
                        sales that month, or upline has not been calculated for it yet.
                    </p>
                @endif
            @endif
        </div>
    </div>

    {{-- ============================================================
         4. What this member RECEIVED, and from whom.
    ============================================================= --}}
    <div class="card">
        <div class="card-header bg-white">
            <strong>What {{ $member->member_code }} received in {{ $period }}</strong>
            <span class="small text-muted">— pools created by their downline sellers</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Seller (downline)</th>
                        <th class="text-end">Seller Sq.Ft.</th>
                        <th class="text-end">Pool</th>
                        <th class="text-center">Split between</th>
                        <th class="text-center">Level held</th>
                        <th class="text-end">Received</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipts as $receipt)
                        <tr>
                            <td>
                                <a href="{{ route('admin.calculations.upline.explain', [$receipt->seller_id, 'period' => $period]) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $receipt->seller->member_code }}
                                </a>
                                <div class="small text-muted">{{ $receipt->seller->name }}</div>
                            </td>
                            <td class="text-end">{{ number_format((float) $receipt->seller_sqft, 2) }}</td>
                            <td class="text-end">
                                ₹{{ number_format((float) $receipt->pool_amount, 2) }}
                                <div class="small text-muted">
                                    {{ number_format((float) $receipt->seller_sqft, 2) }} &times; ₹{{ (int) $receipt->pool_rate }}
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge text-bg-secondary">{{ $receipt->eligible_upline_count }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge text-bg-light border">L{{ $receipt->upline_level }}</span>
                                @if ($receipt->wasCompressed())
                                    <i class="bi bi-arrow-up-short text-warning"
                                       title="Inactive uplines were skipped — actually {{ $receipt->chain_depth }} levels above the seller"></i>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">₹{{ number_format((float) $receipt->receiver_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Received nothing in {{ $period }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($receipts->isNotEmpty())
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5">Total received</th>
                            <th class="text-end">
                                ₹{{ number_format((float) $receipts->sum('receiver_amount'), 2) }}
                            </th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
@endsection
