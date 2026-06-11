<?php

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;

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
    ]);

    $this->externalUserData = [
        'id' => 'client-123',
        'name' => 'Client User',
        'email' => 'client@example.com',
        'environment' => 'testing',
        'url' => 'https://example.com',
    ];

    $this->createRoleExternalUser = function (string $externalId, string $role, array $overrides = []): ExternalUser {
        return ExternalUser::query()->create([
            'external_id' => $externalId,
            'name' => $overrides['name'] ?? 'External '.$externalId,
            'email' => $overrides['email'] ?? $externalId.'@example.com',
            'environment' => $overrides['environment'] ?? 'testing',
            'url' => $overrides['url'] ?? 'https://example.com',
            'project_id' => $overrides['project_id'] ?? $this->project->id,
            'role' => $role,
        ]);
    };

    $this->externalPayload = fn (ExternalUser $externalUser): array => [
        'id' => $externalUser->external_id,
        'name' => $externalUser->name,
        'email' => $externalUser->email,
        'environment' => $externalUser->environment,
        'url' => $externalUser->url,
    ];

    $this->createRequirement = function (ExternalUser $submitter, array $attributes = []): Task {
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
            'requirement_batch_id' => $attributes['requirement_batch_id'] ?? null,
            'submitted_title' => $attributes['submitted_title'] ?? ($attributes['title'] ?? 'Requirement item'),
            'submitted_description' => $attributes['submitted_description'] ?? 'Original requirement details.',
        ]);

        return $task;
    };
});

test('permitted external roles can create a requirement batch with multiple task shaped items', function () {
    $externalUser = ($this->createRoleExternalUser)('client-123', 'client_developer', [
        'name' => 'Client User',
        'email' => 'client@example.com',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'June client requirements',
            'user' => ($this->externalPayload)($externalUser),
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
            'requirement_batch_id' => $batchId,
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
        ]);
    }
});

test('requirement visibility follows the external role matrix', function () {
    $owner = ($this->createRoleExternalUser)('owner-requirement-1', 'owner');
    $clientDeveloper = ($this->createRoleExternalUser)('client-developer-requirement-1', 'client_developer');
    $shiftLeadDeveloper = ($this->createRoleExternalUser)('shift-lead-requirement-1', 'shift_lead_developer');
    $shiftDeveloper = ($this->createRoleExternalUser)('shift-developer-requirement-1', 'shift_developer');
    $user = ($this->createRoleExternalUser)('user-requirement-1', 'user');
    $guest = ($this->createRoleExternalUser)('guest-requirement-1', 'guest');

    $ownerRequirement = ($this->createRequirement)($owner, ['title' => 'Owner requirement']);
    $clientDeveloperRequirement = ($this->createRequirement)($clientDeveloper, ['title' => 'Client developer requirement']);
    $shiftDeveloperRequirement = ($this->createRequirement)($shiftDeveloper, ['title' => 'Shift developer requirement']);
    $userRequirement = ($this->createRequirement)($user, ['title' => 'User requirement']);
    $guestRequirement = ($this->createRequirement)($guest, ['title' => 'Guest requirement']);
    $assignedRequirement = ($this->createRequirement)($guest, ['title' => 'Assigned guest requirement']);
    $assignedRequirement->externalUsers()->attach($clientDeveloper->id);

    $idsFor = function (ExternalUser $externalUser): array {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/requirements?'.http_build_query([
                'project' => $this->project->token,
                'user' => ($this->externalPayload)($externalUser),
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

test('requirement submission is restricted to external requirement contributor roles', function (string $role, int $status) {
    $externalUser = ($this->createRoleExternalUser)('submission-'.$role, $role);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'Submission role check',
            'user' => ($this->externalPayload)($externalUser),
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

test('external users can list their requirement items including finalized requirements', function () {
    $externalUser = ExternalUser::query()->create([
        'external_id' => $this->externalUserData['id'],
        'name' => $this->externalUserData['name'],
        'email' => $this->externalUserData['email'],
        'environment' => $this->externalUserData['environment'],
        'url' => $this->externalUserData['url'],
        'project_id' => $this->project->id,
    ]);
    $batch = RequirementBatch::query()->create([
        'project_id' => $this->project->id,
        'external_user_id' => $externalUser->id,
        'title' => 'June client requirements',
    ]);

    $openRequirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Open requirement',
        'status' => 'pending',
    ]);
    $openRequirement->submitter()->associate($externalUser)->save();
    $openRequirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/open',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_batch_id' => $batch->id,
        'submitted_title' => 'Open requirement',
        'submitted_description' => 'Needs clarification.',
    ]);

    $finalizedRequirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Finalized requirement',
        'status' => 'pending',
    ]);
    $finalizedRequirement->submitter()->associate($externalUser)->save();
    $finalizedRequirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/finalized',
        'phase' => 'task',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_batch_id' => $batch->id,
        'submitted_title' => 'Finalized requirement',
        'submitted_description' => 'Already confirmed.',
        'finalized_at' => now(),
        'finalized_by' => $this->user->id,
    ]);

    $normalTask = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Normal task',
        'status' => 'pending',
    ]);
    $normalTask->submitter()->associate($externalUser)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/requirements?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $finalizedRequirement->id)
        ->assertJsonPath('data.0.phase', 'task')
        ->assertJsonPath('data.0.finalized', true)
        ->assertJsonPath('data.0.batch.id', $batch->id)
        ->assertJsonPath('data.0.batch.title', 'June client requirements')
        ->assertJsonPath('data.0.batch.total_items', 2)
        ->assertJsonPath('data.0.batch.requirement_items', 1)
        ->assertJsonPath('data.0.batch.finalized_items', 1)
        ->assertJsonPath('data.1.id', $openRequirement->id)
        ->assertJsonPath('data.1.phase', 'requirement')
        ->assertJsonPath('data.1.finalized', false)
        ->assertJsonPath('data.1.can_edit', true)
        ->assertJsonPath('data.1.can_update_status', true)
        ->assertJsonPath('data.1.can_update_priority', true)
        ->assertJsonPath('data.1.can_delete', true)
        ->assertJsonPath('data.1.can_comment', true)
        ->assertJsonPath('data.1.batch.id', $batch->id)
        ->assertJsonPath('data.1.batch.total_items', 2)
        ->assertJsonPath('data.1.batch.requirement_items', 1)
        ->assertJsonPath('data.1.batch.finalized_items', 1);
});

