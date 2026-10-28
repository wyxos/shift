<?php

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;

    $this->project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'requirement-project-token',
    ]);

    $this->project->environments()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
        'callback_trusted_at' => now(),
    ]);

    $this->externalIdentityData = [
        'id' => 'client-123',
        'name' => 'Client User',
        'email' => 'client@example.com',
        'environment' => 'testing',
        'url' => 'https://example.com',
    ];

    $this->createRoleExternalIdentity = function (string $externalId, string $role, array $overrides = []): ExternalIdentity {
        return ExternalIdentity::query()->create([
            'external_id' => $externalId,
            'name' => $overrides['name'] ?? 'External '.$externalId,
            'email' => $overrides['email'] ?? $externalId.'@example.com',
            'environment' => $overrides['environment'] ?? 'testing',
            'url' => $overrides['url'] ?? 'https://example.com',
            'project_id' => $overrides['project_id'] ?? $this->project->id,
            'role' => $role,
        ]);
    };

    $this->externalPayload = fn (ExternalIdentity $externalIdentity): array => [
        'id' => $externalIdentity->external_id,
        'name' => $externalIdentity->name,
        'email' => $externalIdentity->email,
        'environment' => $externalIdentity->environment,
        'url' => $externalIdentity->url,
    ];

    $this->createRequirement = function (ExternalIdentity $submitter, array $attributes = []): Task {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'title' => $attributes['title'] ?? 'Requirement item',
            'status' => $attributes['status'] ?? 'pending',
        ]);
        $task->submitter()->associate($submitter)->save();
        $task->metadata()->create([
            'environment' => $attributes['environment'] ?? 'testing',
            'url' => $attributes['url'] ?? 'https://example.com/requirement',
            'phase' => $attributes['phase'] ?? 'requirement',
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
            'requirement_status' => $attributes['requirement_status'] ?? 'submitted',
            'requirement_batch_id' => $attributes['requirement_batch_id'] ?? null,
            'submitted_title' => $attributes['submitted_title'] ?? ($attributes['title'] ?? 'Requirement item'),
            'submitted_description' => $attributes['submitted_description'] ?? 'Original requirement details.',
        ]);

        return $task;
    };
});

