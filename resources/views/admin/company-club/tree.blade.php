@extends('layouts.admin')

@section('title', $settings->name() . ' — Network Tree')
@section('page-title', $settings->name() . ' — Network Tree')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Network Tree</li>
@endsection

@section('content')

    <div class="alert alert-light border small">
        <i class="bi bi-info-circle me-1"></i>
        <strong>{{ $settings->name() }} is a system entity, not a member.</strong>
        It has no record, no member ID and no rewards. Members created without a sponsor sit
        directly beneath it. It is <strong>never counted as a level</strong> &mdash; the
        immediate active sponsor of a seller is level&nbsp;1, and a member sitting directly
        under the Club simply has nobody above them to pay.
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Network</strong>
            <div class="small text-muted">
                {{ number_format($network['total']) }} members &middot;
                {{ number_format($network['active']) }} active &middot;
                {{ number_format($network['direct_club_members']) }} directly under the Club
            </div>
        </div>

        <div class="card-body">
            {{-- The Club is drawn here, in the view, precisely because there is
                 no row for it. Its children arrive over AJAX one level at a
                 time — the network can be large and must never be rendered in
                 a single response. --}}
            <div class="mb-3">
                <span class="cc-network-root">
                    <i class="bi bi-award me-1"></i>{{ $settings->name() }}
                </span>
            </div>

            <div id="cc-tree-root"
                 data-cc-tree
                 data-children-url="{{ route('admin.company-club.tree.children') }}"
                 data-explain-url="{{ route('admin.company-club.explain', ['member' => '__ID__']) }}">
                <div class="text-muted small" data-cc-tree-loading>
                    <span class="spinner-border spinner-border-sm me-1"></span>Loading network&hellip;
                </div>
            </div>
        </div>

        <div class="card-footer bg-white small text-muted">
            Branches load as you expand them. Level numbers count <strong>members only</strong>:
            {{ $settings->name() }} is not level 0 and not level 1 &mdash; it is not a level at all.
        </div>
    </div>
@endsection
