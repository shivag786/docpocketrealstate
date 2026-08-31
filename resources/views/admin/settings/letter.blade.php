@extends('layouts.admin')

@section('title', 'Welcome Letter')
@section('page-title', 'Welcome Letter')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.settings.edit') }}">Settings</a></li>
    <li class="breadcrumb-item active" aria-current="page">Welcome Letter</li>
@endsection

@section('page-actions')
    @if ($sample)
        <a href="{{ route('admin.members.letter', $sample) }}" target="_blank" rel="noopener"
           class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye me-1"></i>Preview with {{ $sample->member_code }}
        </a>
    @endif
@endsection

@section('content')
    @include('admin.settings._nav')

    @php $fields = $settings->letterFields(); @endphp

    <form method="POST" action="{{ route('admin.settings.letter.update') }}">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <div class="card mb-3">
                    <div class="card-header bg-white">
                        <strong>Rows printed on the letter</strong>
                    </div>

                    <div class="list-group list-group-flush">
                        @foreach ($labels as $field => [$label, $help])
                            <label class="list-group-item d-flex justify-content-between align-items-start gap-3"
                                   for="field-{{ $field }}">
                                <span>
                                    <span class="fw-semibold d-block">{{ $label }}</span>
                                    <span class="small text-muted">{{ $help }}</span>
                                </span>

                                <span class="form-check form-switch mt-1 flex-shrink-0">
                                    {{-- An unchecked switch posts nothing, so a
                                         hidden 0 rides in front of every one.
                                         Without it a row could be switched on
                                         but never off. --}}
                                    <input type="hidden" name="fields[{{ $field }}]" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="field-{{ $field }}"
                                           name="fields[{{ $field }}]"
                                           value="1"
                                           @checked($fields[$field] ?? false)>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="card-footer bg-white">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Save
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card mb-3">
                    <div class="card-header bg-white"><strong>What the letter looks like</strong></div>
                    <div class="card-body">
                        {{-- A shape sketch, not a rendering: the real thing is
                             one click away under Preview, and a second HTML
                             copy of the layout here would be one more place to
                             drift out of step with the PDF template. --}}
                        <div class="border rounded p-3 small bg-light-subtle">
                            <div class="text-muted">Letterhead &mdash; logo, {{ $settings->name() }}</div>
                            <hr class="my-2">
                            <div class="fw-semibold mb-2">Welcome to {{ $settings->name() }}</div>
                            <ul class="list-unstyled mb-2">
                                <li class="text-muted">Member name &mdash; <em>always</em></li>
                                <li class="text-muted">Member ID &mdash; <em>always</em></li>
                                @foreach ($labels as $field => [$label, $help])
                                    <li class="{{ ($fields[$field] ?? false) ? '' : 'text-decoration-line-through text-body-tertiary' }}">
                                        {{ $label }}
                                    </li>
                                @endforeach
                                <li class="text-muted">Joining date &mdash; <em>always</em></li>
                            </ul>
                            <hr class="my-2">
                            <div class="text-muted">Signature &amp; seal space</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white"><strong>Good to know</strong></div>
                    <div class="card-body small">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-file-earmark-check me-1"></i>
                            <strong>The letter is always one page.</strong> Even with every
                            row switched on it fits a single A4 sheet, and the signature and
                            seal area at the foot is reserved space that never gets pushed
                            onto a second page.
                        </div>

                        <p class="mb-2">
                            <strong>Name, Member ID and joining date cannot be hidden.</strong>
                            A letter that cannot say who it is for, what their code is, or
                            when they joined is not a welcome letter.
                        </p>

                        <p class="mb-0 text-muted">
                            A row switched on is still skipped for a member who has nothing
                            to print in it &mdash; a member with no sponsor, or no blood group
                            recorded. Email is the exception: it prints
                            &ldquo;Not recorded&rdquo;, because a blank there reads as an
                            oversight rather than a fact.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