test('permitted external roles can create a requirement batch with multiple task shaped items', function () {
    $externalIdentity = ($this->createRoleExternalIdentity)('client-123', 'client_developer', [
        'name' => 'Client User',
        'email' => 'client@example.com',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'June client requirements',
            'user' => ($this->externalPayload)($externalIdentity),
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/portal',
            ],
            'items' => [
                [
                    'title' => 'Show renewal warning',
                    'description' => 'Warn users before their policy expires.',
                ],
                [
                    'title' => 'Export outstanding renewals',
                    'description' => 'Allow admins to export pending renewals.',
                ],
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('batch.title', 'June client requirements')
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.phase', 'requirement')
        ->assertJsonPath('items.0.requirement_status', 'submitted')
        ->assertJsonPath('items.1.phase', 'requirement');

    $batchId = $response->json('batch.id');

    $this->assertDatabaseHas('requirement_batches', [
        'id' => $batchId,
        'project_id' => $this->project->id,
        'title' => 'June client requirements',
    ]);

    foreach ($response->json('items') as $item) {
        $this->assertDatabaseHas('tasks', [
            'id' => $item['id'],
            'project_id' => $this->project->id,
            'status' => 'pending',
            'priority' => 'medium',
        ]);

        $this->assertDatabaseHas('task_metadata', [
            'task_id' => $item['id'],
            'phase' => 'requirement',
            'requirement_status' => 'submitted',
            'requirement_batch_id' => $batchId,
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
        ]);

        $this->assertDatabaseHas('task_collaborators', [
            'task_id' => $item['id'],
            'external_identity_id' => $externalIdentity->id,
            'kind' => 'external',
        ]);
    }
});

test('requirement submitter can opt out of follow ups before submitting a batch', function () {
    $externalIdentity = ($this->createRoleExternalIdentity)('client-opt-out', 'client_developer', [
        'name' => 'Opted Out Client',
        'email' => 'opted-out@example.com',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'No follow ups',
            'user' => ($this->externalPayload)($externalIdentity),
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/portal',
            ],
            'include_submitter_as_collaborator' => false,
            'items' => [
                [
                    'title' => 'Quiet requirement',
                    'description' => 'A teammate will follow this up.',
                ],
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonCount(1, 'items');

    $requirement = Task::query()->where('title', 'Quiet requirement')->firstOrFail();

    $this->assertDatabaseMissing('task_collaborators', [
        'task_id' => $requirement->id,
        'external_identity_id' => $externalIdentity->id,
    ]);
});

test('requirement batch submission syncs collaborators and rich editor temp attachments', function () {
    Storage::fake();
    Http::fake([
        'https://example.com/shift/api/collaborators/external*' => Http::response([
            'environment' => 'testing',
            'url' => 'https://example.com',
            'users' => [
                [
                    'id' => 'client-456',
                    'name' => 'Global Client User',
                    'email' => 'global-client@example.com',
                ],
                [
                    'id' => 'client-789',
                    'name' => 'Item Client User',
                    'email' => 'item-client@example.com',
                ],
            ],
        ], 200),
    ]);

    $externalIdentity = ($this->createRoleExternalIdentity)('client-123', 'client_developer', [
        'name' => 'Client User',
        'email' => 'client@example.com',
    ]);
    $globalInternalCollaborator = User::factory()->create();
    $itemInternalCollaborator = User::factory()->create();

    foreach ([$globalInternalCollaborator, $itemInternalCollaborator] as $collaborator) {
        \App\Models\ProjectUser::factory()->create([
            'project_id' => $this->project->id,
            'user_id' => $collaborator->id,
            'user_email' => $collaborator->email,
            'user_name' => $collaborator->name,
            'registration_status' => 'registered',
        ]);
    }

    $uploadRequest = \Illuminate\Http\Request::create('/api/attachments/upload', 'POST', [
        'project' => $this->project->token,
        'user' => ($this->externalPayload)($externalIdentity),
    ]);
    $uploadRequest->setUserResolver(fn () => $this->user->withAccessToken(\Laravel\Sanctum\PersonalAccessToken::findToken($this->token)));
    $temporaryStorage = app(\App\Services\TemporaryAttachmentStorage::class);
    $temporaryStorage->claim('requirement-temp-1', $temporaryStorage->ownerForRequest($uploadRequest));
    Storage::put('temp_attachments/requirement-temp-1/screenshot.png', 'image-bytes');
    Storage::put('temp_attachments/requirement-temp-1/screenshot.png.meta', json_encode([
        'original_filename' => 'screenshot.png',
    ]));

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'Collaborator scoped requirements',
            'user' => ($this->externalPayload)($externalIdentity),
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/portal',
            ],
            'internal_collaborator_ids' => [$globalInternalCollaborator->id],
            'external_collaborators' => [
                [
                    'id' => 'client-456',
                    'name' => 'Global Client User',
                    'email' => 'global-client@example.com',
                ],
            ],
            'items' => [
                [
                    'title' => 'Global collaborator item',
                    'description' => '<p><img src="/shift/api/attachments/temp/requirement-temp-1/screenshot.png"></p>',
                    'temp_identifier' => 'requirement-temp-1',
                ],
                [
                    'title' => 'Per item collaborator item',
                    'description' => '<p>Need CSV export.</p>',
                    'internal_collaborator_ids' => [$itemInternalCollaborator->id],
                    'external_collaborators' => [
                        [
                            'id' => 'client-789',
                            'name' => 'Item Client User',
                            'email' => 'item-client@example.com',
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertCreated()->assertJsonCount(2, 'items');

    $globalRequirement = Task::query()->where('title', 'Global collaborator item')->firstOrFail();
    $itemRequirement = Task::query()->where('title', 'Per item collaborator item')->firstOrFail();
    $globalExternalCollaborator = ExternalIdentity::query()
        ->where('project_id', $this->project->id)
        ->where('external_id', 'client-456')
        ->firstOrFail();
    $itemExternalCollaborator = ExternalIdentity::query()
        ->where('project_id', $this->project->id)
        ->where('external_id', 'client-789')
        ->firstOrFail();

    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $globalRequirement->id,
        'user_id' => $globalInternalCollaborator->id,
        'kind' => 'internal',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $globalRequirement->id,
        'external_identity_id' => $globalExternalCollaborator->id,
        'kind' => 'external',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $globalRequirement->id,
        'external_identity_id' => $externalIdentity->id,
        'kind' => 'external',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $itemRequirement->id,
        'user_id' => $itemInternalCollaborator->id,
        'kind' => 'internal',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $itemRequirement->id,
        'external_identity_id' => $itemExternalCollaborator->id,
        'kind' => 'external',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $itemRequirement->id,
        'external_identity_id' => $externalIdentity->id,
        'kind' => 'external',
    ]);
    $this->assertDatabaseMissing('task_collaborators', [
        'task_id' => $itemRequirement->id,
        'user_id' => $globalInternalCollaborator->id,
    ]);

    $attachment = $globalRequirement->attachments()->firstOrFail();
    Storage::assertMissing('temp_attachments/requirement-temp-1/screenshot.png');
    Storage::assertExists($attachment->path);

    expect($globalRequirement->refresh()->description)->toContain("/attachments/{$attachment->id}/download");
    expect($globalRequirement->metadata?->submitted_description)->toContain("/attachments/{$attachment->id}/download");

    $proxiedUrl = "https://example.com/portal/shift/api/attachments/{$attachment->id}/download";
    expect($response->json('items.0.description'))->toContain($proxiedUrl);
    expect($response->json('items.0.submitted_description'))->toContain($proxiedUrl);

    $listResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/requirements?'.http_build_query([
            'project' => $this->project->token,
            'user' => ($this->externalPayload)($externalIdentity),
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/portal',
            ],
        ]))
        ->assertOk();

    $listedRequirement = collect($listResponse->json('data'))
        ->firstWhere('id', $globalRequirement->id);

    expect($listedRequirement['description'] ?? null)->toBe('<p><img src="'.$proxiedUrl.'"></p>');
    expect($listedRequirement['submitted_description'] ?? null)->toBe('<p><img src="'.$proxiedUrl.'"></p>');
});

test('requirement visibility follows the external role matrix', function () {
    $owner = ($this->createRoleExternalIdentity)('owner-requirement-1', 'owner');
    $clientDeveloper = ($this->createRoleExternalIdentity)('client-developer-requirement-1', 'client_developer');
    $shiftLeadDeveloper = ($this->createRoleExternalIdentity)('shift-lead-requirement-1', 'shift_lead_developer');
    $shiftDeveloper = ($this->createRoleExternalIdentity)('shift-developer-requirement-1', 'shift_developer');
    $user = ($this->createRoleExternalIdentity)('user-requirement-1', 'user');
    $guest = ($this->createRoleExternalIdentity)('guest-requirement-1', 'guest');

    $ownerRequirement = ($this->createRequirement)($owner, ['title' => 'Owner requirement']);
    $clientDeveloperRequirement = ($this->createRequirement)($clientDeveloper, ['title' => 'Client developer requirement']);
    $shiftDeveloperRequirement = ($this->createRequirement)($shiftDeveloper, ['title' => 'Shift developer requirement']);
    $userRequirement = ($this->createRequirement)($user, ['title' => 'User requirement']);
    $guestRequirement = ($this->createRequirement)($guest, ['title' => 'Guest requirement']);
    $assignedRequirement = ($this->createRequirement)($guest, ['title' => 'Assigned guest requirement']);
    $assignedRequirement->externalIdentities()->attach($clientDeveloper->id);

    $idsFor = function (ExternalIdentity $externalIdentity): array {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/requirements?'.http_build_query([
                'project' => $this->project->token,
                'user' => ($this->externalPayload)($externalIdentity),
            ]));

        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    };

    expect($idsFor($owner))->toBe(collect([
        $ownerRequirement->id,
        $clientDeveloperRequirement->id,
        $shiftDeveloperRequirement->id,
        $userRequirement->id,
        $guestRequirement->id,
        $assignedRequirement->id,
    ])->sort()->values()->all());

    expect($idsFor($shiftLeadDeveloper))->toBe(collect([
        $ownerRequirement->id,
        $clientDeveloperRequirement->id,
        $shiftDeveloperRequirement->id,
        $userRequirement->id,
        $guestRequirement->id,
        $assignedRequirement->id,
    ])->sort()->values()->all());

    expect($idsFor($clientDeveloper))->toBe(collect([
        $ownerRequirement->id,
        $clientDeveloperRequirement->id,
        $assignedRequirement->id,
    ])->sort()->values()->all());

    expect($idsFor($shiftDeveloper))->toBe(collect([
        $ownerRequirement->id,
        $shiftDeveloperRequirement->id,
    ])->sort()->values()->all());

    expect($idsFor($user))->toBe([$userRequirement->id]);
    expect($idsFor($guest))->toBe([$guestRequirement->id, $assignedRequirement->id]);
});

