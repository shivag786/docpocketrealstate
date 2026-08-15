@php
    /**
     * Shared by create and edit.
     *
     * $member          Member|null
     * $statuses        array<string,string>
     * $sponsor         Member|null   pre-selected sponsor
     * $canChangeSponsor bool
     */
    $member ??= null;
    $sponsor ??= $member?->sponsor;
    $canChangeSponsor ??= true;
@endphp

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header bg-white"><strong>Member details</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="name" class="form-label required-mark">Full name</label>
                        <input type="text"
                               id="name"
                               name="name"
                               value="{{ old('name', $member?->name) }}"
                               class="form-control @error('name') is-invalid @enderror"
                               required
                               autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="mobile" class="form-label required-mark">Mobile</label>
                        <input type="text"
                               id="mobile"
                               name="mobile"
                               value="{{ old('mobile', $member?->mobile) }}"
                               class="form-control @error('mobile') is-invalid @enderror"
                               required>
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="email" class="form-label">Email <span class="text-muted small">(optional)</span></label>
                        <input type="email"
                               id="email"
                               name="email"
                               value="{{ old('email', $member?->email) }}"
                               class="form-control @error('email') is-invalid @enderror">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label for="address" class="form-label">Address <span class="text-muted small">(optional)</span></label>
                        <textarea id="address"
                                  name="address"
                                  rows="2"
                                  class="form-control @error('address') is-invalid @enderror">{{ old('address', $member?->address) }}</textarea>
                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="joining_date" class="form-label required-mark">Joining date</label>
                        <input type="date"
                               id="joining_date"
                               name="joining_date"
                               value="{{ old('joining_date', $member?->joining_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"
                               max="{{ now()->format('Y-m-d') }}"
                               class="form-control @error('joining_date') is-invalid @enderror"
                               required>
                        @error('joining_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="status" class="form-label required-mark">Status</label>
                        <select id="status"
                                name="status"
                                class="form-select @error('status') is-invalid @enderror"
                                required>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}"
                                    @selected(old('status', $member?->status?->value ?? 'active') === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header bg-white"><strong>Sponsor</strong></div>
            <div class="card-body">
                @if (! $canChangeSponsor)
                    <div class="alert alert-warning small d-flex gap-2 mb-3">
                        <i class="bi bi-lock mt-1"></i>
                        <div>
                            The sponsor is locked because sales have been recorded against
                            this member. Changing it would alter upline rewards that were
                            already calculated.
                        </div>
                    </div>
                @endif

                {{-- The AJAX picker writes the chosen id here. --}}
                <input type="hidden"
                       id="sponsor_id"
                       name="sponsor_id"
                       value="{{ old('sponsor_id', $sponsor?->id) }}">

                <div data-sponsor-picker
                     data-search-url="{{ route('admin.members.search-sponsors') }}"
                     @if ($member) data-exclude="{{ $member->id }}" @endif
                     @if (! $canChangeSponsor) data-locked="1" @endif>

                    <div class="mb-2 @error('sponsor_id') is-invalid @enderror">
                        <label for="sponsor-search" class="form-label">Search for a sponsor</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="search"
                                   id="sponsor-search"
                                   class="form-control"
                                   placeholder="Member ID, name or mobile"
                                   autocomplete="off"
                                   @disabled(! $canChangeSponsor)
                                   data-sponsor-search>
                        </div>
                        <div class="form-text">
                            Leave empty to create a root member with no sponsor.
                        </div>
                    </div>

                    @error('sponsor_id')
                        <div class="alert alert-danger py-2 small">{{ $message }}</div>
                    @enderror

                    {{-- Results land here; never a full member dump. --}}
                    <div class="list-group list-group-flush d-none" data-sponsor-results></div>

                    <div class="mt-2 {{ $sponsor ? '' : 'd-none' }}" data-sponsor-selected>
                        <div class="border rounded p-2 d-flex justify-content-between align-items-center bg-light">
                            <div>
                                <div class="small text-muted">Selected sponsor</div>
                                <div class="fw-semibold" data-sponsor-name>{{ $sponsor?->name }}</div>
                                <div class="small text-muted" data-sponsor-code>{{ $sponsor?->member_code }}</div>
                            </div>
                            @if ($canChangeSponsor)
                                <button type="button" class="btn btn-sm btn-outline-danger" data-sponsor-clear title="Remove sponsor">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-2 {{ $sponsor ? 'd-none' : '' }}" data-sponsor-empty>
                        <div class="border rounded border-dashed p-2 text-center text-muted small">
                            No sponsor selected &mdash; this will be a root member.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
