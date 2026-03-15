<?php

namespace App\Services;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExternalUserService
{
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

        return ExternalUser::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'external_id' => $externalId,
                'environment' => $environment,
                'url' => $url,
            ],
            [
                'name' => $this->normalizeString($attributes['name'] ?? null) ?? 'External User',
                'email' => $this->normalizeString($attributes['email'] ?? null),
            ],
        );
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
}
