<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectEnvironmentService
{
    public function register(Project $project, ?string $environment, ?string $url): ProjectEnvironment
    {
        $normalizedEnvironment = $this->normalizeEnvironment($environment);
        $normalizedUrl = $this->normalizeUrl($url);

        if ($normalizedEnvironment === null || $normalizedUrl === null) {
            throw ValidationException::withMessages([
                'environment' => 'Project environments require both an environment name and URL.',
            ]);
        }

        return ProjectEnvironment::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'environment' => $normalizedEnvironment,
            ],
            [
                'url' => $normalizedUrl,
            ],
        );
    }

    public function find(Project $project, ?string $environment): ?ProjectEnvironment
    {
        $normalizedEnvironment = $this->normalizeEnvironment($environment);

        if ($normalizedEnvironment === null) {
            return null;
        }

        return ProjectEnvironment::query()
            ->where('project_id', $project->id)
            ->where('environment', $normalizedEnvironment)
            ->first();
    }

    public function normalizeEnvironment(?string $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return $normalized !== null ? Str::of($normalized)->lower()->replace(' ', '-')->toString() : null;
    }

    public function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    public function normalizeUrl(?string $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return $normalized !== null ? rtrim($normalized, '/') : null;
    }

    public function label(string $environment): string
    {
        return Str::headline(str_replace(['-', '_'], ' ', $environment));
    }
}
