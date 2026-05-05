<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ShiftMcpAccess;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListProjectsTool extends Tool
{
    protected string $name = 'list_projects';

    protected string $description = 'List SHIFT projects with client, organisation, environment, and task-count context. API tokens are never returned.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $access = app(ShiftMcpAccess::class);
        $principal = $access->principal($request);

        if (! $principal) {
            return Response::error('SHIFT MCP is not configured with an authenticated user, or the configured project is not visible to that user.');
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $search = $validated['search'] ?? null;

        $projects = $access->projectsFor($principal)
            ->with(['author', 'client.organisation', 'organisation', 'environments'])
            ->withCount([
                'tasks as task_count' => fn ($query) => $query->visibleTo($principal->user->id),
                'tasks as open_tasks_count' => fn ($query) => $query
                    ->visibleTo($principal->user->id)
                    ->where('status', '!=', 'completed'),
            ])
            ->when($search, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('organisation', fn ($organisationQuery) => $organisationQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(function (Project $project): array {
                $organisation = $project->organisation ?? $project->client?->organisation;

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'client' => $project->client ? [
                        'id' => $project->client->id,
                        'name' => $project->client->name,
                    ] : null,
                    'organisation' => $organisation ? [
                        'id' => $organisation->id,
                        'name' => $organisation->name,
                    ] : null,
                    'author' => $project->author ? [
                        'id' => $project->author->id,
                        'name' => $project->author->name,
                        'email' => $project->author->email,
                    ] : null,
                    'task_count' => $project->tasks_count,
                    'open_task_count' => $project->open_tasks_count,
                    'environments' => $project->environments
                        ->map(fn ($environment): array => [
                            'environment' => $environment->environment,
                            'url' => $environment->url,
                        ])
                        ->values()
                        ->all(),
                    'created_at' => $project->created_at?->toISOString(),
                    'updated_at' => $project->updated_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return Response::structured([
            'projects' => $projects,
            'count' => count($projects),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Optional project, client, or organisation search text.'),
            'limit' => $schema->integer()
                ->description('Maximum number of projects to return, between 1 and 50.')
                ->default(20),
        ];
    }
}
