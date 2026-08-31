@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', $settings->name() . ' — Settings')
@section('page-title', $settings->name() . ' — Settings')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Settings</li>
@endsection

@section('content')

    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('admin.company-club.settings.update') }}" class="card">
                @csrf
                @method('PUT')

                <div class="card-header bg-white"><strong>Configuration</strong></div>

                <div class="card-body">
                    <div class="mb-3">
                        <label for="display_name" class="form-label">Display name</label>
                        <input type="text" id="display_name" name="display_name"
                               class="form-control @error('display_name') is-invalid @enderror"
                               value="{{ old('display_name', $settings->display_name) }}" maxlength="100" required>
                        @error('display_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            What this module is called throughout the admin panel &mdash; Company Club,
                            Corporate Club, Main Company, Central Club. <strong>Cosmetic only:</strong>
                            renaming it changes no figure and no rule.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="reward_rate" class="form-label">Reward rate (&#8377; per Sq.Ft.)</label>
                        <input type="number" id="reward_rate" name="reward_rate" step="0.01" min="0.01"
                               class="form-control @error('reward_rate') is-invalid @enderror"
                               value="{{ old('reward_rate', $settings->reward_rate) }}" required>
                        @error('reward_rate')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            Multiplies the month's eligible Sq.Ft. to produce the single monthly pool.
                            Client-confirmed at &#8377;50.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="max_upline_levels" class="form-label">Maximum active upline levels</label>
                        <input type="number" id="max_upline_levels" name="max_upline_levels" min="1" max="20"
                               class="form-control @error('max_upline_levels') is-invalid @enderror"
                               value="{{ old('max_upline_levels', $settings->max_upline_levels) }}" required>
                        @error('max_upline_levels')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            How many <strong>active</strong> sponsors above a seller qualify. Inactive
                            sponsors are skipped and do not use up a level, so this is a count of
                            active members, not of database hops. Client-confirmed at 5.
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary"
                            data-confirm-submit="Save these settings? Calculations already recorded keep the rate and level cap they were made with — only future calculations use the new values.">
                        <i class="bi bi-save me-1"></i>Save settings
                    </button>
                </div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white"><strong>What changing these does</strong></div>
                <div class="card-body small">
                    <div class="alert alert-success mb-3">
                        <i class="bi bi-shield-check me-1"></i>
                        <strong>History is safe.</strong>
                        Every calculation freezes the rate and level cap it used onto its own run
                        row. Editing these values can never rewrite a figure that has already been
                        calculated, reported or paid.
                    </div>

                    <p class="mb-2"><strong>Future calculations change from the next run onward.</strong></p>
                    <ul class="ps-3 mb-3">
                        <li>Raising the rate raises the pool, and therefore every share.</li>
                        <li>Raising the level cap widens the recipient list, which <em>lowers</em>
                            each share &mdash; the pool is fixed and divided equally.</li>
                        <li>Lowering the level cap narrows the list and raises each share.</li>
                    </ul>

                    @if ($calculatedPeriods->isNotEmpty())
                        <p class="mb-1"><strong>Already calculated and unaffected:</strong></p>
                        <p class="mb-0">
                            @foreach ($calculatedPeriods as $period)
                                <span class="badge text-bg-light border">{{ $period }}</span>
                            @endforeach
                        </p>
                    @else
                        <p class="text-muted mb-0">No month has been calculated yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
