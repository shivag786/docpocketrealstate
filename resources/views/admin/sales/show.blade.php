@extends('layouts.admin')

@php $saleLabel = $sale->registry_reference ?? '#' . $sale->id; @endphp

@section('title', $saleLabel)
@section('page-title', 'Sale ' . $saleLabel)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.sales.index') }}">Sales</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $saleLabel }}</li>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Registry sale</strong>
                    <span class="badge {{ $sale->status->badgeClass() }}">{{ $sale->status->label() }}</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Registry number</span>
                        <span class="fw-semibold">
                            {{ $sale->registry_reference ?? '—' }}
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Sq.Ft.</span>
                        <span class="fw-semibold">{{ number_format((float) $sale->sqft, 2) }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Registry date</span>
                        <span>{{ $sale->registry_date->format('d M Y') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Sale date</span>
                        <span>{{ $sale->sale_date->format('d M Y') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Reward period</span>
                        <span class="badge text-bg-primary">{{ $sale->period() }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Project</span>
                        <span>
                            @if ($sale->project)
                                <a href="{{ route('admin.projects.show', $sale->project) }}" class="text-decoration-none">
                                    {{ $sale->project->name }}
                                </a>
                            @else
                                &mdash;
                            @endif
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Block</span>
                        <span>{{ $sale->block_name ?: '—' }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Plot number</span>
                        <span>{{ $sale->plot_number ?: '—' }}</span>
                    </li>
                    {{-- The managed Property / Site record. Only sales entered
                         before block and plot became typed fields (2026-08-31)
                         carry one, so the row is shown only when there is one
                         rather than printing a permanent dash. --}}
                    @if ($sale->property)
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Property / Site</span>
                            <span>{{ $sale->property->property_code }}</span>
                        </li>
                    @endif
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Entered by</span>
                        <span>{{ $sale->enteredBy?->name ?? '—' }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Recorded at</span>
                        <span>{{ $sale->created_at->format('d M Y, H:i') }}</span>
                    </li>
                    @if ($sale->notes)
                        <li class="list-group-item">
                            <div class="text-muted mb-1">Notes</div>
                            <div>{{ $sale->notes }}</div>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card mb-3">
                <div class="card-header bg-white"><strong>Seller</strong></div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Member ID</span>
                        <a href="{{ route('admin.members.show', $sale->member) }}" class="fw-semibold text-decoration-none">
                            {{ $sale->member->member_code }}
                        </a>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Name</span><span>{{ $sale->member->name }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Mobile</span><span>{{ $sale->member->mobile }}</span>
                    </li>
                </ul>
            </div>

            @php
                $directAmount = bcmul($sale->sqft, (string) config('rewards.rates.direct'), 2);
                // Still computed while Upline is hidden: the pool is still being
                // formed from this Sq.Ft., the row below is simply not drawn.
                $uplinePool = bcmul($sale->sqft, (string) config('rewards.rates.upline'), 2);
            @endphp

            <div class="card">
                <div class="card-header bg-white"><strong>Rewards from this sale</strong></div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">
                            Direct reward
                            <span class="d-block text-body-tertiary">
                                {{ number_format((float) $sale->sqft, 2) }} × ₹{{ config('rewards.rates.direct') }}
                            </span>
                        </span>
                        <span class="fw-semibold text-success">₹{{ number_format((float) $directAmount, 2) }}</span>
                    </li>
                    @if (App\Enums\RewardType::Upline->isVisible())
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">
                                Upline pool from this Sq.Ft.
                                <span class="d-block text-body-tertiary">
                                    split equally among up to {{ config('rewards.upline.max_levels') }} active uplines
                                </span>
                            </span>
                            <span class="fw-semibold">₹{{ number_format((float) $uplinePool, 2) }}</span>
                        </li>
                    @endif
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Counts toward team target</span>
                        <span class="fw-semibold">
                            {{ number_format((float) $sale->sqft, 2) }} Sq.Ft.
                        </span>
                    </li>
                    {{-- Company Club counts this sale only while the seller is
                         ACTIVE — the one rule that differs from every other
                         engine, so it is stated on the sale itself. --}}
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Counts toward Company Club</span>
                        @if ($sale->member?->isActive())
                            <span class="fw-semibold">
                                {{ number_format((float) $sale->sqft, 2) }} Sq.Ft.
                            </span>
                        @else
                            <span class="badge text-bg-secondary"
                                  title="The seller is inactive, so this sale is excluded from the Company Club pool. The other engines are unaffected.">
                                excluded &mdash; seller inactive
                            </span>
                        @endif
                    </li>
                </ul>
                <div class="card-footer bg-white small text-muted">
                    Figures shown are what this sale contributes.
                    @if (App\Enums\RewardType::Upline->isVisible())
                        The upline pool is split across the seller's chain rather than paid to one
                        member, and the seller's own monthly total drives it — see the
                        <a href="{{ route('admin.rewards.upline.explain', [$sale->member_id, 'period' => $sale->registry_date->format('Y-m')]) }}">upline explorer</a>.
                    @else
                        A sale is never editable, so these are final for this registry.
                    @endif
                </div>
            </div>

            <div class="alert alert-secondary small mt-3 mb-0 d-flex gap-2">
                <i class="bi bi-lock mt-1"></i>
                <div>
                    Registry sales are permanent. There is no edit or delete, by
                    business decision.
                </div>
            </div>
        </div>
    </div>
@endsection
