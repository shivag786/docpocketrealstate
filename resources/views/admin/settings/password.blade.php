@extends('layouts.admin')

@section('title', 'Change Password')
@section('page-title', 'Change Password')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.settings.edit') }}">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Password</li>
@endsection

@section('content')
    @include('admin.settings._nav')

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <form method="POST" action="{{ route('admin.settings.password.update') }}" class="card">
                @csrf
                @method('PUT')

                <div class="card-header bg-white">
                    <strong>Your password</strong>
                    <div class="small text-muted mt-1">
                        Signed in as {{ auth()->user()->email }}
                    </div>
                </div>

                <div class="card-body">
                    @foreach ([
                        ['current_password', 'Current password', 'current-password', true],
                        ['password', 'New password', 'new-password', true],
                        ['password_confirmation', 'Confirm new password', 'new-password', false],
                    ] as [$field, $label, $autocomplete, $showError])
                        <div class="mb-3">
                            <label for="{{ $field }}" class="form-label required-mark">{{ $label }}</label>

                            {{-- invalid-feedback lives inside the input-group,
                                 or Bootstrap will not reveal it: it only shows a
                                 sibling of the .is-invalid control. --}}
                            <div class="input-group">
                                <input type="password"
                                       id="{{ $field }}"
                                       name="{{ $field }}"
                                       class="form-control @error($field) is-invalid @enderror"
                                       required
                                       autocomplete="{{ $autocomplete }}"
                                       @if ($loop->first) autofocus @endif>

                                <button type="button"
                                        class="btn btn-outline-secondary"
                                        data-password-toggle="{{ $field }}"
                                        aria-label="Show password"
                                        title="Show password">
                                    <i class="bi bi-eye"></i>
                                </button>

                                @error($field)
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            @if ($field === 'password')
                                <div class="form-text">
                                    At least 8 characters, including a letter and a number.
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-key me-1"></i>Change password
                    </button>
                </div>
            </form>
        </div>

        <div class="col-12 col-lg-6">
            {{-- CLIENT DECISION (2026-08-31): the password is kept in readable
                 form so it can be looked up here and in the database. The
                 warning below is not boilerplate — it is the trade being made,
                 stated where the person making it will see it. --}}
            @php $readable = auth()->user()->readablePassword(); @endphp

            <div class="card border-warning mb-3">
                <div class="card-header bg-warning-subtle border-warning">
                    <strong><i class="bi bi-eye me-1"></i>Your current password</strong>
                </div>
                <div class="card-body">
                    @if ($readable)
                        <div class="input-group mb-2">
                            <input type="password"
                                   id="stored-password"
                                   class="form-control font-monospace"
                                   value="{{ $readable }}"
                                   readonly>
                            <button type="button"
                                    class="btn btn-outline-secondary"
                                    data-password-toggle="stored-password"
                                    aria-label="Show password"
                                    title="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="small text-muted">
                            Also readable directly in the database, in
                            <code>users.password_plain</code>.
                        </div>
                    @else
                        <p class="mb-0 text-muted small">
                            No readable copy is stored for this account yet &mdash; it was set
                            before the panel started keeping one, and a password cannot be
                            recovered from its hash. Change it once using the form and it will
                            appear here.
                        </p>
                    @endif
                </div>
            </div>

            <div class="alert alert-danger small d-flex gap-2">
                <i class="bi bi-shield-exclamation mt-1 fs-5"></i>
                <div>
                    <strong>This password is stored unencrypted, at your request.</strong>
                    Anyone who can read the database &mdash; your hosting provider, anyone
                    holding a backup or an exported dump, any developer given access later
                    &mdash; can read it and sign in as you. If you use this password anywhere
                    else, change it there.
                    <div class="mt-2">
                        Removing the <code>users.password_plain</code> column breaks nothing:
                        sign-in uses the hash in <code>users.password</code> and always has.
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white"><strong>Worth knowing</strong></div>
                <div class="card-body small">
                    <p class="mb-2">
                        <strong>Your current password is required</strong> even though you are
                        already signed in. That is what stops an unattended browser being used
                        to take the account over.
                    </p>

                    <p class="mb-2">
                        This changes <strong>your own</strong> password only. There is no screen
                        for changing somebody else's.
                    </p>

                    <p class="mb-2 text-muted">
                        Any other browser already signed in as you stays signed in until that
                        session expires &mdash; changing the password does not kick it out. If
                        you are worried about a machine you left signed in, sign out there.
                    </p>

                    <p class="mb-0 text-muted">
                        Locked out with no readable copy? Reset it from the server with
                        <code>php artisan app:set-password</code>.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
