<?php

namespace App\Services;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExternalUserService
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_CLIENT_DEVELOPER = 'client_developer';

    public const ROLE_SHIFT_LEAD_DEVELOPER = 'shift_lead_developer';

    public const ROLE_SHIFT_DEVELOPER = 'shift_developer';

    public const ROLE_USER = 'user';

    public const ROLE_GUEST = 'guest';

    private const VIEW_ALL_PROJECT_ITEM_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_SHIFT_LEAD_DEVELOPER,
    ];

    private const VIEW_OWNER_AND_ASSIGNED_PROJECT_ITEM_ROLES = [
        self::ROLE_CLIENT_DEVELOPER,
        self::ROLE_SHIFT_DEVELOPER,
    ];

    private const REQUIREMENT_SUBMITTER_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_CLIENT_DEVELOPER,
        self::ROLE_SHIFT_LEAD_DEVELOPER,
        self::ROLE_SHIFT_DEVELOPER,
    ];

    private const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_CLIENT_DEVELOPER,
        self::ROLE_SHIFT_LEAD_DEVELOPER,
        self::ROLE_SHIFT_DEVELOPER,
        self::ROLE_USER,
        self::ROLE_GUEST,
    ];

    public function __construct(
        private readonly ProjectEnvironmentService $projectEnvironmentService,
    ) {}

    public function find(Project $project, mixed $externalId, ?string $environment, ?string $url): ?ExternalUser
    {
        $normalizedId = $this->normalizeExternalId($externalId);
        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($environment);
        $normalizedUrl = $this->projectEnvironmentService->normalizeUrl($url);

        if ($normalizedId === null || $normalizedEnvironment === null || $normalizedUrl === null) {
            return null;
        }

        return ExternalUser::query()
            ->where('project_id', $project->id)
            ->where('external_id', $normalizedId)
            ->where('environment', $normalizedEnvironment)
            ->where('url', $normalizedUrl)
            ->first();
    }

    public function upsert(Project $project, array $attributes): ExternalUser
    {
        $externalId = $this->normalizeExternalId($attributes['external_id'] ?? null);
        $environment = $this->projectEnvironmentService->normalizeEnvironment($attributes['environment'] ?? null);
        $url = $this->projectEnvironmentService->normalizeUrl($attributes['url'] ?? null);

        if ($externalId === null || $environment === null || $url === null) {
            throw ValidationException::withMessages([
                'external_collaborators' => 'External collaborators must include a stable identity.',
            ]);
        }

        $values = [
            'name' => $this->normalizeString($attributes['name'] ?? null) ?? 'External User',
            'email' => $this->normalizeString($attributes['email'] ?? null),
        ];

        if (array_key_exists('role', $attributes)) {
            $values['role'] = $this->normalizeRole($attributes['role']) ?? self::ROLE_USER;
        }

        return ExternalUser::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'external_id' => $externalId,
                'environment' => $environment,
                'url' => $url,
            ],
            $values,
        );
    }

    public function role(ExternalUser $externalUser): string
    {
        return $this->normalizeRole($externalUser->getAttribute('role')) ?? self::ROLE_USER;
    }

    public function canViewAllProjectItems(ExternalUser $externalUser): bool
    {
        return in_array($this->role($externalUser), self::VIEW_ALL_PROJECT_ITEM_ROLES, true);
    }

    public function canViewOwnerAndAssignedProjectItems(ExternalUser $externalUser): bool
    {
        return in_array($this->role($externalUser), self::VIEW_OWNER_AND_ASSIGNED_PROJECT_ITEM_ROLES, true);
    }

    public function canSubmitRequirements(ExternalUser $externalUser): bool
    {
        return in_array($this->role($externalUser), self::REQUIREMENT_SUBMITTER_ROLES, true);
    }

    public function constrainVisibleProjectItems(Builder $query, ExternalUser $externalUser): Builder
    {
        if ($this->canViewAllProjectItems($externalUser)) {
            return $query;
        }

        return $query->where(function (Builder $visibilityQuery) use ($externalUser) {
            $visibilityQuery->whereHasMorph('submitter', [ExternalUser::class], function (Builder $submitterQuery) use ($externalUser) {
                $submitterQuery->where('external_users.id', $externalUser->id);
            });

            if (! $this->canViewOwnerAndAssignedProjectItems($externalUser)) {
                return;
            }

            $visibilityQuery
                ->orWhereHasMorph('submitter', [ExternalUser::class], function (Builder $submitterQuery) {
                    $submitterQuery->where('external_users.role', self::ROLE_OWNER);
                })
                ->orWhereHas('externalCollaborators', function (Builder $collaboratorQuery) use ($externalUser) {
                    $collaboratorQuery->where('external_users.id', $externalUser->id);
                });
        });
    }

    public function canViewProjectItem(Task $task, ExternalUser $externalUser): bool
    {
        if ($this->canViewAllProjectItems($externalUser)) {
            return true;
        }

        if ($this->isSubmitter($task, $externalUser)) {
            return true;
        }

        if (! $this->canViewOwnerAndAssignedProjectItems($externalUser)) {
            return false;
        }

        $submitter = $task->submitter;

        if ($submitter instanceof ExternalUser && $this->role($submitter) === self::ROLE_OWNER) {
            return true;
        }

        return $task->externalCollaborators()
            ->where('external_users.id', $externalUser->id)
            ->exists();
    }

    public function canMutateProjectItem(Task $task, ExternalUser $externalUser): bool
    {
        return $this->isSubmitter($task, $externalUser);
    }

    public function canCommentOnProjectItem(Task $task, ExternalUser $externalUser): bool
    {
        return $this->canViewProjectItem($task, $externalUser);
    }

    public function capabilityFlags(Task $task, ?ExternalUser $externalUser): array
    {
        $canMutate = $externalUser instanceof ExternalUser
            && $this->canMutateProjectItem($task, $externalUser);

        return [
            'can_edit' => $canMutate,
            'can_update_status' => $canMutate,
            'can_update_priority' => $canMutate,
            'can_delete' => $canMutate,
            'can_comment' => $externalUser instanceof ExternalUser
                && $this->canCommentOnProjectItem($task, $externalUser),
        ];
    }

    public function searchCollaborators(Project $project, ?string $environment, ?string $search = null): array
    {
        $registration = $this->environmentRegistration($project, $environment);

        if ($registration === null) {
            throw new RuntimeException('External collaborators are unavailable because this environment is not registered for the selected project.');
        }

        $query = [];
        $term = trim((string) $search);
        if ($term !== '') {
            $query['search'] = $term;
        }

        try {
            $response = Http::withToken($project->token)
                ->acceptJson()
                ->timeout(10)
                ->get(rtrim($registration->url, '/').'/shift/api/collaborators/external', $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('External collaborators are unavailable because the client app could not be reached.', previous: $exception);
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? 'External collaborators are unavailable.';

            throw new RuntimeException((string) $message);
        }

        $payload = $response->json();
        $returnedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($payload['environment'] ?? null);
        $returnedUrl = $this->projectEnvironmentService->normalizeUrl($payload['url'] ?? null);

        if ($returnedEnvironment === null || $returnedUrl === null) {
            throw new RuntimeException('External collaborators are unavailable because the client app returned an invalid identity.');
        }

        if ($returnedEnvironment !== $registration->environment || $returnedUrl !== $registration->url) {
            throw new RuntimeException('External collaborators are unavailable because the client app identity does not match the registered environment.');
        }

        $users = collect($payload['users'] ?? [])
            ->filter(fn ($user) => is_array($user))
            ->map(function (array $user) {
                return [
                    'id' => $this->normalizeExternalId($user['id'] ?? null),
                    'name' => $this->projectEnvironmentService->normalizeString($user['name'] ?? null),
                    'email' => $this->projectEnvironmentService->normalizeString($user['email'] ?? null),
                ];
            })
            ->filter(fn (array $user) => $user['id'] !== null && $user['name'] !== null && $user['email'] !== null)
            ->values()
            ->all();

        return [
            'environment' => $registration->environment,
            'url' => $registration->url,
            'users' => $users,
        ];
    }

    public function resolveCollaborators(Project $project, ?string $environment, array $collaborators): Collection
    {
        $selectedIds = collect($collaborators)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => $this->normalizeExternalId($item['id'] ?? null))
            ->filter()
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            return collect();
        }

        $lookup = $this->searchCollaborators($project, $environment);
        $available = collect($lookup['users'])->keyBy(fn (array $user) => $user['id']);
        $missing = $selectedIds->reject(fn (string $id) => $available->has($id));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'external_collaborators' => 'One or more external collaborators are no longer available for this project.',
            ]);
        }

        return $selectedIds
            ->map(function (string $id) use ($available, $lookup, $project) {
                $candidate = $available->get($id);

                return $this->upsert($project, [
                    'external_id' => $candidate['id'],
                    'name' => $candidate['name'],
                    'email' => $candidate['email'],
                    'environment' => $lookup['environment'],
                    'url' => $lookup['url'],
                ]);
            })
            ->values();
    }

    public function environmentRegistration(Project $project, ?string $environment): ?ProjectEnvironment
    {
        return $this->projectEnvironmentService->find($project, $environment);
    }

    public function normalizeExternalId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    public function normalizeString(?string $value): ?string
    {
        return $this->projectEnvironmentService->normalizeString($value);
    }

    public function normalizeUrl(?string $value): ?string
    {
        return $this->projectEnvironmentService->normalizeUrl($value);
    }

    private function normalizeRole(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return null;
        }

        $role = trim(strtolower((string) $value));

        return in_array($role, self::ROLES, true) ? $role : null;
    }

    private function isSubmitter(Task $task, ExternalUser $externalUser): bool
    {
        return $task->submitter_type === ExternalUser::class
            && (int) $task->submitter_id === (int) $externalUser->id;
    }
}
