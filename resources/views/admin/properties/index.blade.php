@extends('layouts.admin')

@section('title', 'Properties')
@section('page-title', 'Properties / Sites')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Properties</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.properties.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add property
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label for="q" class="form-label small mb-1">Search</label>
                    <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Property code or details">
                </div>
                <div class="col-6 col-md-3">
                    <label for="project_id" class="form-label small mb-1">Project</label>
                    <select id="project_id" name="project_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($projects as $id => $name)
                            <option value="{{ $id }}" @selected(($filters['project_id'] ?? '') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="status" class="form-label small mb-1">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    @if (array_filter($filters))
                        <a href="{{ route('admin.properties.index') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Project</th>
                        <th>Details</th>
                        <th class="text-center">Sales</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($properties as $property)
                        <tr>
                            <td class="fw-semibold">{{ $property->property_code }}</td>
                            <td>
                                <a href="{{ route('admin.projects.show', $property->project) }}" class="text-decoration-none">
                                    {{ $property->project->name }}
                                </a>
                            </td>
                            <td class="small text-muted">{{ Str::limit($property->details, 60) ?: '—' }}</td>
                            <td class="text-center">{{ $property->sales_count ?: '—' }}</td>
                            <td>
                                <span class="badge {{ $property->status->badgeClass() }}">{{ $property->status->label() }}</span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.properties.edit', $property) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-geo-alt fs-2 d-block mb-2 opacity-50"></i>
                                No properties yet.
                                <a href="{{ route('admin.properties.create') }}">Add the first one</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($properties->hasPages())
            <div class="card-footer bg-white">{{ $properties->links() }}</div>
        @endif
    </div>
@endsection
