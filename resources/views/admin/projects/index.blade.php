@extends('layouts.admin')

@section('title', 'Projects')
@section('page-title', 'Projects')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Projects</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.projects.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add project
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="q" class="form-label small mb-1">Search</label>
                    <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Project name or location">
                </div>
                <div class="col-6 col-md-3">
                    <label for="status" class="form-label small mb-1">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    @if (array_filter($filters))
                        <a href="{{ route('admin.projects.index') }}" class="btn btn-sm btn-outline-secondary">
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
                        <th>Project</th>
                        <th>Location</th>
                        <th class="text-center">Properties</th>
                        <th class="text-center">Sales</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($projects as $project)
                        <tr>
                            <td>
                                <a href="{{ route('admin.projects.show', $project) }}" class="fw-semibold text-decoration-none">
                                    {{ $project->name }}
                                </a>
                            </td>
                            <td class="text-muted">{{ $project->location ?: '—' }}</td>
                            <td class="text-center">{{ $project->properties_count }}</td>
                            <td class="text-center">{{ $project->sales_count ?: '—' }}</td>
                            <td>
                                <span class="badge {{ $project->status->badgeClass() }}">{{ $project->status->label() }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.properties.create', ['project_id' => $project->id]) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Add property">
                                    <i class="bi bi-geo-alt"></i>
                                </a>
                                <a href="{{ route('admin.projects.edit', $project) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-buildings fs-2 d-block mb-2 opacity-50"></i>
                                No projects yet.
                                <a href="{{ route('admin.projects.create') }}">Add the first one</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($projects->hasPages())
            <div class="card-footer bg-white">{{ $projects->links() }}</div>
        @endif
    </div>
@endsection
