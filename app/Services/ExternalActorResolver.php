<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Support\ExternalActor;
use Illuminate\Http\Request;

class ExternalActorResolver
{
    public function __construct(private readonly ExternalIdentityService $identities) {}

    public function fromRequest(Project $project, Request $request, bool $create = false): ?ExternalActor
    {
        return $this->resolve($project, [
            'external_id' => $request->input('user.id'),
            'name' => $request->input('user.name'),
            'email' => $request->input('user.email'),
            'environment' => $request->input('user.environment'),
            'url' => $request->input('user.url'),
        ], $create);
    }

    /** @param array<string, mixed> $attributes */
    public function resolve(Project $project, array $attributes, bool $create = false): ?ExternalActor
    {
        $identity = $create ? $this->identities->upsert($project, $attributes) : $this->identities->find(
            $project, $attributes['external_id'] ?? null, $attributes['environment'] ?? null, $attributes['url'] ?? null,
        );

        if (! $identity instanceof ExternalIdentity) {
            return null;
        }

        $identity->loadMissing(['participant', 'projectEnvironment']);

        return new ExternalActor($identity, $identity->participant, $identity->projectEnvironment);
    }
}
