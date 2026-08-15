<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Models\Project;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PropertyController extends Controller
{
    public function index(Request $request): View
    {
        $properties = Property::query()
            ->with('project:id,name')
            ->withCount('sales')
            ->search($request->query('q'))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->query('project_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('project_id')
            ->orderBy('property_code')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        return view('admin.properties.index', [
            'properties' => $properties,
            'projects' => Project::orderBy('name')->pluck('name', 'id'),
            'statuses' => PropertyStatus::options(),
            'filters' => $request->only(['q', 'project_id', 'status']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.properties.create', [
            'projects' => Project::active()->orderBy('name')->pluck('name', 'id'),
            'statuses' => PropertyStatus::options(),
            'selectedProject' => $request->query('project_id'),
        ]);
    }

    public function store(StorePropertyRequest $request): RedirectResponse
    {
        $property = Property::create($request->validated());

        return redirect()
            ->route('admin.properties.index', ['project_id' => $property->project_id])
            ->with('success', "Property {$property->property_code} was created.");
    }

    public function edit(Property $property): View
    {
        return view('admin.properties.edit', [
            'property' => $property,
            'projects' => Project::orderBy('name')->pluck('name', 'id'),
            'statuses' => PropertyStatus::options(),
        ]);
    }

    public function update(StorePropertyRequest $request, Property $property): RedirectResponse
    {
        $property->update($request->validated());

        return redirect()
            ->route('admin.properties.index', ['project_id' => $property->project_id])
            ->with('success', "Property {$property->property_code} was updated.");
    }

    public function destroy(Property $property): RedirectResponse
    {
        if ($property->sales()->exists()) {
            return back()->with('error', 'This property has recorded sales and cannot be deleted.');
        }

        $property->delete();

        return redirect()
            ->route('admin.properties.index')
            ->with('success', 'Property deleted.');
    }

    /**
     * Active properties for a project — feeds the dependent dropdown on the
     * daily sale entry form.
     */
    public function forProject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        $properties = Property::query()
            ->where('project_id', $validated['project_id'])
            ->active()
            ->orderBy('property_code')
            ->get(['id', 'property_code', 'details'])
            ->map(fn (Property $p) => [
                'id' => $p->id,
                'property_code' => $p->property_code,
                'details' => $p->details,
            ]);

        return ApiResponse::success($properties);
    }
}
