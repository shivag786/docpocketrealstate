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
            <div class="card">
                <div class="card-header bg-white">
                    <strong>Record a registry sale</strong>
                </div>

                <div class="card-body">
                    <div class="alert alert-warning d-flex align-items-start gap-2 small" role="alert">
                        <i class="bi bi-exclamation-triangle mt-1"></i>
                        <div>
                            A saved sale is <strong>approved immediately</strong> and
                            <strong>cannot be edited or deleted</strong>. Check the member,
                            registry number and Sq.Ft. before saving.
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.sales.store') }}" novalidate id="sale-entry-form">
                        @csrf

                        <div class="row g-3">
                            {{-- Member --}}
                            <div class="col-12">
                                <label for="member-search" class="form-label required-mark">Member</label>

                                <input type="hidden" id="member_id" name="member_id"
                                       value="{{ old('member_id', $member?->id) }}">

                                <div data-member-picker
                                     data-search-url="{{ route('admin.members.search-sponsors') }}">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                        <input type="search"
                                               id="member-search"
                                               class="form-control @error('member_id') is-invalid @enderror"
                                               placeholder="Member ID, name or mobile"
                                               autocomplete="off"
                                               autofocus
                                               data-member-search>
                                    </div>

                                    <div class="list-group list-group-flush d-none border rounded mt-1"
                                         data-member-results></div>

                                    <div class="mt-2 {{ $member ? '' : 'd-none' }}" data-member-selected>
                                        <div class="border rounded p-2 d-flex justify-content-between align-items-center bg-light">
                                            <div>
                                                <div class="small text-muted">Selected member</div>
                                                <div class="fw-semibold" data-member-name>{{ $member?->name }}</div>
                                                <div class="small text-muted" data-member-code>{{ $member?->member_code }}</div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-member-clear>
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                @error('member_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>

                            {{-- Project + property --}}
                            <div class="col-12 col-md-6">
                                <label for="project_id" class="form-label required-mark">Project</label>
                                <select id="project_id"
                                        name="project_id"
                                        class="form-select @error('project_id') is-invalid @enderror"
                                        required
                                        data-project-select
                                        data-properties-url="{{ route('admin.properties.for-project') }}">
                                    <option value="">Select a project</option>
                                    @foreach ($projects as $id => $name)
                                        <option value="{{ $id }}" @selected(old('project_id') == $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('project_id')<div class="invalid-feedback">{{ $message }}</div>@enderror

                                @if ($projects->isEmpty())
                                    <div class="form-text text-danger">
                                        No active projects.
                                        <a href="{{ route('admin.projects.create') }}">Create one first</a>.
                                    </div>
                                @endif
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="property_id" class="form-label required-mark">Property / Site</label>
                                <select id="property_id"
                                        name="property_id"
                                        class="form-select @error('property_id') is-invalid @enderror"
                                        required
                                        data-property-select
                                        data-selected="{{ old('property_id') }}">
                                    <option value="">Select a project first</option>
                                </select>
                                @error('property_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            {{-- Registry --}}
                            <div class="col-12 col-md-6">
                                <label for="registry_reference" class="form-label required-mark">Registry number</label>
                                <input type="text"
                                       id="registry_reference"
                                       name="registry_reference"
                                       value="{{ old('registry_reference') }}"
                                       class="form-control @error('registry_reference') is-invalid @enderror"
                                       required>
                                @error('registry_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Must be unique — this prevents the same sale being entered twice.</div>
                            </div>

                            <div class="col-6 col-md-3">
                                <label for="registry_date" class="form-label required-mark">Registry date</label>
                                <input type="date"
                                       id="registry_date"
                                       name="registry_date"
                                       value="{{ old('registry_date', now()->format('Y-m-d')) }}"
                                       max="{{ now()->format('Y-m-d') }}"
                                       class="form-control @error('registry_date') is-invalid @enderror"
                                       required>
                                @error('registry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Decides the reward month.</div>
                            </div>

                            <div class="col-6 col-md-3">
                                <label for="sqft" class="form-label required-mark">Sq.Ft.</label>
                                <input type="number"
                                       id="sqft"
                                       name="sqft"
                                       value="{{ old('sqft') }}"
                                       step="0.01"
                                       min="0.01"
                                       class="form-control @error('sqft') is-invalid @enderror"
                                       required>
                                @error('sqft')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12">
                                <label for="notes" class="form-label">Notes <span class="text-muted small">(optional)</span></label>
                                <textarea id="notes" name="notes" rows="2"
                                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary"
                                    data-confirm-submit="Save this sale? It cannot be edited or deleted afterwards.">
                                <i class="bi bi-check-lg me-1"></i>Save sale
                            </button>
                            <button type="reset" class="btn btn-outline-secondary">Clear form</button>
                        </div>
                    </form>
                </div>
            </div>
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
                                <div class="d-flex justify-content-between">
                                    <a href="{{ route('admin.sales.show', $entry) }}" class="fw-semibold text-decoration-none">
                                        {{ $entry->registry_reference }}
                                    </a>
                                    <span>{{ number_format((float) $entry->sqft, 2) }} Sq.Ft.</span>
                                </div>
                                <div class="text-muted">
                                    {{ $entry->member->member_code }} — {{ $entry->member->name }}
                                </div>
                                <div class="text-muted">
                                    {{ $entry->property->property_code }} ·
                                    {{ $entry->registry_date->format('d M Y') }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
