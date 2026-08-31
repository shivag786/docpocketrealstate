@extends('layouts.admin')

@php
    // A member with no sponsor sits directly under the Company Club, which is a
    // system entity with no member row. "Root" was the old wording for it.
    $clubName = \App\Models\CompanyClubSetting::current()->name();
@endphp

@section('title', 'Sponsor Tree')
@section('page-title', 'Sponsor Tree')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Sponsor Tree</li>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-lg-5 position-relative">
                    <label for="tree-search" class="form-label small mb-1">Find a member</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search"
                               id="tree-search"
                               class="form-control"
                               placeholder="Member ID, name or mobile"
                               autocomplete="off"
                               data-tree-search>
                    </div>
                    <div class="list-group position-absolute w-100 shadow-sm d-none"
                         style="z-index: 20;"
                         data-tree-search-results></div>
                </div>

                <div class="col-6 col-lg-2">
                    <label for="tree-level" class="form-label small mb-1">Level filter</label>
                    <select id="tree-level" class="form-select form-select-sm" data-tree-level>
                        <option value="">All levels</option>
                        @foreach (range(1, 10) as $level)
                            <option value="{{ $level }}">Level {{ $level }} and above</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-lg-5 d-flex flex-wrap gap-2 justify-content-lg-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tree-expand>
                        <i class="bi bi-arrows-expand me-1"></i>Expand next level
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tree-collapse>
                        <i class="bi bi-arrows-collapse me-1"></i>Collapse all
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" data-tree-reset>
                        <i class="bi bi-house me-1"></i>Back to {{ $clubName }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Focus banner: shown when the tree is re-rooted at one member. --}}
    <div class="alert alert-info d-none align-items-center gap-2" role="alert" data-tree-focus-banner>
        <i class="bi bi-crosshair"></i>
        <div class="flex-grow-1">
            Focused on <strong data-tree-focus-name></strong>
            <span class="text-muted" data-tree-focus-code></span>
        </div>
        <a href="#" class="btn btn-sm btn-outline-primary d-none" data-tree-focus-sponsor>
            <i class="bi bi-arrow-up me-1"></i>View sponsor
        </a>
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Network</strong>
            <span class="small text-muted">
                {{ number_format($memberCount) }} {{ Str::plural('member', $memberCount) }},
                {{ number_format($rootCount) }} directly under {{ $clubName }}
            </span>
        </div>

        <div class="card-body">
            @if ($memberCount === 0)
                <div class="text-center text-muted py-5">
                    <i class="bi bi-diagram-3 fs-1 d-block mb-2 opacity-50"></i>
                    No members yet.
                    <a href="{{ route('admin.members.create') }}">Add the first one</a>.
                </div>
            @else
                {{--
                    Only the members directly under {{ $clubName }} are fetched on load.
                    Each expansion requests
                    exactly one more level, so the whole network is never rendered
                    at once (docs/04_UI_UX_SPECIFICATION.md).
                --}}
                <div data-member-tree
                     data-club-name="{{ $clubName }}"
                     data-children-url="{{ route('admin.tree.children') }}"
                     data-search-url="{{ route('admin.tree.search') }}"
                     data-focus-url="{{ url('admin/tree/focus') }}"
                     data-downline-url="{{ url('admin/tree/downline') }}"
                     @if ($focus) data-initial-focus="{{ $focus->id }}" @endif>

                    <div class="tree-root" data-tree-container></div>

                    <div class="text-center text-muted py-4 d-none" data-tree-loading>
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Loading network&hellip;
                    </div>
                </div>
            @endif
        </div>

        <div class="card-footer bg-white small text-muted d-flex flex-wrap gap-3">
            <span><i class="bi bi-people me-1"></i>Direct = personally referred members</span>
            <span><i class="bi bi-diagram-2 me-1"></i>Team = every member below, at any depth</span>
        </div>
    </div>
@endsection
