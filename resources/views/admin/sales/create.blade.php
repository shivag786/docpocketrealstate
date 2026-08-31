@extends('layouts.admin')

@section('title', 'Daily Sale Entry')
@section('page-title', 'Daily Sale Entry')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.sales.index') }}">Sales</a></li>
    <li class="breadcrumb-item active" aria-current="page">Entry</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.sales.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i>Sales history
    </a>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <form method="POST" action="{{ route('admin.sales.store') }}" novalidate id="sale-entry-form">
                @csrf

                {{-- ------------------------------------------------------------
                     Required: member and Sq.Ft. Everything else is optional.
                ------------------------------------------------------------- --}}
                <div class="card sale-entry-card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong>Record a sale</strong>
                        <span class="small text-muted">Member and Sq.Ft. are all that is required</span>
                    </div>

                    <div class="card-body">
                        {{-- Member --}}
                        <div class="mb-4">
                            <label for="member-search" class="form-label required-mark fw-semibold">Member</label>

                            <input type="hidden" id="member_id" name="member_id"
                                   value="{{ old('member_id', $member?->id) }}">

                            <div data-member-picker
                                 data-search-url="{{ route('admin.members.search-sponsors') }}">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-white text-muted">
                                        <i class="bi bi-person-badge"></i>
                                    </span>
                                    <input type="search"
                                           id="member-search"
                                           class="form-control @error('member_id') is-invalid @enderror"
                                           placeholder="Search by member ID, name or mobile"
                                           autocomplete="off"
                                           autofocus
                                           data-member-search>
                                </div>

                                <div class="list-group border rounded mt-1 d-none shadow-sm"
                                     data-member-results></div>

                                <div class="mt-2 {{ $member ? '' : 'd-none' }}" data-member-selected>
                                    <div class="selected-member d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="selected-member-avatar">
                                                <i class="bi bi-person-fill"></i>
                                            </span>
                                            <div>
                                                <div class="fw-semibold" data-member-name>{{ $member?->name }}</div>
                                                <div class="small text-muted" data-member-code>{{ $member?->member_code }}</div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-member-clear
                                                title="Change member">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            @error('member_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        {{-- Registry date.
                             Promoted out of the optional section and defaulted to
                             today: this single field decides which month the sale
                             is rewarded in, and back-dating is a normal operation
                             — entering history, or a registry that came through
                             late. Hiding it behind an accordion made the common
                             case invisible. --}}
                        <div class="row g-3 align-items-start mb-3">
                            <div class="col-12 col-md-6">
                                <label for="registry_date" class="form-label fw-semibold">Registry date</label>
                                <input type="date"
                                       id="registry_date"
                                       name="registry_date"
                                       value="{{ old('registry_date', now()->format('Y-m-d')) }}"
                                       max="{{ now()->format('Y-m-d') }}"
                                       class="form-control form-control-lg @error('registry_date') is-invalid @enderror">
                                @error('registry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">
                                    Today by default. Pick an earlier date to record a past sale —
                                    future dates are not accepted.
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="border rounded p-2 h-100 bg-light-subtle">
                                    <div class="stat-label">Reward month</div>
                                    <div class="small text-muted">
                                        This date decides which month the sale is rewarded in. Saving
                                        rebuilds that month's reward figures straight away — and,
                                        because targets accumulate across months, re-judges every
                                        month after it.
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Sq.Ft. + live direct amount --}}
                        <div class="row g-3 align-items-start">
                            <div class="col-12 col-md-6">
                                <label for="sqft" class="form-label required-mark fw-semibold">Sq.Ft. sold</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-white text-muted"><i class="bi bi-rulers"></i></span>
                                    <input type="text"
                                           id="sqft"
                                           name="sqft"
                                           value="{{ old('sqft') }}"
                                           class="form-control text-end @error('sqft') is-invalid @enderror"
                                           placeholder="0.00"
                                           inputmode="decimal"
                                           autocomplete="off"
                                           required
                                           data-numeric
                                           data-decimals="2"
                                           data-sqft-input
                                           data-rate="{{ config('rewards.rates.direct') }}">
                                    <span class="input-group-text bg-white text-muted">Sq.Ft.</span>
                                    @error('sqft')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="form-text">Numbers only, up to 2 decimal places.</div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold">Direct sale reward</label>
                                <div class="direct-amount-box" data-direct-amount-box>
                                    <div class="direct-amount" data-direct-amount>&#8377;0.00</div>
                                    <div class="direct-amount-formula" data-direct-formula>
                                        Enter Sq.Ft. to see the amount
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ------------------------------------------------------------
                     Where the plot is.

                     A visible card rather than a collapsed accordion, at the
                     client's request (2026-08-31): staff record this on nearly
                     every sale, and it was previously hidden behind "Optional".

                     The project is a dropdown because projects are a managed
                     list. Block and plot number are typed, because they are not
                     — a project gains blocks as it is laid out. The block field
                     offers what has already been recorded against the chosen
                     project, so repeated entry converges on one spelling without
                     ever refusing a new block.
                ------------------------------------------------------------- --}}
                <div class="card mt-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong>Plot location</strong>
                        <span class="small text-muted">Optional &mdash; does not affect the reward</span>
                    </div>

                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label for="project_id" class="form-label">Project</label>
                                <select id="project_id"
                                        name="project_id"
                                        class="form-select @error('project_id') is-invalid @enderror"
                                        data-project-select
                                        data-block-target="block_name">
                                    <option value="">— None —</option>
                                    @foreach ($projects as $id => $name)
                                        <option value="{{ $id }}" @selected(old('project_id') == $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('project_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="block_name" class="form-label">Block name</label>

                                <div data-block-picker
                                     data-search-url="{{ route('admin.sales.blocks') }}">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white text-muted">
                                            <i class="bi bi-grid-3x3-gap"></i>
                                        </span>
                                        <input type="text"
                                               id="block_name"
                                               name="block_name"
                                               value="{{ old('block_name') }}"
                                               class="form-control @error('block_name') is-invalid @enderror"
                                               placeholder="e.g. Block C"
                                               autocomplete="off"
                                               maxlength="100"
                                               data-block-input>
                                        @error('block_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>

                                    {{-- Suggestions land here. Never a <select>:
                                         a new block must always be typeable. --}}
                                    <div class="list-group border rounded mt-1 d-none shadow-sm position-absolute"
                                         style="z-index: 5;"
                                         data-block-results></div>
                                </div>

                                <div class="form-text" data-block-hint>
                                    Pick a project to see the blocks already recorded in it.
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="plot_number" class="form-label">Plot number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="bi bi-signpost"></i></span>
                                    <input type="text"
                                           id="plot_number"
                                           name="plot_number"
                                           value="{{ old('plot_number') }}"
                                           class="form-control @error('plot_number') is-invalid @enderror"
                                           placeholder="e.g. 118"
                                           autocomplete="off"
                                           maxlength="50">
                                    @error('plot_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="form-text">Free text — "118", "A-12", "118/2" are all fine.</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ------------------------------------------------------------
                     Registry paperwork, collapsed by default.
                ------------------------------------------------------------- --}}
                @php
                    // registry_date, project, block and plot are no longer in
                    // here — the date decides the reward month, and the location
                    // is recorded on nearly every sale.
                    $hasOptional = old('registry_reference') || old('notes')
                        || $errors->hasAny(['registry_reference', 'notes']);
                @endphp

                <div class="accordion mt-3" id="saleOptionalAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button {{ $hasOptional ? '' : 'collapsed' }}"
                                    type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#saleOptionalDetails"
                                    aria-expanded="{{ $hasOptional ? 'true' : 'false' }}">
                                <i class="bi bi-sliders me-2"></i>
                                Registry paperwork
                                <span class="badge text-bg-light border ms-2 fw-normal">Optional</span>
                            </button>
                        </h2>

                        <div id="saleOptionalDetails"
                             class="accordion-collapse collapse {{ $hasOptional ? 'show' : '' }}"
                             data-bs-parent="#saleOptionalAccordion">
                            <div class="accordion-body">
                                <p class="small text-muted">
                                    Fill these in only if you have them. They do not affect the
                                    reward calculation.
                                </p>

                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label for="registry_reference" class="form-label">Registry number</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white text-muted"><i class="bi bi-hash"></i></span>
                                            <input type="text"
                                                   id="registry_reference"
                                                   name="registry_reference"
                                                   value="{{ old('registry_reference') }}"
                                                   class="form-control @error('registry_reference') is-invalid @enderror"
                                                   autocomplete="off"
                                                   placeholder="e.g. REG-2026-0001">
                                            @error('registry_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>
                                        <div class="form-text">
                                            When given it must be unique, which stops the same registry
                                            being entered twice.
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label for="notes" class="form-label">Notes</label>
                                        <textarea id="notes" name="notes" rows="2"
                                                  class="form-control @error('notes') is-invalid @enderror"
                                                  placeholder="Anything worth recording about this sale">{{ old('notes') }}</textarea>
                                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning d-flex align-items-start gap-2 small mt-3" role="alert">
                    <i class="bi bi-exclamation-triangle mt-1"></i>
                    <div>
                        A saved sale is <strong>approved immediately</strong> and
                        <strong>cannot be edited or deleted</strong>. Check the member and
                        Sq.Ft. before saving.
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg"
                            data-confirm-submit="Save this sale? It cannot be edited or deleted afterwards.">
                        <i class="bi bi-check-lg me-1"></i>Save sale
                    </button>
                    <button type="reset" class="btn btn-outline-secondary btn-lg" data-sale-reset>Clear</button>
                </div>
            </form>
        </div>

        {{-- Recent entries, so staff can confirm the last save landed --}}
        <div class="col-12 col-xl-4">
            <div class="card">
                <div class="card-header bg-white"><strong>Recently recorded</strong></div>

                @if ($recent->isEmpty())
                    <div class="card-body text-muted small">No sales recorded yet.</div>
                @else
                    <ul class="list-group list-group-flush small">
                        @foreach ($recent as $entry)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="fw-semibold">{{ $entry->member->member_code }}</div>
                                    <span class="badge text-bg-primary">{{ number_format((float) $entry->sqft, 2) }} Sq.Ft.</span>
                                </div>
                                <div class="text-muted">{{ $entry->member->name }}</div>
                                <div class="text-muted">
                                    {{ $entry->registry_date->format('d M Y') }}
                                    @if ($entry->location())
                                        &middot; {{ $entry->location() }}
                                    @elseif ($entry->property)
                                        &middot; {{ $entry->property->property_code }}
                                    @endif
                                    @if ($entry->registry_reference)
                                        &middot; {{ $entry->registry_reference }}
                                    @endif
                                </div>
                                <a href="{{ route('admin.sales.show', $entry) }}" class="small text-decoration-none">
                                    View
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
