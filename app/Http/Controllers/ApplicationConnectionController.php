<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\ApplicationConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationConnectionController extends Controller
{
    public function index(Request $request, ApplicationConnectionService $connections): Response
    {
        $attributes = $request->validate(['project_id' => ['nullable', 'integer', 'min:1']]);
        $projects = $connections->projects($request->user());
        $selected = isset($attributes['project_id'])
            ? $projects->firstWhere('id', (int) $attributes['project_id'])
            : $projects->first();

        abort_if(isset($attributes['project_id']) && $selected === null, 404);

        return Inertia::render('Connection', [
            'portalUrl' => rtrim(url('/'), '/'),
            'projects' => $projects->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->all(),
            'selectedProjectId' => $selected?->id,
            'environments' => $selected ? $connections->environments($selected, $request->user()) : [],
        ]);
    }

    public function update(Request $request, Project $project, ProjectEnvironment $environment): RedirectResponse
    {
        abort_unless($project->isManagedByUser($request->user()->id) && $environment->project_id === $project->id, 404);
        $attributes = $request->validate(['feedback_enabled' => ['required', 'boolean']]);
        $environment->external_widget_enabled = (bool) $attributes['feedback_enabled'];

        if (! $environment->external_widget_enabled) {
            $environment->external_widget_guest_submissions_enabled = false;
        }

        $environment->save();

        return to_route('connection.index', ['project_id' => $project->id]);
    }
}
