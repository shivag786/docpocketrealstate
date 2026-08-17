@extends('layouts.admin')

@section('title', 'Calculation Center')
@section('page-title', 'Calculation Center')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Calculations</li>
@endsection

@section('content')
    {{-- Period selection is required before any engine can run
         (docs/04_UI_UX_SPECIFICATION.md). --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="period" class="form-label small mb-1 required-mark">Period</label>
                    <input type="month" id="period" name="period" value="{{ $period }}"
                           max="{{ now()->format('Y-m') }}"
                           class="form-control form-control-sm" required>
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-repeat me-1"></i>Load period
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($previewError)
        <div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-octagon mt-1"></i>
            <div>{{ $previewError }}</div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Direct — the only engine wired in Phase 5 --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Calculate Direct</strong>
                    <span class="badge text-bg-primary">₹{{ config('rewards.rates.direct') }} / Sq.Ft.</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Own approved sale Sq.Ft. &times; ₹{{ config('rewards.rates.direct') }}.
                        Target achievement does not affect this reward.
                    </p>

                    @if ($preview)
                        <div class="row g-2 mb-3">
                            @foreach ([
                                ['Approved sales', number_format($preview['sales'])],
                                ['Members', number_format($preview['members'])],
                                ['Total Sq.Ft.', number_format((float) $preview['sqft'], 2)],
                                ['Direct reward', '₹' . number_format((float) $preview['amount'], 2)],
                            ] as [$label, $value])
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <div class="stat-label">{{ $label }}</div>
                                        <div class="fw-semibold">{{ $value }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($directRun)
                        <div class="alert alert-success small d-flex gap-2 mb-3">
                            <i class="bi bi-check-circle mt-1"></i>
                            <div>
                                Already calculated for {{ $period }} —
                                <a href="{{ route('admin.calculations.show', $directRun) }}">run #{{ $directRun->id }}</a>
                                on {{ $directRun->completed_at?->format('d M Y, H:i') }}.
                                Recalculation is not available until Phase 12.
                            </div>
                        </div>

                        <a href="{{ route('admin.calculations.direct.ledger', ['period' => $period]) }}"
                           class="btn btn-outline-primary">
                            <i class="bi bi-journal-text me-1"></i>View direct reward ledger
                        </a>
                    @else
                        <form method="POST" action="{{ route('admin.calculations.direct') }}">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <button type="submit" class="btn btn-primary"
                                    @disabled(! $preview || $preview['sales'] === 0)
                                    data-confirm-submit="Calculate direct rewards for {{ $period }}?">
                                <i class="bi bi-calculator me-1"></i>Calculate Direct for {{ $period }}
                            </button>
                        </form>

                        @if ($preview && $preview['sales'] === 0)
                            <div class="form-text">No approved sales in this period.</div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Upline --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Calculate Upline</strong>
                    <span class="badge text-bg-info">₹{{ config('rewards.rates.upline') }} pool</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Seller's monthly own Sq.Ft. &times; ₹{{ config('rewards.rates.upline') }},
                        divided equally among up to {{ config('rewards.upline.max_levels') }}
                        active uplines. Inactive members are skipped. Target achievement does
                        not affect this reward.
                    </p>

                    @if ($uplinePreview)
                        <div class="row g-2 mb-3">
                            @foreach ([
                                ['Sellers', number_format($uplinePreview['sellers'])],
                                ['With uplines', number_format($uplinePreview['distributing'])],
                                ['Total pool', '₹' . number_format((float) $uplinePreview['pool'], 2)],
                                ['Shares to pay', number_format($uplinePreview['receipts'])],
                            ] as [$label, $value])
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <div class="stat-label">{{ $label }}</div>
                                        <div class="fw-semibold">{{ $value }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ((float) $uplinePreview['residual'] !== 0.0)
                            <div class="alert alert-warning small py-2 d-flex gap-2">
                                <i class="bi bi-info-circle mt-1"></i>
                                <div>
                                    Rounding residual
                                    <strong>₹{{ number_format((float) $uplinePreview['residual'], 2) }}</strong>
                                    — shares are rounded off individually, so the distributed
                                    total differs from the pool by this much.
                                </div>
                            </div>
                        @endif
                    @endif

                    @if ($uplineRun)
                        <div class="alert alert-success small d-flex gap-2 mb-3">
                            <i class="bi bi-check-circle mt-1"></i>
                            <div>
                                Already calculated for {{ $period }} —
                                <a href="{{ route('admin.calculations.show', $uplineRun) }}">run #{{ $uplineRun->id }}</a>.
                            </div>
                        </div>

                        <a href="{{ route('admin.calculations.upline.ledger', ['period' => $period]) }}"
                           class="btn btn-outline-primary">
                            <i class="bi bi-journal-text me-1"></i>View upline reward ledger
                        </a>
                    @else
                        <form method="POST" action="{{ route('admin.calculations.upline') }}">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <button type="submit" class="btn btn-primary"
                                    @disabled(! $uplinePreview || $uplinePreview['distributing'] === 0)
                                    data-confirm-submit="Calculate upline rewards for {{ $period }}?">
                                <i class="bi bi-arrow-up-circle me-1"></i>Calculate Upline for {{ $period }}
                            </button>
                        </form>

                        @if ($uplinePreview && $uplinePreview['distributing'] === 0)
                            <div class="form-text">
                                No seller in this period has an eligible upline, so there is
                                nothing to distribute.
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Team sales: a measurement, not a reward --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Calculate Team Sales</strong>
                    <span class="badge text-bg-secondary">no payout</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Each leader's own approved Sq.Ft. plus every connected downline's,
                        at any depth. This produces no reward — it is the figure the Target
                        engine will measure against 5,000 / 10,000 / 35,000 Sq.Ft.
                    </p>

                    @if ($teamPreview)
                        <div class="row g-2 mb-3">
                            @foreach ([
                                ['Leaders with team sales', number_format($teamPreview['leaders'])],
                                ['Company Sq.Ft.', number_format((float) $teamPreview['company_sqft'], 2)],
                                ['Largest team', number_format((float) $teamPreview['largest_team'], 2)],
                            ] as [$label, $value])
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <div class="stat-label">{{ $label }}</div>
                                        <div class="fw-semibold">{{ $value }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($teamRun)
                        <div class="alert alert-success small d-flex gap-2 mb-3">
                            <i class="bi bi-check-circle mt-1"></i>
                            <div>
                                Already calculated for {{ $period }} —
                                <a href="{{ route('admin.calculations.show', $teamRun) }}">run #{{ $teamRun->id }}</a>.
                            </div>
                        </div>

                        <a href="{{ route('admin.calculations.team.report', ['period' => $period]) }}"
                           class="btn btn-outline-primary">
                            <i class="bi bi-people me-1"></i>View team sales report
                        </a>
                    @else
                        <form method="POST" action="{{ route('admin.calculations.team') }}">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <button type="submit" class="btn btn-primary"
                                    @disabled(! $teamPreview || $teamPreview['leaders'] === 0)
                                    data-confirm-submit="Calculate team sales for {{ $period }}?">
                                <i class="bi bi-people me-1"></i>Calculate Team Sales for {{ $period }}
                            </button>
                        </form>

                        @if ($teamPreview && $teamPreview['leaders'] === 0)
                            <div class="form-text">No approved sales in this period.</div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Target 1 — one calendar month --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Calculate One Month Target</strong>
                    <span class="badge text-bg-warning">₹{{ config('rewards.rates.target') }} / Sq.Ft.</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Each member's team Sq.Ft. tested against
                        {{ number_format((float) ($targetPreview['target_sqft'] ?? 0), 0) }} Sq.Ft.
                        for the month. The reward is fixed at the threshold, so a team doing more
                        is still paid on {{ number_format((float) ($targetPreview['target_sqft'] ?? 0), 0) }}.
                        Achieved once per member, then they move to the Two Month Target.
                    </p>

                    @if ($targetPreview)
                        <div class="row g-2 mb-3">
                            @foreach ([
                                ['Members measured', number_format($targetPreview['measured'])],
                                ['Would achieve', number_format($targetPreview['achieved'])],
                                ['Would fall short', number_format($targetPreview['missed'])],
                                ['Reward to pay', '₹' . number_format((float) $targetPreview['total_amount'], 2)],
                            ] as [$label, $value])
                                <div class="col-6">
                                    <div class="border rounded p-2">
                                        <div class="stat-label">{{ $label }}</div>
                                        <div class="fw-semibold">{{ $value }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($targetPreview['graduated'] > 0)
                            <div class="alert alert-secondary small py-2 d-flex gap-2">
                                <i class="bi bi-mortarboard mt-1"></i>
                                <div>
                                    <strong>{{ $targetPreview['graduated'] }}</strong>
                                    {{ Str::plural('member', $targetPreview['graduated']) }} already
                                    achieved this target and {{ $targetPreview['graduated'] === 1 ? 'is' : 'are' }}
                                    no longer measured against it.
                                </div>
                            </div>
                        @endif

                        @unless ($targetPreview['team_sales_ready'])
                            <div class="alert alert-warning small py-2 d-flex gap-2">
                                <i class="bi bi-exclamation-triangle mt-1"></i>
                                <div>
                                    Team Sales has not been calculated for {{ $period }}. Targets are
                                    judged on the figures that run produces, so run it first.
                                </div>
                            </div>
                        @endunless
                    @endif

                    @if ($targetRun)
                        <div class="alert alert-success small d-flex gap-2 mb-3">
                            <i class="bi bi-check-circle mt-1"></i>
                            <div>
                                Already calculated for {{ $period }} —
                                <a href="{{ route('admin.calculations.show', $targetRun) }}">run #{{ $targetRun->id }}</a>.
                            </div>
                        </div>

                        <a href="{{ route('admin.targets.achieved', ['period' => $period]) }}"
                           class="btn btn-outline-primary">
                            <i class="bi bi-bullseye me-1"></i>View target results
                        </a>
                    @else
                        <form method="POST" action="{{ route('admin.targets.run') }}">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <button type="submit" class="btn btn-primary"
                                    @disabled(! $targetPreview || ! $targetPreview['team_sales_ready'] || $targetPreview['measured'] === 0)
                                    data-confirm-submit="Calculate the one month target for {{ $period }}? Members who achieve it are paid once and move to the Two Month Target.">
                                <i class="bi bi-bullseye me-1"></i>Calculate Target for {{ $period }}
                            </button>
                        </form>

                        @if ($targetPreview && $targetPreview['team_sales_ready'] && $targetPreview['measured'] === 0)
                            <div class="form-text">
                                No member is being measured this period — either there were no
                                sales, or everyone with sales has already achieved this target.
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- The remaining engines, shown but not yet wired. --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-white"><strong>Other engines</strong></div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        @foreach ([
                            ['Calculate Two Month Target', 9, '10,000 Sq.Ft. over 2 months — threshold and rate set by admin'],
                            ['Calculate Three Month Target', 10, '35,000 Sq.Ft. over 3 months — threshold and rate set by admin'],
                            ['Calculate Company Club', 11, 'All approved company Sq.Ft. × ₹' . config('rewards.rates.company_club')],
                            ['Calculate All', 12, 'Runs every engine for the period in one controlled operation'],
                        ] as [$label, $phase, $description])
                            <div class="border rounded p-2 d-flex justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="fw-semibold small">{{ $label }}</div>
                                    <div class="text-muted" style="font-size: .78rem;">{{ $description }}</div>
                                </div>
                                <span class="badge text-bg-light border text-nowrap">Phase {{ $phase }}</span>
                            </div>
                        @endforeach
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        The four calculations are independent engines and are never derived
                        from one another.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Run history --}}
    <div class="card mt-3">
        <div class="card-header bg-white"><strong>Recent calculation runs</strong></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Run</th>
                        <th>Period</th>
                        <th>Type</th>
                        <th class="text-end">Entries</th>
                        <th class="text-end">Sq.Ft.</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $entry)
                        <tr>
                            <td>
                                <a href="{{ route('admin.calculations.show', $entry) }}" class="fw-semibold text-decoration-none">
                                    #{{ $entry->id }}
                                </a>
                            </td>
                            <td>{{ $entry->period }}</td>
                            <td class="small">{{ $entry->run_type->label() }}</td>
                            <td class="text-end">{{ number_format($entry->records_created) }}</td>
                            <td class="text-end">{{ number_format((float) $entry->total_sqft, 2) }}</td>
                            <td class="text-end fw-semibold">₹{{ number_format((float) $entry->total_amount, 2) }}</td>
                            <td><span class="badge {{ $entry->status->badgeClass() }}">{{ $entry->status->label() }}</span></td>
                            <td class="small text-muted">{{ $entry->initiatedBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No calculation runs yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
