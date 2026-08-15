@php
    $property ??= null;
    $selectedProject ??= null;
@endphp

<div class="card">
    <div class="card-header bg-white"><strong>Property / Site details</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="project_id" class="form-label required-mark">Project</label>
                <select id="project_id" name="project_id"
                        class="form-select @error('project_id') is-invalid @enderror" required>
                    <option value="">Select a project</option>
                    @foreach ($projects as $id => $name)
                        <option value="{{ $id }}"
                            @selected(old('project_id', $property?->project_id ?? $selectedProject) == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('project_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12 col-md-3">
                <label for="property_code" class="form-label required-mark">Property code</label>
                <input type="text" id="property_code" name="property_code"
                       value="{{ old('property_code', $property?->property_code) }}"
                       class="form-control @error('property_code') is-invalid @enderror" required>
                @error('property_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Unique within its project.</div>
            </div>

            <div class="col-12 col-md-3">
                <label for="status" class="form-label required-mark">Status</label>
                <select id="status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $property?->status?->value ?? 'active') === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Only active sites appear in sale entry.</div>
            </div>

            <div class="col-12">
                <label for="details" class="form-label">Details <span class="text-muted small">(optional)</span></label>
                <textarea id="details" name="details" rows="3"
                          class="form-control @error('details') is-invalid @enderror">{{ old('details', $property?->details) }}</textarea>
                @error('details')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</div>
