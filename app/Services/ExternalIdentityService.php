<?php

namespace App\Services;

use App\Enums\ExternalIdentityRole;
use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalIdentityService
{
    public function __construct(
        private readonly ProjectEnvironmentService $projectEnvironmentService,
        private readonly ProjectParticipantService $participants,
    ) {}

    public function find(Project $project, mixed $externalId, ?string $environment, ?string $url): ?ExternalIdentity
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

            $externalIdentity = $this->findByProjectEnvironment($project, $projectEnvironment, $normalizedId);

            if ($externalIdentity instanceof ExternalIdentity) {
                return $externalIdentity;
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
    ): ?ExternalIdentity {
        return ExternalIdentity::query()
            ->where('project_id', $project->id)
            ->where('project_environment_id', $projectEnvironment->id)
            ->where('external_id', $externalId)
            ->first();
    }

    public function upsert(Project $project, array $attributes): ExternalIdentity
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
            $values['role'] = $this->normalizeRole($attributes['role']) ?? ExternalIdentityRole::User->value;
        }

        return DB::transaction(function () use ($project, $externalId, $environment, $url, $values) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
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
            $externalIdentity = $projectEnvironment instanceof ProjectEnvironment
                ? $this->findByProjectEnvironment($project, $projectEnvironment, $externalId)
                    ?? $this->legacyIdentityQuery($project, $externalId, $environment, $trustedUrl)->first()
                : $this->legacyIdentityQuery($project, $externalId, $environment, $trustedUrl)->first();

            if (! $externalIdentity instanceof ExternalIdentity) {
                return ExternalIdentity::query()->create([
                    ...$values,
                    'project_id' => $project->id,
                    'project_participant_id' => $this->participants->create($project)->id,
                    'project_environment_id' => $projectEnvironment?->id,
                    'external_id' => $externalId,
                    'environment' => $environment,
                    'url' => $trustedUrl,
                ]);
            }

            $externalIdentity->fill([
                ...$values,
                'project_id' => $project->id,
                'project_participant_id' => $externalIdentity->project_participant_id ?? $this->participants->create($project)->id,
                'project_environment_id' => $projectEnvironment?->id,
                'environment' => $environment,
                'url' => $trustedUrl,
            ])->save();

            return $externalIdentity;
        }, 3);
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

        return in_array($role, ExternalIdentityRole::values(), true) ? $role : null;
    }

    private function legacyIdentityQuery(Project $project, string $externalId, string $environment, string $url): Builder
    {
        return ExternalIdentity::query()
            ->where('project_id', $project->id)
            ->where('external_id', $externalId)
            ->where('environment', $environment)
            ->where('url', $url);
    }
}
