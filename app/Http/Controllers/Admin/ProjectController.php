<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $projects = Project::query()
            ->withCount(['properties', 'sales'])
            ->search($request->query('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        return view('admin.projects.index', [
            'projects' => $projects,
            'statuses' => ProjectStatus::options(),
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('admin.projects.create', ['statuses' => ProjectStatus::options()]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());

        return redirect()
            ->route('admin.projects.show', $project)
            ->with('success', "Project \"{$project->name}\" was created.");
    }

    public function show(Project $project): View
    {
        return view('admin.projects.show', [
            'project' => $project->loadCount(['properties', 'sales']),
            'properties' => $project->properties()
                ->withCount('sales')
                ->orderBy('property_code')
                ->paginate(config('members.per_page'))
                ->withQueryString(),
        ]);
    }

    public function edit(Project $project): View
    {
        return view('admin.projects.edit', [
            'project' => $project,
            'statuses' => ProjectStatus::options(),
        ]);
    }

    public function update(StoreProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()
            ->route('admin.projects.show', $project)
            ->with('success', "Project \"{$project->name}\" was updated.");
    }

    public function destroy(Project $project): RedirectResponse
    {
        // Sales are permanent records; a project that has any must survive so
        // every reward stays traceable to its source.
        if ($project->sales()->exists()) {
            return back()->with('error', 'This project has recorded sales and cannot be deleted.');
        }

        if ($project->properties()->exists()) {
            return back()->with('error', 'This project still has properties. Remove them first.');
        }

        $project->delete();

        return redirect()
            ->route('admin.projects.index')
            ->with('success', 'Project deleted.');
    }
}
