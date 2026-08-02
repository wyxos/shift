<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProjectEnvironmentService
{
    public function __construct(
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
    ) {}

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function options(Project $project): array
    {
        return $project->environments()
            ->select(['environment'])
            ->orderBy('environment')
            ->get()
            ->map(fn (ProjectEnvironment $environment) => [
                'key' => $environment->environment,
                'label' => $this->label($environment->environment),
            ])
            ->all();
    }

    public function register(Project $project, ?string $environment, ?string $url): ProjectEnvironment
    {
        $normalizedEnvironment = $this->normalizeEnvironment($environment);

        if ($normalizedEnvironment === null) {
            throw ValidationException::withMessages([
                'environment' => 'Project environments require both an environment name and URL.',
            ]);
        }

        try {
            $normalizedUrl = $this->outboundUrlPolicy->approveRegistration($url, $normalizedEnvironment);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'url' => $exception->getMessage(),
            ]);
        }

        $registration = ProjectEnvironment::query()->firstOrNew([
            'project_id' => $project->id,
            'environment' => $normalizedEnvironment,
        ]);

        if (! $registration->exists) {
            $registration->external_widget_enabled = $project->external_widget_enabled;
            $registration->external_widget_guest_submissions_enabled = $project->external_widget_guest_submissions_enabled;
        }

        $registration->url = $normalizedUrl;
        $registration->callback_trusted_at = $this->trustedAt($normalizedUrl);
        $registration->save();

        return $registration;
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

    public function findTrustedByUrl(Project $project, ?string $url): ?ProjectEnvironment
    {
        $normalizedUrl = $this->normalizeBaseUrl($url);

        if ($normalizedUrl === null) {
            return null;
        }

        return ProjectEnvironment::query()
            ->where('project_id', $project->id)
            ->where('url', $normalizedUrl)
            ->whereNotNull('callback_trusted_at')
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
        return $this->outboundUrlPolicy->normalizeBaseUrl($value);
    }

    public function normalizeBaseUrl(?string $value): ?string
    {
        return $this->outboundUrlPolicy->normalizeBaseUrl($value);
    }

    public function label(string $environment): string
    {
        return Str::headline(str_replace(['-', '_'], ' ', $environment));
    }

    private function trustedAt(string $url): ?CarbonInterface
    {
        try {
            $this->outboundUrlPolicy->approveRequest($url);
        } catch (InvalidArgumentException) {
            return null;
        }

        return now();
    }
}
