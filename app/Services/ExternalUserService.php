<?php

namespace App\Services;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
    ) {}

    public function find(Project $project, mixed $externalId, ?string $environment, ?string $url): ?ExternalUser
    {
        $normalizedId = $this->normalizeExternalId($externalId);
        $normalizedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($environment);
        $normalizedUrl = $this->projectEnvironmentService->normalizeBaseUrl($url);

        if ($normalizedId === null || $normalizedEnvironment === null) {
            return null;
        }

        $projectEnvironment = $this->projectEnvironmentService->find($project, $normalizedEnvironment);

        if ($projectEnvironment instanceof ProjectEnvironment) {
            if ($normalizedUrl === null || $normalizedUrl !== $projectEnvironment->url) {
                return null;
            }

            $externalUser = $this->findByProjectEnvironment($project, $projectEnvironment, $normalizedId, $normalizedUrl);

            if ($externalUser instanceof ExternalUser) {
                return $externalUser;
            }

            return $this->legacyIdentityQuery(
                $project,
                $normalizedId,
                $normalizedEnvironment,
                $projectEnvironment->url,
            )->first();
        }

        if ($normalizedUrl === null) {
            return null;
        }

        return $this->legacyIdentityQuery($project, $normalizedId, $normalizedEnvironment, $normalizedUrl)->first();
    }

    private function findByProjectEnvironment(
        Project $project,
        ProjectEnvironment $projectEnvironment,
        string $externalId,
        ?string $url = null,
    ): ?ExternalUser {
        $matches = ExternalUser::query()
            ->where('project_id', $project->id)
            ->where('project_environment_id', $projectEnvironment->id)
            ->where('external_id', $externalId)
            ->orderBy('id')
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1 && $url !== null) {
            return $matches->first(fn (ExternalUser $externalUser) => $externalUser->url === $url);
        }

        return null;
    }

    public function upsert(Project $project, array $attributes): ExternalUser
    {
        $externalId = $this->normalizeExternalId($attributes['external_id'] ?? null);
        $environment = $this->projectEnvironmentService->normalizeEnvironment($attributes['environment'] ?? null);
        $url = $this->projectEnvironmentService->normalizeBaseUrl($attributes['url'] ?? null);

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

        return DB::transaction(function () use ($project, $externalId, $environment, $url, $values) {
            $projectEnvironment = ProjectEnvironment::query()
                ->where('project_id', $project->id)
                ->where('environment', $environment)
                ->lockForUpdate()
                ->first();

            if ($projectEnvironment instanceof ProjectEnvironment && $projectEnvironment->url !== $url) {
                throw ValidationException::withMessages([
                    'user.url' => 'The external user URL does not match the manager-registered project environment.',
                ]);
            }

            $trustedUrl = $projectEnvironment?->url ?? $url;
            $externalUser = $projectEnvironment instanceof ProjectEnvironment
                ? $this->findByProjectEnvironment($project, $projectEnvironment, $externalId, $trustedUrl)
                    ?? $this->legacyIdentityQuery($project, $externalId, $environment, $trustedUrl)->first()
                : $this->legacyIdentityQuery($project, $externalId, $environment, $trustedUrl)->first();

            if (! $externalUser instanceof ExternalUser) {
                return ExternalUser::query()->create([
                    ...$values,
                    'project_id' => $project->id,
                    'external_contact_id' => $this->createContactId($project),
                    'project_environment_id' => $projectEnvironment?->id,
                    'external_id' => $externalId,
                    'environment' => $environment,
                    'url' => $trustedUrl,
                ]);
            }

            $externalUser->fill([
                ...$values,
                'project_id' => $project->id,
                'external_contact_id' => $externalUser->external_contact_id ?? $this->createContactId($project),
                'project_environment_id' => $projectEnvironment?->id,
                'environment' => $environment,
                'url' => $trustedUrl,
            ])->save();

            return $externalUser;
        });
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
            $linkedExternalUserIds = $this->linkedExternalUserIds($externalUser);

            $visibilityQuery->whereHasMorph('submitter', [ExternalUser::class], function (Builder $submitterQuery) use ($linkedExternalUserIds) {
                $submitterQuery->whereIn('external_users.id', $linkedExternalUserIds);
            })->orWhereHas('externalCollaborators', function (Builder $collaboratorQuery) use ($linkedExternalUserIds) {
                $collaboratorQuery->whereIn('external_users.id', $linkedExternalUserIds);
            });

            if (! $this->canViewOwnerAndAssignedProjectItems($externalUser)) {
                return;
            }

            $visibilityQuery->orWhereHasMorph('submitter', [ExternalUser::class], function (Builder $submitterQuery) {
                $submitterQuery->where('external_users.role', self::ROLE_OWNER);
            });
        });
    }

    public function canViewProjectItem(Task $task, ExternalUser $externalUser): bool
    {
        if ($this->canViewAllProjectItems($externalUser)) {
            return true;
        }

        if ($this->isLinkedSubmitter($task, $externalUser)) {
            return true;
        }

        if ($task->externalCollaborators()
            ->whereIn('external_users.id', $this->linkedExternalUserIds($externalUser))
            ->exists()) {
            return true;
        }

        if (! $this->canViewOwnerAndAssignedProjectItems($externalUser)) {
            return false;
        }

        $submitter = $task->submitter;

        return $submitter instanceof ExternalUser && $this->role($submitter) === self::ROLE_OWNER;
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

    public function searchCollaborators(
        Project $project,
        ?string $environment,
        ?string $search = null,
        bool $paginate = false,
        int $page = 1,
        int $perPage = 15,
    ): array {
        $registration = $this->environmentRegistration($project, $environment);

        if ($registration === null) {
            throw new RuntimeException('External collaborators are unavailable because this environment is not registered for the selected project.');
        }

        if ($registration->callback_trusted_at === null) {
            throw new RuntimeException('External collaborators are unavailable because the registered callback destination has not been trusted.');
        }

        if (! filled($project->token)) {
            throw new RuntimeException('External collaborators are unavailable because this project does not have a callback token.');
        }

        try {
            $destination = $this->outboundUrlPolicy->approveRequest($registration->url);
            $requestOptions = $this->outboundUrlPolicy->requestOptions($destination);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                'External collaborators are unavailable because the registered callback destination is not trusted.',
                previous: $exception,
            );
        }

        $query = [];
        $term = trim((string) $search);
        if ($term !== '') {
            $query['search'] = $term;
        }

        if ($paginate) {
            $query['paginate'] = 1;
            $query['page'] = $page;
            $query['per_page'] = $perPage;
        }

        try {
            $callbackUrl = $destination['url'].'/shift/api/collaborators/external';
            $response = Http::withOptions($requestOptions)
                ->withToken($project->token)
                ->acceptJson()
                ->connectTimeout((int) config('shift.notifications.callback_connect_timeout_seconds', 5))
                ->timeout(10)
                ->get($callbackUrl, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('External collaborators are unavailable because the client app could not be reached.', previous: $exception);
        }

        if ($this->outboundUrlPolicy->responseWasRedirected($response, $callbackUrl)) {
            throw new RuntimeException('External collaborators are unavailable because the callback destination attempted a redirect.');
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? 'External collaborators are unavailable.';

            throw new RuntimeException((string) $message);
        }

        $payload = $response->json();
        $returnedEnvironment = $this->projectEnvironmentService->normalizeEnvironment($payload['environment'] ?? null);
        $returnedUrl = $this->projectEnvironmentService->normalizeBaseUrl($payload['url'] ?? null);

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

        $result = [
            'environment' => $registration->environment,
            'url' => $registration->url,
            'users' => $users,
        ];

        if (! $paginate) {
            return $result;
        }

        $pagination = $this->normalizeCollaboratorPagination($payload['pagination'] ?? null);

        if ($pagination === null) {
            $total = count($users);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $result['users'] = array_slice($users, ($page - 1) * $perPage, $perPage);
            $pagination = [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($page * $perPage, $total),
            ];
        }

        $result['pagination'] = $pagination;

        return $result;
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}|null
     */
    private function normalizeCollaboratorPagination(mixed $pagination): ?array
    {
        if (! is_array($pagination)) {
            return null;
        }

        $currentPage = filter_var($pagination['current_page'] ?? null, FILTER_VALIDATE_INT);
        $lastPage = filter_var($pagination['last_page'] ?? null, FILTER_VALIDATE_INT);
        $perPage = filter_var($pagination['per_page'] ?? null, FILTER_VALIDATE_INT);
        $total = filter_var($pagination['total'] ?? null, FILTER_VALIDATE_INT);

        if ($currentPage === false || $lastPage === false || $perPage === false || $total === false) {
            return null;
        }

        if ($currentPage < 1 || $lastPage < 1 || $perPage < 1 || $total < 0) {
            return null;
        }

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => is_numeric($pagination['from'] ?? null) ? (int) $pagination['from'] : null,
            'to' => is_numeric($pagination['to'] ?? null) ? (int) $pagination['to'] : null,
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

    private function legacyIdentityQuery(Project $project, string $externalId, string $environment, string $url): Builder
    {
        return ExternalUser::query()
            ->where('project_id', $project->id)
            ->where('external_id', $externalId)
            ->where('environment', $environment)
            ->where('url', $url);
    }

    private function createContactId(Project $project): int
    {
        return (int) DB::table('external_contacts')->insertGetId([
            'project_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkedExternalUserIds(ExternalUser $externalUser): array
    {
        $externalContactId = $externalUser->external_contact_id;

        if ($externalContactId === null) {
            return [(int) $externalUser->id];
        }

        return ExternalUser::query()
            ->where('project_id', $externalUser->project_id)
            ->where('external_contact_id', $externalContactId)
            ->pluck('id')
            ->push($externalUser->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function isLinkedSubmitter(Task $task, ExternalUser $externalUser): bool
    {
        return $task->submitter_type === ExternalUser::class
            && $task->submitter_id !== null
            && in_array((int) $task->submitter_id, $this->linkedExternalUserIds($externalUser), true);
    }

    private function isSubmitter(Task $task, ExternalUser $externalUser): bool
    {
        return $task->submitter_type === ExternalUser::class
            && (int) $task->submitter_id === (int) $externalUser->id;
    }
}
