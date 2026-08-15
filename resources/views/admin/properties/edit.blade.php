@extends('layouts.admin')

@section('title', 'Edit ' . $property->property_code)
@section('page-title', 'Edit property / site')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.properties.index') }}">Properties</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $property->property_code }}</li>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.properties.update', $property) }}" novalidate>
        @csrf
        @method('PUT')
        @include('admin.properties._form')

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save changes</button>
            <a href="{{ route('admin.properties.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>

    <div class="card mt-3 border-danger-subtle">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong class="d-block">Delete property</strong>
                <span class="small text-muted">Blocked once sales have been recorded against it.</span>
            </div>
            <form method="POST" action="{{ route('admin.properties.destroy', $property) }}"
                  data-confirm="Delete property {{ $property->property_code }}?">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </form>
        </div>
    </div>
@endsection
