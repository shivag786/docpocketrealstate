@extends('layouts.admin')

@section('title', 'Developer')
@section('page-title', 'Developer Tools')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.settings.edit') }}">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Developer</li>
@endsection

@section('content')
    @include('admin.settings._nav')

    @php $total = array_sum($counts); @endphp

    <div class="alert alert-warning d-flex gap-3 align-items-start">
        <i class="bi bi-tools fs-4"></i>
        <div>
            <strong>This page exists because <code>DEVELOPER_TOOLS=true</code> is set in
            <code>.env</code>.</strong>
            <div class="small mt-1">
                Set it to <code>false</code> and this page, and the route behind it, stop
                existing &mdash; the URL returns 404 rather than a refusal. Do that at
                go-live, after the final reset.
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card border-danger">
                <div class="card-header bg-danger-subtle border-danger">
                    <strong class="text-danger-emphasis">
                        <i class="bi bi-exclamation-octagon me-1"></i>Reset the system
                    </strong>
                </div>

                <div class="card-body">
                    <p>
                        Deletes <strong>every member, sale and calculated figure</strong> so the
                        system can be handed over empty. Use this once, after testing with real
                        data and before go-live.
                    </p>

                    <div class="alert alert-danger small d-flex gap-2">
                        <i class="bi bi-exclamation-triangle mt-1"></i>
                        <div>
                            <strong>This cannot be undone.</strong> There is no backup and no
                            restore. Rewards already marked paid are deleted along with
                            everything else. Take a database dump first if you might want any
                            of it back.
                        </div>
                    </div>

                    @if ($total === 0)
                        <div class="alert alert-success mb-0">
                            <i class="bi bi-check-circle me-1"></i>
                            The system is already empty &mdash; there is nothing to clear.
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.settings.developer.reset') }}">
                            @csrf

                            <label for="confirmation" class="form-label fw-semibold">
                                Type <code>{{ $phrase }}</code> to confirm
                            </label>

                            <div class="input-group mb-2" style="max-width: 320px;">
                                <span class="input-group-text bg-white text-danger">
                                    <i class="bi bi-shield-exclamation"></i>
                                </span>
                                {{-- The button stays disabled until the word matches
                                     exactly. The server enforces the same thing in
                                     ResetSystemRequest; this only saves the operator a
                                     round trip. --}}
                                <input type="text"
                                       id="confirmation"
                                       name="confirmation"
                                       class="form-control font-monospace @error('confirmation') is-invalid @enderror"
                                       autocomplete="off"
                                       placeholder="{{ $phrase }}"
                                       data-confirm-phrase="{{ $phrase }}"
                                       data-confirm-target="reset-button">
                                @error('confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <button type="submit"
                                    id="reset-button"
                                    class="btn btn-danger"
                                    disabled
                                    data-confirm-submit="Delete all {{ number_format($total) }} rows and reset the system? This cannot be undone."
                                    data-confirm-title="Reset the system"
                                    data-confirm-variant="danger">
                                <i class="bi bi-trash3 me-1"></i>Reset the system
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>What will be deleted</strong>
                    <span class="badge text-bg-danger">{{ number_format($total) }} rows</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm mb-0 small">
                        <tbody>
                            @foreach ($counts as $table => $count)
                                <tr class="{{ $count === 0 ? 'text-body-tertiary' : '' }}">
                                    <td class="font-monospace">{{ $table }}</td>
                                    <td class="text-end tabular">{{ number_format($count) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white"><strong>What survives</strong></div>
                <div class="card-body small">
                    <ul class="ps-3 mb-3">
                        @foreach ($preserved as $table)
                            <li class="font-monospace">{{ $table }}</li>
                        @endforeach
                    </ul>
                    <p class="mb-0 text-muted">
                        Your admin login survives, so you are not locked out of the panel you
                        just cleared, and your company settings survive, so the logo,
                        signatory and designation list do not have to be entered again.
                        Member codes restart at
                        <strong>{{ config('members.code.prefix') }}{{ config('members.code.start_at') }}</strong>.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