test('requirement index exposes registered project environment keys and labels without urls', function () {
    $externalIdentity = ($this->createRoleExternalIdentity)('environment-reader', 'client_developer');
    $this->project->environments()->create([
        'environment' => 'pre-production',
        'url' => 'https://pre-production.example.com',
        'callback_trusted_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/requirements?'.http_build_query([
            'project' => $this->project->token,
            'user' => ($this->externalPayload)($externalIdentity),
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('project_environments.0.key', 'pre-production')
        ->assertJsonPath('project_environments.0.label', 'Pre Production')
        ->assertJsonMissingPath('project_environments.0.url');
});

test('requirement submission is restricted to external requirement contributor roles', function (string $role, int $status) {
    $externalIdentity = ($this->createRoleExternalIdentity)('submission-'.$role, $role);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'Submission role check',
            'user' => ($this->externalPayload)($externalIdentity),
            'metadata' => [
                'environment' => 'testing',
                'url' => 'https://example.com/portal',
            ],
            'items' => [
                [
                    'title' => 'Role gated requirement',
                    'description' => 'Only contributor roles can submit this.',
                ],
            ],
        ]);

    $response->assertStatus($status);

    if ($status === 201) {
        $response->assertJsonCount(1, 'items');

        return;
    }

    $this->assertDatabaseMissing('tasks', [
        'title' => 'Role gated requirement',
        'project_id' => $this->project->id,
    ]);
})->with([
    'owner' => ['owner', 201],
    'client developer' => ['client_developer', 201],
    'shift lead developer' => ['shift_lead_developer', 201],
    'shift developer' => ['shift_developer', 201],
    'user' => ['user', 403],
    'guest' => ['guest', 403],
]);
