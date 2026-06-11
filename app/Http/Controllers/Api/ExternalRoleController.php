<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExternalUserRole;
use App\Http\Controllers\Controller;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Services\ExternalUserService;
use App\Services\ProjectEnvironmentService;
use App\Services\ShiftPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExternalRoleController extends Controller
{
    public function __construct(
        private readonly ExternalUserService $externalUserService,
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly ShiftPermissionService $permissions,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'user.id' => 'nullable',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'metadata.url' => 'nullable|url',
        ]);

        $project = $this->project($attributes['project']);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        return response()->json([
            'capabilities' => [
                'can_manage_external_roles' => $this->canManageExternalRoles($project, $request, $attributes),
            ],
            'roles' => $this->roles(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'user.id' => 'nullable',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'metadata.url' => 'nullable|url',
            'search' => 'nullable|string|max:255',
            'environment' => 'nullable|string|max:255',
        ]);

        $project = $this->project($attributes['project']);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        abort_unless($this->canManageExternalRoles($project, $request, $attributes), 403);

        $environment = $this->environment($attributes);
        $lookup = $this->externalUserService->searchCollaborators(
            $project,
            $environment,
            $attributes['search'] ?? null,
        );

        return response()->json([
            'capabilities' => [
                'can_manage_external_roles' => true,
            ],
            'roles' => $this->roles(),
            'users' => collect($lookup['users'])
                ->map(fn (array $user) => $this->serializeCandidate($project, $lookup['environment'], $lookup['url'], $user))
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'project' => 'required|string',
            'user.id' => 'nullable',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'metadata.url' => 'nullable|url',
            'external_user' => 'required|array',
            'external_user.id' => 'required',
            'external_user.name' => 'required|string|max:255',
            'external_user.email' => 'required|email',
            'role' => ['required', Rule::in(ExternalUserRole::values())],
            'environment' => 'nullable|string|max:255',
        ]);

        $project = $this->project($attributes['project']);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        abort_unless($this->canManageExternalRoles($project, $request, $attributes), 403);

        $environment = $this->environment($attributes);
        $url = $this->urlForEnvironment($project, $environment, $attributes);

        $externalUser = $this->externalUserService->upsert($project, [
            'external_id' => data_get($attributes, 'external_user.id'),
            'name' => data_get($attributes, 'external_user.name'),
            'email' => data_get($attributes, 'external_user.email'),
            'environment' => $environment,
            'url' => $url,
            'role' => $attributes['role'],
        ]);

        return response()->json([
            'user' => $this->serializeExternalUser($externalUser),
            'roles' => $this->roles(),
        ]);
    }

    private function project(string $token): ?Project
    {
        return Project::query()
            ->with('environments')
            ->where('token', $token)
            ->first();
    }

    private function canManageExternalRoles(Project $project, Request $request, array $attributes): bool
    {
        if ($this->permissions->canManageTechnicalSettings($project, $request->user()?->id)) {
            return true;
        }

        $externalUser = $this->currentExternalUser($project, $attributes);

        return $externalUser?->role?->canManageExternalRoles() === true;
    }

    private function currentExternalUser(Project $project, array $attributes): ?ExternalUser
    {
        return $this->externalUserService->find(
            $project,
            data_get($attributes, 'user.id'),
            data_get($attributes, 'user.environment') ?? data_get($attributes, 'metadata.environment'),
            data_get($attributes, 'user.url') ?? data_get($attributes, 'metadata.url'),
        );
    }

    private function environment(array $attributes): ?string
    {
        return $this->projectEnvironmentService->normalizeEnvironment(
            $attributes['environment'] ?? data_get($attributes, 'metadata.environment') ?? data_get($attributes, 'user.environment')
        );
    }

    private function urlForEnvironment(Project $project, ?string $environment, array $attributes): ?string
    {
        if ($environment !== null) {
            $registeredUrl = $project->environments->firstWhere('environment', $environment)?->url;
            if ($registeredUrl !== null) {
                return $registeredUrl;
            }
        }

        return $this->projectEnvironmentService->normalizeUrl(
            data_get($attributes, 'metadata.url') ?? data_get($attributes, 'user.url')
        );
    }

    private function serializeCandidate(Project $project, ?string $environment, ?string $url, array $user): array
    {
        $externalUser = ExternalUser::query()
            ->where('project_id', $project->id)
            ->where('external_id', $this->externalUserService->normalizeExternalId($user['id'] ?? null))
            ->when($environment !== null, fn ($query) => $query->where('environment', $environment))
            ->when($url !== null, fn ($query) => $query->where('url', $url))
            ->first();

        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $externalUser?->role?->value ?? ExternalUserRole::User->value,
        ];
    }

    private function serializeExternalUser(ExternalUser $externalUser): array
    {
        return [
            'id' => $externalUser->external_id,
            'name' => $externalUser->name,
            'email' => $externalUser->email,
            'environment' => $externalUser->environment,
            'url' => $externalUser->url,
            'role' => $externalUser->role?->value ?? ExternalUserRole::User->value,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roles(): array
    {
        return collect(ExternalUserRole::cases())
            ->map(fn (ExternalUserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->all();
    }
}
