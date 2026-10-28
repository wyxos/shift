<?php

use App\Models\ExternalIdentity;
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

test('external users can list their requirement items including finalized requirements', function () {
    $externalIdentity = ExternalIdentity::query()->create([
        'external_id' => $this->externalIdentityData['id'],
        'name' => $this->externalIdentityData['name'],
        'email' => $this->externalIdentityData['email'],
        'environment' => $this->externalIdentityData['environment'],
        'url' => $this->externalIdentityData['url'],
        'project_id' => $this->project->id,
    ]);
    $batch = RequirementBatch::query()->create([
        'project_id' => $this->project->id,
        'external_identity_id' => $externalIdentity->id,
        'title' => 'June client requirements',
    ]);

    $openRequirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Open requirement',
        'status' => 'pending',
    ]);
    $openRequirement->submitter()->associate($externalIdentity)->save();
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
    $finalizedRequirement->submitter()->associate($externalIdentity)->save();
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
    $normalTask->submitter()->associate($externalIdentity)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/requirements?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalIdentityData,
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
        ->assertJsonPath('data.1.requirement_status', 'submitted')
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
    $externalIdentity = ExternalIdentity::query()->create([
        'external_id' => $this->externalIdentityData['id'],
        'name' => $this->externalIdentityData['name'],
        'email' => $this->externalIdentityData['email'],
        'environment' => $this->externalIdentityData['environment'],
        'url' => $this->externalIdentityData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Requirement item',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalIdentity)->save();
    $requirement->externalCollaborators()->attach($externalIdentity->id);
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
    $task->submitter()->associate($externalIdentity)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalIdentityData,
        ]));

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $task->id);
});

test('external submitter can open a requirement item through the task detail endpoint with phase metadata', function () {
    $externalIdentity = ExternalIdentity::query()->create([
        'external_id' => $this->externalIdentityData['id'],
        'name' => $this->externalIdentityData['name'],
        'email' => $this->externalIdentityData['email'],
        'environment' => $this->externalIdentityData['environment'],
        'url' => $this->externalIdentityData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Requirement item',
        'description' => 'Accepted through intake.',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalIdentity)->save();
    $requirement->externalCollaborators()->attach($externalIdentity->id);
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
            'user' => $this->externalIdentityData,
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('id', $requirement->id)
        ->assertJsonPath('phase', 'requirement')
        ->assertJsonPath('requirement_status', 'submitted')
        ->assertJsonPath('finalized', false)
        ->assertJsonPath('submitted_title', 'Requirement item')
        ->assertJsonPath('submitted_description', 'Original client wording.')
        ->assertJsonPath('external_collaborators.0.id', 'client-123')
        ->assertJsonPath('external_collaborators.0.name', 'Client User')
        ->assertJsonPath('external_collaborators.0.email', 'client@example.com');
});

test('finalizing a requirement preserves the task id and existing threads', function () {
    $externalIdentity = ExternalIdentity::query()->create([
        'external_id' => $this->externalIdentityData['id'],
        'name' => $this->externalIdentityData['name'],
        'email' => $this->externalIdentityData['email'],
        'environment' => $this->externalIdentityData['environment'],
        'url' => $this->externalIdentityData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Initial requirement title',
        'description' => 'Initial requirement details.',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalIdentity)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_status' => 'ready-to-finalize',
        'submitted_title' => 'Initial requirement title',
        'submitted_description' => 'Initial requirement details.',
    ]);

    $thread = new TaskThread([
        'type' => 'external',
        'content' => '<p>Can you confirm the export columns?</p>',
        'sender_name' => $externalIdentity->name,
    ]);
    $thread->sender()->associate($externalIdentity);
    $requirement->threads()->save($thread);

    $response = $this->actingAs($this->user)
        ->patchJson(route('requirements.finalize', $requirement), [
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
        'requirement_status' => 'ready-to-finalize',
        'submitted_title' => 'Initial requirement title',
        'submitted_description' => 'Initial requirement details.',
        'finalized_by' => $this->user->id,
    ]);
});

test('parked requirement items cannot be finalized until they are ready', function () {
    $externalIdentity = ExternalIdentity::query()->create([
        'external_id' => $this->externalIdentityData['id'],
        'name' => $this->externalIdentityData['name'],
        'email' => $this->externalIdentityData['email'],
        'environment' => $this->externalIdentityData['environment'],
        'url' => $this->externalIdentityData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Parked requirement',
        'description' => 'Too early for this work.',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalIdentity)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_status' => 'parked',
        'submitted_title' => 'Parked requirement',
        'submitted_description' => 'Too early for this work.',
    ]);

    $this->actingAs($this->user)
        ->patchJson(route('requirements.finalize', $requirement), [
            'title' => 'Parked requirement',
            'description' => 'Too early for this work.',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only ready-to-finalize requirements can be finalized.');

    $this->assertDatabaseHas('task_metadata', [
        'task_id' => $requirement->id,
        'phase' => 'requirement',
        'requirement_status' => 'parked',
    ]);
});

test('external users cannot finalize requirements', function () {
    $externalIdentity = ExternalIdentity::query()->create([
        'external_id' => $this->externalIdentityData['id'],
        'name' => $this->externalIdentityData['name'],
        'email' => $this->externalIdentityData['email'],
        'environment' => $this->externalIdentityData['environment'],
        'url' => $this->externalIdentityData['url'],
        'project_id' => $this->project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Client requirement',
        'status' => 'pending',
    ]);
    $requirement->submitter()->associate($externalIdentity)->save();
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson(route('requirements.finalize', $requirement), [
            'title' => 'Attempted finalize',
            'description' => 'External users should not be able to finalize.',
        ])
        ->assertUnauthorized();
});
