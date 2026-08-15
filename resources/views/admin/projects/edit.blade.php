@extends('layouts.admin')

@section('title', 'Edit ' . $project->name)
@section('page-title', 'Edit project')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.projects.index') }}">Projects</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.projects.show', $project) }}">{{ $project->name }}</a></li>
    <li class="breadcrumb-item active" aria-current="page">Edit</li>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.projects.update', $project) }}" novalidate>
        @csrf
        @method('PUT')
        @include('admin.projects._form')

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save changes</button>
            <a href="{{ route('admin.projects.show', $project) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>

    <div class="card mt-3 border-danger-subtle">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong class="d-block">Delete project</strong>
                <span class="small text-muted">
                    Blocked once the project has properties or recorded sales.
                </span>
            </div>
            <form method="POST" action="{{ route('admin.projects.destroy', $project) }}"
                  data-confirm="Delete project &quot;{{ $project->name }}&quot;?">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </form>
        </div>
    </div>
@endsection
