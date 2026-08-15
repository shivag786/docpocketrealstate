@extends('layouts.admin')

@section('title', 'Add project')
@section('page-title', 'Add project')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.projects.index') }}">Projects</a></li>
    <li class="breadcrumb-item active" aria-current="page">Add</li>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.projects.store') }}" novalidate>
        @csrf
        @include('admin.projects._form', ['project' => null])

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create project</button>
            <a href="{{ route('admin.projects.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
@endsection
