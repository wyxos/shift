<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ApplicationConnectionService
{
    /** @return Collection<int, Project> */
    public function projects(User $user): Collection
    {
        return Project::query()->visibleTo($user->id)
            ->with(['client.organisation', 'organisation'])
            ->orderBy('name')->get()
            ->filter(fn (Project $project): bool => $project->isManagedByUser($user->id))
            ->values();
    }

    /** @return array<int, array<string, mixed>> */
    public function environments(Project $project, User $user): array
    {
        return $project->environments()->orderBy('environment')->get()
            ->map(fn (ProjectEnvironment $environment): array => [
                'id' => $environment->id,
                'environment' => $environment->environment,
                'label' => app(ProjectEnvironmentService::class)->label($environment->environment),
                'url' => $environment->url,
                'feedback_enabled' => $environment->external_widget_enabled,
                'feedback_report' => $this->report($project, $environment, $user, false),
                'error_report' => $this->report($project, $environment, $user, true),
            ])->all();
    }

    /** @return array{id: int, title: string, url: string}|null */
    private function report(Project $project, ProjectEnvironment $environment, User $user, bool $error): ?array
    {
        $baseUrl = rtrim($environment->url, '/');
        $escapedUrl = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $baseUrl);
        $pagePattern = $escapedUrl.'/%';
        $queryPattern = $escapedUrl.'?%';
        $fragmentPattern = $escapedUrl.'#%';
        $query = Task::query()->visibleTo($user->id)->where('project_id', $project->id)
            ->whereHas('metadata', function (Builder $query) use ($environment, $baseUrl, $pagePattern, $queryPattern, $fragmentPattern, $error): void {
                $query->where('environment', $environment->environment)
                    ->where(fn (Builder $urls): Builder => $urls->where('url', $baseUrl)
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pagePattern])
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$queryPattern])
                        ->orWhereRaw("url LIKE ? ESCAPE '!'", [$fragmentPattern]));

                if (! $error) {
                    $query->where('source', 'embedded_widget');
                }
            });

        if ($error) {
            $query->whereNotNull('error_signature');
        } else {
            $query->whereNull('error_signature');
        }

        $task = $query->latest('id')->first(['id', 'title']);

        return $task ? [
            'id' => $task->id,
            'title' => $task->title,
            'url' => route('tasks.index', ['task' => $task->id]),
        ] : null;
    }
}
