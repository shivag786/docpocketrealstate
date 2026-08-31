@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', 'Eligible Members — ' . $period)
@section('page-title', $settings->name() . ' — Eligible Members')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Eligible Members</li>
@endsection

@section('page-actions')
    @include('admin.partials.export-menu', [
        'route' => 'admin.company-club.eligible.export',
        'params' => ['period' => $period],
        'count' => $recipients?->total() ?? 0,
    ])

    <a href="{{ route('admin.company-club.distribution', ['period' => $period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-diagram-3 me-1"></i>Reward distribution
    </a>
@endsection

@section('content')

    @include('admin.company-club._period-filter')

    @include('admin.company-club._run-status', [
        'run' => $run,
        'history' => $history,
        'needsRecalculation' => $needsRecalculation,
        'period' => $period,
    ])

    @if ($run)
        <div class="row g-3">
            {{-- Who qualified. --}}
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <strong>Eligible members</strong>
                        <span class="badge text-bg-primary ms-1">{{ $run->eligible_count }}</span>
                        <div class="text-muted small mt-1">
                            Unique recipients. A member reached through several selling branches
                            appears <strong>once</strong>, with every qualifying path preserved.
                        </div>
                    </div>

                    @if ($recipients && $recipients->total() > 0)
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Member</th>
                                        <th>Status</th>
                                        <th class="text-center">Level</th>
                                        <th class="text-center">Paths</th>
                                        <th class="text-end">Reward</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recipients as $reward)
                                        <tr>
                                            <td>
                                                <span class="badge text-bg-light border">{{ $reward->member?->member_code }}</span>
                                                {{ $reward->member?->name }}
                                            </td>
                                            <td>
                                                <span class="badge {{ $reward->member?->status->badgeClass() }}">
                                                    {{ $reward->member?->status->label() }}
                                                </span>
                                            </td>
                                            <td class="text-center">L{{ $reward->best_level }}</td>
                                            <td class="text-center">{{ $reward->eligibility_path_count }}</td>
                                            <td class="text-end fw-semibold">
                                                &#8377;{{ Money::inr((string) $reward->amount) }}
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.company-club.explain', [$reward->member_id, 'period' => $period]) }}"
                                                   class="btn btn-sm btn-outline-secondary">Why?</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="card-footer bg-white">{{ $recipients->links() }}</div>
                    @else
                        <div class="card-body text-muted small">
                            Nobody was eligible in {{ $period }}.
                        </div>
                    @endif
                </div>
            </div>

            {{-- What produced the pool. The other half of the explanation: these
                 members' sales are why anybody was paid at all. --}}
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <strong>Sales that created the pool</strong>
                        <div class="text-muted small mt-1">
                            Active sellers whose branches reached at least one eligible member.
                        </div>
                    </div>

                    @if ($sellers->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Seller</th>
                                        <th class="text-end">Sq.Ft.</th>
                                        <th class="text-center">Reached</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sellers as $seller)
                                        <tr>
                                            <td>
                                                <span class="badge text-bg-light border">
                                                    {{ $seller->saleMember?->member_code }}
                                                </span>
                                                {{ $seller->saleMember?->name }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $seller->sqft, 2) }}</td>
                                            <td class="text-center">{{ $seller->recipients }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="card-footer bg-white small text-muted">
                            "Reached" is how many eligible members that seller's chain produced &mdash;
                            at most {{ $settings->maxLevels() }}, fewer when the chain runs out or
                            an inactive sponsor sits at the top of it.
                        </div>
                    @else
                        <div class="card-body text-muted small">
                            No selling branch reached an eligible member in {{ $period }}.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection
