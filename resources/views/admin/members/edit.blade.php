@extends('layouts.admin')

@section('title', 'Edit ' . $member->member_code)
@section('page-title', 'Edit member')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.members.index') }}">Members</a></li>
    <li class="breadcrumb-item">
        <a href="{{ route('admin.members.show', $member) }}">{{ $member->member_code }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Edit</li>
@endsection

@section('content')
    <div class="alert alert-light border d-flex align-items-center gap-2 small">
        <i class="bi bi-hash"></i>
        <div>
            Member ID <strong>{{ $member->member_code }}</strong> is permanent and cannot be changed.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.members.update', $member) }}" novalidate>
        @csrf
        @method('PUT')

        @include('admin.members._form')

        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Save changes
            </button>
            <a href="{{ route('admin.members.show', $member) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
@endsection
