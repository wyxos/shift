<?php

namespace App\Services;

use App\Models\Project;
use App\Support\TemporaryAttachmentOwner;
use Illuminate\Http\Request;

class TemporaryAttachmentOwnerResolver
{
    public function __construct(private readonly ProjectEnvironmentService $environments) {}

    public function resolve(Request $request): ?TemporaryAttachmentOwner
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $token = $user->currentAccessToken();
        $context = [
            'kind' => 'api',
            'user_id' => (int) $user->getAuthIdentifier(),
            'integration' => $token !== null && method_exists($token, 'getKey')
                ? $token->getMorphClass().':'.$token->getKey()
                : 'session',
        ];

        if (! $request->hasAny(['project', 'user'])) {
            return new TemporaryAttachmentOwner($context);
        }

        $projectToken = $request->input('project');
        $externalId = $request->input('user.id');
        $environment = $request->input('user.environment');
        $url = $request->input('user.url');
        if (! is_string($projectToken)
            || (! is_string($externalId) && ! is_int($externalId))
            || trim((string) $externalId) === ''
            || ! is_string($environment)
            || ! is_string($url)) {
            return null;
        }

        $project = Project::query()->visibleTo($user->getAuthIdentifier())->where('token', $projectToken)->first();
        $environment = $this->environments->normalizeEnvironment($environment);
        $url = $this->environments->normalizeBaseUrl($url);
        if ($project === null || $environment === null || $url === null) {
            return null;
        }

        $registration = $this->environments->find($project, $environment);
        if ($registration !== null && $registration->url !== $url) {
            return null;
        }

        return new TemporaryAttachmentOwner([
            ...$context,
            'project_id' => $project->id,
            'environment' => $environment,
            'url' => $url,
            'external_id' => trim((string) $externalId),
        ]);
    }
}
