@php $project ??= null; @endphp

<div class="card">
    <div class="card-header bg-white"><strong>Project details</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-md-8">
                <label for="name" class="form-label required-mark">Project name</label>
                <input type="text" id="name" name="name"
                       value="{{ old('name', $project?->name) }}"
                       class="form-control @error('name') is-invalid @enderror" required autofocus>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12 col-md-4">
                <label for="status" class="form-label required-mark">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $project?->status?->value ?? 'active') === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Only active projects can be chosen for new sales.</div>
            </div>

            <div class="col-12">
                <label for="location" class="form-label">Location <span class="text-muted small">(optional)</span></label>
                <input type="text" id="location" name="location"
                       value="{{ old('location', $project?->location) }}"
                       class="form-control @error('location') is-invalid @enderror">
                @error('location')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <label for="description" class="form-label">Description <span class="text-muted small">(optional)</span></label>
                <textarea id="description" name="description" rows="3"
                          class="form-control @error('description') is-invalid @enderror">{{ old('description', $project?->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</div>
