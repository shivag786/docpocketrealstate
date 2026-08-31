@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', $settings->name() . ' — Calculation History')
@section('page-title', $settings->name() . ' — Calculation History')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Calculation History</li>
@endsection

@section('content')

    <div class="alert alert-light border small">
        <i class="bi bi-clock-history me-1"></i>
        <strong>Every calculation ever made is listed here, including replaced ones.</strong>
        A recalculation supersedes the previous run rather than erasing it: the older run keeps
        its own code, figures, timestamp and admin, so it is always possible to see what a month
        said before and when it changed. Only the newest <em>completed</em> run for a month holds
        live figures.
    </div>

    <div class="card">
        <div class="card-header bg-white">
            <strong>Calculation runs</strong>
            <span class="text-muted small ms-2">newest first</span>
        </div>

        @if ($runs->total() > 0)
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Run</th>
                            <th>Month</th>
                            <th>Status</th>
                            <th class="text-end">Sq.Ft.</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Pool</th>
                            <th class="text-center">Members</th>
                            <th class="text-end">Each</th>
                            <th class="text-end">Distributed</th>
                            <th>Calculated</th>
                            <th>By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr class="{{ $run->isCompleted() ? '' : 'text-muted' }}">
                                <td><span class="badge text-bg-dark">{{ $run->run_code }}</span></td>
                                <td>{{ $run->period }}</td>
                                <td>
                                    <span class="badge {{ $run->status->badgeClass() }}">
                                        {{ $run->status->label() }}
                                    </span>
                                    @if ($run->automatic)
                                        <i class="bi bi-lightning-charge text-muted"
                                           title="Rebuilt automatically after a sale was entered."></i>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format((float) $run->total_sqft, 2) }}</td>
                                <td class="text-end">&#8377;{{ Money::inr((string) $run->rate) }}</td>
                                <td class="text-end">&#8377;{{ Money::inr((string) $run->pool_amount) }}</td>
                                <td class="text-center">{{ number_format($run->eligible_count) }}</td>
                                <td class="text-end">&#8377;{{ Money::inr((string) $run->equal_share) }}</td>
                                <td class="text-end">&#8377;{{ Money::inr((string) $run->distributed_amount) }}</td>
                                <td class="text-nowrap small">{{ $run->created_at->format('d M Y, H:i') }}</td>
                                <td class="small">{{ $run->initiatedBy?->name ?? 'system' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.company-club.runs.show', $run) }}"
                                       class="btn btn-sm btn-outline-secondary">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white">{{ $runs->links() }}</div>
        @else
            <div class="card-body text-muted small">
                No {{ $settings->name() }} calculation has been made yet.
                <a href="{{ route('admin.company-club.calculate') }}">Preview this month</a> to start.
            </div>
        @endif
    </div>
@endsection
