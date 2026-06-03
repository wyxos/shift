<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExternalWidgetController extends Controller
{
    public function __construct(
        private readonly ExternalUserService $externalUserService,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    public function config(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'environment' => 'nullable|string|max:255',
        ]);

        $project = Project::query()
            ->with('environments')
            ->where('token', $attributes['project'])
            ->firstOrFail();
        $environment = $this->environmentRegistration($project, $attributes['environment'] ?? null);
        $widget = $this->widgetSettings($project, $environment);

        return response()->json([
            'widget_enabled' => $widget['enabled'],
            'guest_submissions_enabled' => $widget['guest_submissions_enabled'],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'widget' => [
                'enabled' => $widget['enabled'],
                'guest_submissions_enabled' => $widget['guest_submissions_enabled'],
                'environment' => $environment ? [
                    'id' => $environment->id,
                    'key' => $environment->environment,
                    'label' => $this->projectEnvironmentService->label($environment->environment),
                    'url' => $environment->url,
                ] : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'kind' => ['required', Rule::in(['task', 'feature', 'issue'])],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'anonymous' => 'required|boolean',
            'metadata' => 'nullable|array',
            'metadata.environment' => 'nullable|string|max:255',
            'metadata.url' => 'nullable|url',
            'user' => 'nullable|array',
            'user.id' => 'nullable',
            'user.name' => 'required_if:anonymous,false|string|max:255',
            'user.email' => 'required_if:anonymous,false|email',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'user.authenticated' => 'nullable|boolean',
        ]);

        $project = Project::query()
            ->with('environments')
            ->where('token', $attributes['project'])
            ->firstOrFail();

        $selectedEnvironment = $this->resolveTaskEnvironment($project, $attributes);
        $environment = $selectedEnvironment !== null
            ? $project->environments->firstWhere('environment', $selectedEnvironment)
            : null;
        $widget = $this->widgetSettings($project, $environment);

        $isAuthenticatedExternalUser = (bool) data_get($attributes, 'user.authenticated', false);
        $isGuestSubmission = $attributes['anonymous'] || ! $isAuthenticatedExternalUser;

        if (! $widget['enabled']) {
            return response()->json([
                'message' => $environment
                    ? 'The embedded widget is disabled for this environment.'
                    : 'The embedded widget is disabled for this project.',
            ], 403);
        }

        if ($isGuestSubmission && ! $widget['guest_submissions_enabled']) {
            return response()->json([
                'message' => $environment
                    ? 'Guest widget submissions are disabled for this environment.'
                    : 'Guest widget submissions are disabled for this project.',
            ], 403);
        }

        $sourceUrl = $this->sourceUrl($attributes);
        $submitter = $attributes['anonymous'] ? null : $this->externalUser($project, $attributes);

        $task = Task::query()->create([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'project_id' => $project->id,
            'status' => TaskStatus::Pending->value,
            'priority' => TaskPriority::Medium->value,
        ]);

        if ($submitter instanceof ExternalUser) {
            $task->submitter()->associate($submitter)->save();
        }

        $task->metadata()->create([
            'environment' => $selectedEnvironment ?? 'production',
            'url' => $sourceUrl,
            'source' => 'embedded_widget',
            'intake_type' => $attributes['kind'],
        ]);

        $task->load(['submitter', 'metadata']);

        return response()->json([
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            'description' => $task->description,
            'kind' => $task->metadata?->intake_type,
            'status' => $task->status,
            'priority' => $task->priority,
            'submitter' => $task->submitter ? [
                'name' => $task->submitter->name ?? null,
                'email' => $task->submitter->email ?? null,
            ] : null,
            'created_at' => $task->created_at?->toIso8601String(),
        ], 201);
    }

    private function resolveTaskEnvironment(Project $project, array $attributes): ?string
    {
        $environment = $this->projectEnvironmentService->normalizeEnvironment(
            data_get($attributes, 'metadata.environment') ?? data_get($attributes, 'user.environment')
        );

        if ($environment === null) {
            return null;
        }

        if (! $project->environments->contains('environment', $environment)) {
            throw ValidationException::withMessages([
                'metadata.environment' => 'The selected environment is not registered for this project.',
            ]);
        }

        return $environment;
    }

    private function environmentRegistration(Project $project, ?string $environment): ?ProjectEnvironment
    {
        $environment = $this->projectEnvironmentService->normalizeEnvironment($environment);

        if ($environment === null) {
            return null;
        }

        return $project->environments->firstWhere('environment', $environment);
    }

    /**
     * @return array{enabled: bool, guest_submissions_enabled: bool}
     */
    private function widgetSettings(Project $project, ?ProjectEnvironment $environment): array
    {
        if ($environment instanceof ProjectEnvironment) {
            return [
                'enabled' => $environment->external_widget_enabled,
                'guest_submissions_enabled' => $environment->external_widget_guest_submissions_enabled,
            ];
        }

        return [
            'enabled' => $project->external_widget_enabled,
            'guest_submissions_enabled' => $project->external_widget_guest_submissions_enabled,
        ];
    }

    private function sourceUrl(array $attributes): string
    {
        return $this->projectEnvironmentService->normalizeUrl(
            data_get($attributes, 'metadata.url') ?? data_get($attributes, 'user.url') ?? config('app.url')
        ) ?? (string) config('app.url');
    }

    private function externalUser(Project $project, array $attributes): ExternalUser
    {
        $email = (string) data_get($attributes, 'user.email');
        $environment = $this->projectEnvironmentService->normalizeEnvironment(
            data_get($attributes, 'user.environment') ?? data_get($attributes, 'metadata.environment') ?? 'production'
        );
        $registration = $environment !== null
            ? $project->environments->firstWhere('environment', $environment)
            : null;

        return $this->externalUserService->upsert($project, [
            'external_id' => data_get($attributes, 'user.id') ?? 'guest:'.hash('sha256', strtolower($email)),
            'name' => data_get($attributes, 'user.name'),
            'email' => $email,
            'environment' => $environment,
            'url' => data_get($attributes, 'user.url') ?? $registration?->url ?? config('app.url'),
        ]);
    }
}