test('normal external task index excludes requirement phase items', function () {
    $externalUser = ExternalUser::query()->create([
        'external_id' => $this->externalUserData['id'],
        'name' => $this->externalUserData['name'],
        'email' => $this->externalUserData['email'],
        'environment' => $this->externalUserData['environment'],
        'url' => $this->externalUserData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Requirement item',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalUser)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
    ]);

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Normal task',
        'status' => 'pending',
    ]);
    $task->submitter()->associate($externalUser)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $task->id);
});

test('external submitter can open a requirement item through the task detail endpoint with phase metadata', function () {
    $externalUser = ExternalUser::query()->create([
        'external_id' => $this->externalUserData['id'],
        'name' => $this->externalUserData['name'],
        'email' => $this->externalUserData['email'],
        'environment' => $this->externalUserData['environment'],
        'url' => $this->externalUserData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Requirement item',
        'description' => 'Accepted through intake.',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalUser)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'submitted_title' => 'Requirement item',
        'submitted_description' => 'Original client wording.',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks/'.$requirement->id.'?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('id', $requirement->id)
        ->assertJsonPath('phase', 'requirement')
        ->assertJsonPath('finalized', false)
        ->assertJsonPath('submitted_title', 'Requirement item')
        ->assertJsonPath('submitted_description', 'Original client wording.')
        ->assertJsonPath('external_collaborators.0.id', 'client-123')
        ->assertJsonPath('external_collaborators.0.name', 'Client User')
        ->assertJsonPath('external_collaborators.0.email', 'client@example.com');
});

test('finalizing a requirement preserves the task id and existing threads', function () {
    $externalUser = ExternalUser::query()->create([
        'external_id' => $this->externalUserData['id'],
        'name' => $this->externalUserData['name'],
        'email' => $this->externalUserData['email'],
        'environment' => $this->externalUserData['environment'],
        'url' => $this->externalUserData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Initial requirement title',
        'description' => 'Initial requirement details.',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalUser)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'submitted_title' => 'Initial requirement title',
        'submitted_description' => 'Initial requirement details.',
    ]);

    $thread = new TaskThread([
        'type' => 'external',
        'content' => '<p>Can you confirm the export columns?</p>',
        'sender_name' => $externalUser->name,
    ]);
    $thread->sender()->associate($externalUser);
    $requirement->threads()->save($thread);

    $response = $this->actingAs($this->user)
        ->patchJson(route('requirements.v2.finalize', $requirement), [
            'title' => 'Confirmed renewal export',
            'description' => '<p>Build the confirmed export.</p>',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('task.id', $requirement->id)
        ->assertJsonPath('task.phase', 'task')
        ->assertJsonPath('task.title', 'Confirmed renewal export');

    $requirement->refresh();
    expect($requirement->threads()->whereKey($thread->id)->exists())->toBeTrue();
    expect($requirement->threads()->where('content', '<p>Requirement finalized as task.</p>')->exists())->toBeTrue();

    $this->assertDatabaseHas('task_metadata', [
        'task_id' => $requirement->id,
        'phase' => 'task',
        'submitted_title' => 'Initial requirement title',
        'submitted_description' => 'Initial requirement details.',
        'finalized_by' => $this->user->id,
    ]);
});

test('external users cannot finalize requirements', function () {
    $externalUser = ExternalUser::query()->create([
        'external_id' => $this->externalUserData['id'],
        'name' => $this->externalUserData['name'],
        'email' => $this->externalUserData['email'],
        'environment' => $this->externalUserData['environment'],
        'url' => $this->externalUserData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Client requirement',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalUser)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson(route('requirements.v2.finalize', $requirement), [
            'title' => 'Attempted finalize',
            'description' => 'External users should not be able to finalize.',
        ])
        ->assertUnauthorized();
});
