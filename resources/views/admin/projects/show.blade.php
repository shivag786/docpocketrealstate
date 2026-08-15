@extends('layouts.admin')

@section('title', $project->name)
@section('page-title', $project->name)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.projects.index') }}">Projects</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $project->name }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.properties.create', ['project_id' => $project->id]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-geo-alt me-1"></i>Add property
    </a>
    <a href="{{ route('admin.projects.edit', $project) }}" class="btn btn-sm btn-primary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
@endsection

@section('content')
    <div class="row g-3 mb-3">
        @foreach ([
            ['Location', $project->location ?: '—', 'bi-geo'],
            ['Properties', number_format($project->properties_count), 'bi-geo-alt'],
            ['Recorded sales', number_format($project->sales_count), 'bi-receipt'],
        ] as [$label, $value, $icon])
            <div class="col-12 col-md-4">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $label }}</div>
                            <i class="bi {{ $icon }} text-muted"></i>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($project->description)
        <div class="card mb-3">
            <div class="card-body small">{{ $project->description }}</div>
        </div>
    @endif

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Properties / Sites</strong>
            <span class="badge {{ $project->status->badgeClass() }}">{{ $project->status->label() }}</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
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
                            <td class="small text-muted">{{ $property->details ?: '—' }}</td>
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
                            <td colspan="5" class="text-center text-muted py-4">
                                No properties in this project yet.
                                <a href="{{ route('admin.properties.create', ['project_id' => $project->id]) }}">Add one</a>.
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
