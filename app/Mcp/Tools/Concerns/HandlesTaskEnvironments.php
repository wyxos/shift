<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Services\ProjectEnvironmentService;

trait HandlesTaskEnvironments
{
    /**
     * @return array{0: ProjectEnvironment|null, 1: string|null}
     */
    private function resolveMcpProjectEnvironment(Project $project, ?string $environment): array
    {
        $projectEnvironmentService = app(ProjectEnvironmentService::class);
        $normalizedEnvironment = $projectEnvironmentService->normalizeEnvironment($environment);

        if ($normalizedEnvironment === null) {
            return [null, null];
        }

        $registration = $projectEnvironmentService->find($project, $normalizedEnvironment);

        if (! $registration instanceof ProjectEnvironment) {
            return [null, 'The selected environment is not registered for this project.'];
        }

        return [$registration, null];
    }

    private function syncMcpTaskEnvironment(Task $task, ?ProjectEnvironment $environment): void
    {
        if (! $environment instanceof ProjectEnvironment) {
            $task->metadata()->delete();

            return;
        }

        $task->metadata()->updateOrCreate(
            ['task_id' => $task->id],
            [
                'environment' => $environment->environment,
                'url' => $environment->url,
            ],
        );
    }

    /**
     * @return array<int, array{environment: string, label: string, url: string|null}>
     */
    private function mcpProjectEnvironmentOptions(Project $project): array
    {
        $project->loadMissing('environments');
        $projectEnvironmentService = app(ProjectEnvironmentService::class);

        return $project->environments
            ->sortBy('environment')
            ->map(fn (ProjectEnvironment $environment): array => [
                'environment' => $environment->environment,
                'label' => $projectEnvironmentService->label($environment->environment),
                'url' => $environment->url,
            ])
            ->values()
            ->all();
    }
}
