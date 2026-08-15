@extends('layouts.admin')

@section('title', 'Add property')
@section('page-title', 'Add property / site')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.properties.index') }}">Properties</a></li>
    <li class="breadcrumb-item active" aria-current="page">Add</li>
@endsection

@section('content')
    @if ($projects->isEmpty())
        <div class="alert alert-warning">
            There are no active projects yet.
            <a href="{{ route('admin.projects.create') }}">Create a project first</a>.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.properties.store') }}" novalidate>
        @csrf
        @include('admin.properties._form', ['property' => null])

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary" @disabled($projects->isEmpty())>
                <i class="bi bi-check-lg me-1"></i>Create property
            </button>
            <a href="{{ route('admin.properties.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
@endsection
