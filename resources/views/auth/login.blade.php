<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Sign in &middot; {{ config('app.name') }}</title>

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="text-center text-white mb-4">
                <i class="bi bi-building-fill-check fs-1"></i>
                <h1 class="h4 mt-2 mb-0">{{ config('app.name') }}</h1>
                <p class="small mb-0 opacity-75">Sales &amp; Team Reward Management</p>
            </div>

            <div class="card shadow">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1">Sign in</h2>
                    <p class="text-muted small mb-4">Back office access for Admin and Manager accounts.</p>

                    @if (session('success'))
                        <div class="alert alert-success py-2 small" role="alert">{{ session('success') }}</div>
                    @endif

                    @if (session('error'))
                        <div class="alert alert-danger py-2 small" role="alert">{{ session('error') }}</div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" novalidate>
                        @csrf

                        {{-- Pre-filled from config('company.login.default_email')
                             so a single-operator install only types a password.
                             `old()` still wins after a failed attempt, so a
                             corrected address is not thrown away. Editable, not
                             readonly: a second operator has to be able to sign
                             in on the same machine. --}}
                        <div class="mb-3">
                            <label for="email" class="form-label required-mark">Email address</label>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   value="{{ old('email', config('company.login.default_email')) }}"
                                   class="form-control @error('email') is-invalid @enderror"
                                   required
                                   autocomplete="username">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label required-mark">Password</label>

                            {{-- The invalid-feedback must sit INSIDE the
                                 input-group, or Bootstrap will not show it:
                                 it only reveals a sibling of the .is-invalid
                                 control, and the button would otherwise be in
                                 the way. --}}
                            <div class="input-group">
                                <input type="password"
                                       id="password"
                                       name="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       required
                                       autofocus
                                       autocomplete="current-password">

                                <button type="button"
                                        class="btn btn-outline-secondary"
                                        data-password-toggle="password"
                                        aria-label="Show password"
                                        title="Show password">
                                    <i class="bi bi-eye"></i>
                                </button>

                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check mb-4">
                            <input type="checkbox" id="remember" name="remember" value="1" class="form-check-input">
                            <label for="remember" class="form-check-label">Keep me signed in</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
                        </button>
                    </form>
                </div>
            </div>

            <p class="text-center text-white-50 small mt-3 mb-0">
                Network members do not sign in. All entries are made by staff.
            </p>
        </div>
    </div>
</body>
</html>
