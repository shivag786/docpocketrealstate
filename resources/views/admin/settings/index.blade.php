@extends('layouts.admin')

@section('title', 'Company Settings')
@section('page-title', 'Company Settings')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Settings</li>
@endsection

@section('content')
    @include('admin.settings._nav')

    <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <div class="card mb-3">
                    <div class="card-header bg-white"><strong>Identity</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="company_name" class="form-label required-mark">Company name</label>
                                <input type="text" id="company_name" name="company_name"
                                       value="{{ old('company_name', $settings->company_name) }}"
                                       class="form-control @error('company_name') is-invalid @enderror"
                                       maxlength="150" required autofocus>
                                @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">
                                    Printed at the top of every welcome letter and ID card.
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="tagline" class="form-label">
                                    Tagline <span class="text-muted small">(optional)</span>
                                </label>
                                <input type="text" id="tagline" name="tagline"
                                       value="{{ old('tagline', $settings->tagline) }}"
                                       class="form-control @error('tagline') is-invalid @enderror"
                                       maxlength="200">
                                @error('tagline')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12">
                                <label for="address" class="form-label">
                                    Address <span class="text-muted small">(optional)</span>
                                </label>
                                <textarea id="address" name="address" rows="2"
                                          class="form-control @error('address') is-invalid @enderror">{{ old('address', $settings->address) }}</textarea>
                                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="text" id="phone" name="phone"
                                       value="{{ old('phone', $settings->phone) }}"
                                       class="form-control @error('phone') is-invalid @enderror" maxlength="40">
                                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" id="email" name="email"
                                       value="{{ old('email', $settings->email) }}"
                                       class="form-control @error('email') is-invalid @enderror">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="website" class="form-label">Website</label>
                                <input type="text" id="website" name="website"
                                       value="{{ old('website', $settings->website) }}"
                                       class="form-control @error('website') is-invalid @enderror">
                                @error('website')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ------------------------------------------------------------
                     Designations. Edited as free text, one per line, because it
                     is a short ordered list an admin rewrites as a whole.
                ------------------------------------------------------------- --}}
                <div class="card mb-3">
                    <div class="card-header bg-white"><strong>Member designations</strong></div>
                    <div class="card-body">
                        <label for="designations" class="form-label required-mark">One designation per line</label>
                        <textarea id="designations" name="designations" rows="7"
                                  class="form-control font-monospace @error('designations') is-invalid @enderror"
                                  required>{{ old('designations', implode(PHP_EOL, $settings->designationOptions())) }}</textarea>
                        @error('designations')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        <div class="form-text">
                            The order here is the order the member form offers them.
                            <strong>{{ config('company.designations.default', 'Sales Advisor') }}</strong>
                            is the default for a new member and is always kept in the list.
                        </div>

                        @if ($designationCounts->isNotEmpty())
                            <div class="alert alert-info small mt-3 mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Removing a line stops it being offered to <em>new</em> members. Members
                                who already hold it keep it, and their record stays editable.
                                <div class="mt-2">
                                    @foreach ($designationCounts as $name => $count)
                                        <span class="badge text-bg-light border me-1">
                                            {{ $name }} &middot; {{ number_format($count) }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header bg-white"><strong>Authorised signatory</strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="authority_name" class="form-label">Name</label>
                                <input type="text" id="authority_name" name="authority_name"
                                       value="{{ old('authority_name', $settings->authority_name) }}"
                                       class="form-control @error('authority_name') is-invalid @enderror"
                                       maxlength="150">
                                @error('authority_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="authority_designation" class="form-label">Designation</label>
                                <input type="text" id="authority_designation" name="authority_designation"
                                       value="{{ old('authority_designation', $settings->authority_designation) }}"
                                       class="form-control @error('authority_designation') is-invalid @enderror"
                                       maxlength="100" placeholder="e.g. Managing Director">
                                @error('authority_designation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            Printed under the signature line on the welcome letter. Leave the name
                            blank to print an empty line for a wet signature.
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-save me-1"></i>Save settings
                </button>
            </div>

            {{-- ----------------------------------------------------------------
                 Images. Shown at roughly the size they are actually printed, so
                 an admin can see a logo is illegible before a hundred cards are.
            ----------------------------------------------------------------- --}}
            <div class="col-12 col-lg-5">
                @foreach ([
                    ['logo', 'Company logo', $settings->logoUrl(), 'Appears on the letter, the ID card and the sidebar. A transparent PNG works best.'],
                    ['signature', 'Signature image', $settings->signatureUrl(), 'Optional. Printed above the signatory name on the welcome letter.'],
                ] as [$field, $label, $url, $help])
                    <div class="card mb-3">
                        <div class="card-header bg-white"><strong>{{ $label }}</strong></div>
                        <div class="card-body">
                            <div class="border rounded d-flex align-items-center justify-content-center bg-light-subtle mb-3"
                                 style="min-height: 110px;">
                                @if ($url)
                                    <img src="{{ $url }}" alt="{{ $label }}"
                                         style="max-height: 96px; max-width: 100%;">
                                @else
                                    <span class="text-muted small">Nothing uploaded</span>
                                @endif
                            </div>

                            <input type="file" id="{{ $field }}" name="{{ $field }}"
                                   class="form-control @error($field) is-invalid @enderror"
                                   accept="image/png,image/jpeg">
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror

                            <div class="form-text">
                                {{ $help }}
                                PNG or JPG, up to {{ config('company.uploads.max_kb', 1024) }} KB.
                                Leave empty to keep the current image.
                            </div>

                            @if ($url)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="remove_{{ $field }}" name="remove_{{ $field }}">
                                    <label class="form-check-label small" for="remove_{{ $field }}">
                                        Remove the current image
                                    </label>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

                <div class="card">
                    <div class="card-header bg-white"><strong>What these affect</strong></div>
                    <div class="card-body small">
                        <div class="alert alert-success mb-3">
                            <i class="bi bi-shield-check me-1"></i>
                            <strong>No figure changes.</strong> Nothing on this screen is read by
                            any reward engine. It is the letterhead, not the arithmetic.
                        </div>
                        <p class="mb-2">Saved values are used by:</p>
                        <ul class="ps-3 mb-0">
                            <li>the member welcome letter and ID card</li>
                            <li>the panel heading and the sidebar brand</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
