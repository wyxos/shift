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
});

test('external users can create a requirement batch with multiple task shaped items', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/requirements/batches', [
            'project' => $this->project->token,
            'title' => 'June client requirements',
            'user' => $this->externalUserData,
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
