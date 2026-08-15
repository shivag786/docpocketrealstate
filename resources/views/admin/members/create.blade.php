@extends('layouts.admin')

@section('title', 'Add member')
@section('page-title', 'Add member')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.members.index') }}">Members</a></li>
    <li class="breadcrumb-item active" aria-current="page">Add</li>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.members.store') }}" novalidate>
        @csrf

        @include('admin.members._form', ['member' => null])

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Create member
            </button>
            <a href="{{ route('admin.members.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>

        <p class="text-muted small mt-2 mb-0">
            The Member ID is generated automatically using the configured prefix
            (<code>{{ config('members.code.prefix') }}</code>) and the next number in sequence.
        </p>
    </form>
@endsection
