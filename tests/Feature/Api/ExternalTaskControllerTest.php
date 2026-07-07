<?php

use App\Models\Attachment;
use App\Models\ExternalContact;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    // Create a user and generate a token for API access
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;

    // Create a project with a token for API access
    $this->project = Project::factory()->create([
        'token' => 'test-project-token',
        'author_id' => $this->user->id,
    ]);
    $this->project->environments()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
    ]);

    // External user data
    $this->externalUserData = [
        'id' => 'ext-123',
        'name' => 'External User',
        'email' => 'external@example.com',
        'environment' => 'testing',
        'url' => 'https://example.com',
    ];

    // Create an external user
    $this->externalUser = ExternalUser::create([
        'external_id' => $this->externalUserData['id'],
        'name' => $this->externalUserData['name'],
        'email' => $this->externalUserData['email'],
        'environment' => $this->externalUserData['environment'],
        'url' => $this->externalUserData['url'],
        'project_id' => $this->project->id,
    ]);

    $this->createRoleExternalUser = function (string $externalId, string $role, array $overrides = []): ExternalUser {
        return ExternalUser::create([
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

    $this->createSubmittedTask = function (ExternalUser $submitter, array $attributes = []): Task {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            ...$attributes,
        ]);
        $task->submitter()->associate($submitter)->save();

        return $task;
    };
});

test('external task visibility follows the role matrix', function () {
    $owner = ($this->createRoleExternalUser)('owner-1', 'owner');
    $clientDeveloper = ($this->createRoleExternalUser)('client-developer-1', 'client_developer');
    $shiftLeadDeveloper = ($this->createRoleExternalUser)('shift-lead-1', 'shift_lead_developer');
    $shiftDeveloper = ($this->createRoleExternalUser)('shift-developer-1', 'shift_developer');
    $user = ($this->createRoleExternalUser)('user-1', 'user');
    $guest = ($this->createRoleExternalUser)('guest-1', 'guest');

    $ownerTask = ($this->createSubmittedTask)($owner, ['title' => 'Owner task']);
    $clientDeveloperTask = ($this->createSubmittedTask)($clientDeveloper, ['title' => 'Client developer task']);
    $shiftDeveloperTask = ($this->createSubmittedTask)($shiftDeveloper, ['title' => 'Shift developer task']);
    $userTask = ($this->createSubmittedTask)($user, ['title' => 'User task']);
    $guestTask = ($this->createSubmittedTask)($guest, ['title' => 'Guest task']);
    $assignedTask = ($this->createSubmittedTask)($guest, ['title' => 'Assigned guest task']);
    $assignedTask->externalUsers()->attach($clientDeveloper->id);

    $idsFor = function (ExternalUser $externalUser): array {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/tasks?'.http_build_query([
                'project' => $this->project->token,
                'user' => ($this->externalPayload)($externalUser),
            ]));

        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    };

    expect($idsFor($owner))->toBe(collect([
        $ownerTask->id,
        $clientDeveloperTask->id,
        $shiftDeveloperTask->id,
        $userTask->id,
        $guestTask->id,
        $assignedTask->id,
    ])->sort()->values()->all());

    expect($idsFor($shiftLeadDeveloper))->toBe(collect([
        $ownerTask->id,
        $clientDeveloperTask->id,
        $shiftDeveloperTask->id,
        $userTask->id,
        $guestTask->id,
        $assignedTask->id,
    ])->sort()->values()->all());

    expect($idsFor($clientDeveloper))->toBe(collect([
        $ownerTask->id,
        $clientDeveloperTask->id,
        $assignedTask->id,
    ])->sort()->values()->all());

    expect($idsFor($shiftDeveloper))->toBe(collect([
        $ownerTask->id,
        $shiftDeveloperTask->id,
    ])->sort()->values()->all());

    expect($idsFor($user))->toBe([$userTask->id]);
    expect($idsFor($guest))->toBe([$guestTask->id, $assignedTask->id]);
});

test('linked external contacts can view tasks submitted by or assigned to linked accounts', function () {
    $this->project->environments()->updateOrCreate(
        ['environment' => 'production'],
        ['url' => 'https://example.com/production'],
    );

    $testingEnvironment = $this->project->environments()->where('environment', 'testing')->firstOrFail();
    $productionEnvironment = $this->project->environments()->where('environment', 'production')->firstOrFail();

    $contact = ExternalContact::create([
        'project_id' => $this->project->id,
    ]);

    $primaryAccount = ($this->createRoleExternalUser)('linked-primary', 'user', [
        'environment' => 'testing',
        'url' => 'https://example.com',
        'email' => 'shared-contact@example.com',
    ]);
    $secondaryAccount = ($this->createRoleExternalUser)('linked-secondary', 'user', [
        'environment' => 'production',
        'url' => 'https://example.com/production',
        'email' => 'shared-contact@example.com',
    ]);
    $unlinkedAccount = ($this->createRoleExternalUser)('unlinked-user', 'user');

    $primaryAccount->forceFill([
        'external_contact_id' => $contact->id,
        'project_environment_id' => $testingEnvironment->id,
    ])->save();
    $secondaryAccount->forceFill([
        'external_contact_id' => $contact->id,
        'project_environment_id' => $productionEnvironment->id,
    ])->save();
    $unlinkedAccount->forceFill([
        'project_environment_id' => $testingEnvironment->id,
    ])->save();

    $submittedBySecondary = ($this->createSubmittedTask)($secondaryAccount, [
        'title' => 'Submitted by linked production account',
    ]);
    $assignedToSecondary = ($this->createSubmittedTask)($unlinkedAccount, [
        'title' => 'Assigned to linked production account',
    ]);
    $assignedToSecondary->externalUsers()->attach($secondaryAccount->id);
    $unlinkedTask = ($this->createSubmittedTask)($unlinkedAccount, [
        'title' => 'Unlinked account task',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => ($this->externalPayload)($primaryAccount),
        ]));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($submittedBySecondary->id);
    expect($ids)->toContain($assignedToSecondary->id);
    expect($ids)->not->toContain($unlinkedTask->id);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$submittedBySecondary->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => ($this->externalPayload)($primaryAccount),
        ]))
        ->assertOk()
        ->assertJsonPath('id', $submittedBySecondary->id);
});

test('project environment identity resolves an external account when the request url changes', function () {
    $testingEnvironment = $this->project->environments()->where('environment', 'testing')->firstOrFail();
    $this->externalUser->forceFill([
        'project_environment_id' => $testingEnvironment->id,
    ])->save();

    $task = ($this->createSubmittedTask)($this->externalUser, [
        'title' => 'Task visible after client URL changes',
    ]);

    $payload = $this->externalUserData;
    $payload['url'] = 'https://renamed-client.example.com';

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => $payload,
        ]))
        ->assertOk()
        ->assertJsonPath('id', $task->id);
});

test('index returns tasks for external user', function () {
    // Create tasks submitted by the external user
    $task1 = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task1->submitter()->associate($this->externalUser)->save();

    $task2 = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task2->submitter()->associate($this->externalUser)->save();

    // Create a task by another user (should not be returned)
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    $task3 = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task3->submitter()->associate($otherExternalUser)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'data');
});

test('index and show expose task type metadata for app error tasks', function () {
    $normalTask = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Normal customer task',
        'updated_at' => now()->subMinute(),
    ]);
    $normalTask->submitter()->associate($this->externalUser)->save();

    $errorTask = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Checkout failed',
        'error_signature' => str_repeat('c', 64),
        'error_source' => 'backend',
        'updated_at' => now(),
    ]);
    $errorTask->submitter()->associate($this->externalUser)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response->assertOk()
        ->assertJsonPath('data.0.id', $errorTask->id)
        ->assertJsonPath('data.0.type', 'app_error')
        ->assertJsonPath('data.0.type_label', 'App error')
        ->assertJsonPath('data.1.id', $normalTask->id)
        ->assertJsonPath('data.1.type', 'task')
        ->assertJsonPath('data.1.type_label', 'Task');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$errorTask->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]))
        ->assertOk()
        ->assertJsonPath('type', 'app_error')
        ->assertJsonPath('type_label', 'App error');
});

test('project token API calls require authenticated user project access', function () {
    $otherUser = User::factory()->create();
    $otherToken = $otherUser->createToken('other-token')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]))
        ->assertNotFound();

    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->getJson('/api/collaborators/internal?'.http_build_query([
            'project' => $this->project->token,
        ]))
        ->assertNotFound();

    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->postJson('/api/tasks', [
            'title' => 'Blocked Cross Token Task',
            'project' => $this->project->token,
            'user' => $this->externalUserData,
            'metadata' => [
                'url' => 'https://example.com/task/blocked',
                'environment' => 'testing',
            ],
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('tasks', [
        'title' => 'Blocked Cross Token Task',
        'project_id' => $this->project->id,
    ]);
});

test('index supports environment filtering and priority sorting', function () {
    $stagingLow = Task::factory()->create([
        'project_id' => $this->project->id,
        'priority' => 'low',
    ]);
    $stagingLow->submitter()->associate($this->externalUser)->save();
    $stagingLow->metadata()->create([
        'environment' => 'staging',
        'url' => 'https://example.com/staging-low',
    ]);

    $stagingHigh = Task::factory()->create([
        'project_id' => $this->project->id,
        'priority' => 'high',
    ]);
    $stagingHigh->submitter()->associate($this->externalUser)->save();
    $stagingHigh->metadata()->create([
        'environment' => 'staging',
        'url' => 'https://example.com/staging-high',
    ]);

    $productionTask = Task::factory()->create([
        'project_id' => $this->project->id,
        'priority' => 'high',
    ]);
    $productionTask->submitter()->associate($this->externalUser)->save();
    $productionTask->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/production',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
            'environment' => 'staging',
            'sort_by' => 'priority',
        ]));

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.id', $stagingHigh->id);
    $response->assertJsonPath('data.1.id', $stagingLow->id);
    $response->assertJsonPath('data.0.environment', 'staging');
    $response->assertJsonPath('data.1.environment', 'staging');
});

test('show returns task details', function () {
    // Create a task submitted by the external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Test Task',
    ]);
    $task->submitter()->associate($this->externalUser)->save();

    $task->metadata()->create([
        'url' => 'https://example.com/task/123',
        'environment' => 'testing',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response->assertStatus(200);
    $response->assertJson([
        'id' => $task->id,
        'title' => 'Test Task',
        'project_id' => $this->project->id,
        'can_edit' => true,
        'can_update_status' => true,
        'can_update_priority' => true,
        'can_delete' => true,
        'can_comment' => true,
    ]);
});

test('show rewrites attachment urls without duplicating the client host', function () {
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Task With Inline Image',
    ]);
    $task->submitter()->associate($this->externalUser)->save();

    $attachment = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'screenshot.png',
        'path' => "attachments/{$task->id}/screenshot.png",
    ]);

    // Persist an absolute internal download URL; the API should rewrite to the client SDK proxy URL once.
    $task->description = '<p><img src="https://example.com/attachments/'.$attachment->id.'/download" /></p>';
    $task->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response->assertStatus(200);
    $desc = (string) ($response->json('description') ?? '');
    expect($desc)->toContain('https://example.com/shift/api/attachments/'.$attachment->id.'/download');
    expect($desc)->not->toContain('https://example.comhttps://example.com');
});

test('show returns 404 for task in different project', function () {
    // Create another project
    $otherProject = Project::factory()->create([
        'token' => 'other-project-token',
    ]);

    // Create a task in the other project
    $task = Task::factory()->create([
        'project_id' => $otherProject->id,
    ]);
    $task->submitter()->associate($this->externalUser)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token, // Using the original project token
            'user' => $this->externalUserData,
        ]));

    $response->assertStatus(404);
});

test('store creates new task', function () {
    $taskData = [
        'title' => 'New External Task',
        'description' => 'Task created via API',
        'project' => $this->project->token,
        'priority' => 'high',
        'status' => 'pending',
        'user' => $this->externalUserData,
        'metadata' => [
            'url' => 'https://example.com/task/new',
            'environment' => 'testing',
        ],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', $taskData);

    $response->assertStatus(201);
    $response->assertJson([
        'title' => 'New External Task',
        'description' => 'Task created via API',
        'priority' => 'high',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('tasks', [
        'title' => 'New External Task',
        'description' => 'Task created via API',
        'project_id' => $this->project->id,
        'priority' => 'high',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('task_metadata', [
        'url' => 'https://example.com/task/new',
        'environment' => 'testing',
    ]);
});

test('store dispatches create notification jobs for tagged external collaborators', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://example.com/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://example.com',
            'environment' => 'testing',
            'users' => [
                [
                    'id' => 'other-456',
                    'name' => 'Other External User',
                    'email' => 'other-collaborator@example.com',
                ],
            ],
        ], 200),
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    $taskData = [
        'title' => 'New External Task With Collaborator',
        'project' => $this->project->token,
        'user' => $this->externalUserData,
        'metadata' => [
            'url' => 'https://example.com/task/new-collaborator',
            'environment' => 'testing',
        ],
        'external_collaborators' => [
            [
                'id' => 'other-456',
                'name' => 'Other External User',
                'email' => 'other-collaborator@example.com',
            ],
        ],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', $taskData);

    $response->assertCreated();

    $task = Task::query()->where('title', 'New External Task With Collaborator')->firstOrFail();
    $externalUser = ExternalUser::query()->where('email', 'other-collaborator@example.com')->firstOrFail();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\NotifyExternalUser::class, function ($job) use ($task, $externalUser) {
        return $job->taskId === $task->id
            && $job->externalUserId === $externalUser->id;
    });

    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\NotifyExternalUser::class, function ($job) use ($task) {
        return $job->taskId === $task->id
            && $job->externalUserId === $this->externalUser->id;
    });
});

test('store does not dispatch create notification job for the external creator when self tagged as collaborator', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://example.com/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://example.com',
            'environment' => 'testing',
            'users' => [
                [
                    'id' => 'ext-123',
                    'name' => 'External User',
                    'email' => 'external@example.com',
                ],
            ],
        ], 200),
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    $taskData = [
        'title' => 'New External Task With Self Collaborator',
        'project' => $this->project->token,
        'user' => $this->externalUserData,
        'metadata' => [
            'url' => 'https://example.com/task/self-notification',
            'environment' => 'testing',
        ],
        'external_collaborators' => [
            [
                'id' => 'ext-123',
                'name' => 'External User',
                'email' => 'external@example.com',
            ],
        ],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', $taskData);

    $response->assertCreated();

    $task = Task::query()->where('title', 'New External Task With Self Collaborator')->firstOrFail();

    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\NotifyExternalUser::class, function ($job) use ($task) {
        return $job->taskId === $task->id
            && $job->externalUserId === $this->externalUser->id;
    });
});

test('update updates task', function () {
    // Create a task submitted by the external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Original Title',
        'status' => 'pending',
        'priority' => 'low',
    ]);
    $task->submitter()->associate($this->externalUser)->save();

    $updateData = [
        'title' => 'Updated Title',
        'description' => 'Updated description',
        'status' => 'in-progress',
        'priority' => 'high',
        'project' => $this->project->token,
        'user' => [
            'id' => $this->externalUser->external_id,
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
        ],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->putJson("/api/tasks/{$task->id}", $updateData);

    $response->assertStatus(200);
    $response->assertJson([
        'title' => 'Updated Title',
        'description' => 'Updated description',
        'status' => 'in-progress',
        'priority' => 'high',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'Updated Title',
        'description' => 'Updated description',
        'status' => 'in-progress',
        'priority' => 'high',
    ]);
});

test('update returns 403 for unauthorized user', function () {
    // Create a task submitted by a different external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    $updateData = [
        'title' => 'Updated Title',
        'project' => $this->project->token,
        'user' => [
            'id' => $this->externalUser->external_id, // Different user than the submitter
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
        ],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->putJson("/api/tasks/{$task->id}", $updateData);

    $response->assertStatus(403);
});

test('destroy deletes task', function () {
    // Create a task submitted by the external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task->submitter()->associate($this->externalUser)->save();

    // Create attachments for the task
    $attachment1 = \App\Models\Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'test-document1.pdf',
        'path' => "attachments/{$task->id}/test-document1.pdf",
    ]);

    $attachment2 = \App\Models\Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'test-document2.pdf',
        'path' => "attachments/{$task->id}/test-document2.pdf",
    ]);

    // Create fake files in storage
    \Illuminate\Support\Facades\Storage::put($attachment1->path, 'test content 1');
    \Illuminate\Support\Facades\Storage::put($attachment2->path, 'test content 2');

    // Verify files exist before deletion
    \Illuminate\Support\Facades\Storage::assertExists($attachment1->path);
    \Illuminate\Support\Facades\Storage::assertExists($attachment2->path);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->deleteJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id,
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ],
        ]));

    $response->assertStatus(200);
    $response->assertJson([
        'message' => 'Task deleted successfully',
    ]);

    // Verify task is deleted
    $this->assertDatabaseMissing('tasks', [
        'id' => $task->id,
    ]);

    // Verify attachments are deleted from database
    $this->assertDatabaseMissing('attachments', [
        'id' => $attachment1->id,
    ]);
    $this->assertDatabaseMissing('attachments', [
        'id' => $attachment2->id,
    ]);

    // Verify files are deleted from storage
    \Illuminate\Support\Facades\Storage::assertMissing($attachment1->path);
    \Illuminate\Support\Facades\Storage::assertMissing($attachment2->path);
});

test('destroy returns 403 for unauthorized user', function () {
    // Create a task submitted by a different external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->deleteJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id, // Different user than the submitter
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ],
        ]));

    $response->assertStatus(403);
});

test('destroy is forbidden for non submitter with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    $owner = ($this->createRoleExternalUser)('owner-delete-1', 'owner');
    $task = ($this->createSubmittedTask)($owner, [
        'title' => 'Owner task visible to assigned developer',
    ]);
    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->deleteJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => ($this->externalPayload)($this->externalUser->fresh()),
        ]));

    $response->assertStatus(403);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'Owner task visible to assigned developer',
    ]);
});

test('requirement item mutation is submitter only for visible non submitters', function () {
    $clientDeveloper = ($this->createRoleExternalUser)('client-developer-requirement-edit', 'client_developer');
    $owner = ($this->createRoleExternalUser)('owner-requirement-edit', 'owner');
    $requirement = ($this->createSubmittedTask)($owner, [
        'title' => 'Owner submitted requirement',
        'description' => 'Original requirement details.',
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $requirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'submitted_title' => 'Owner submitted requirement',
        'submitted_description' => 'Original requirement details.',
    ]);

    $payload = [
        'project' => $this->project->token,
        'user' => ($this->externalPayload)($clientDeveloper),
    ];

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->putJson("/api/tasks/{$requirement->id}", [
            ...$payload,
            'title' => 'Changed requirement',
            'description' => 'Changed requirement details.',
            'status' => 'completed',
            'priority' => 'high',
        ])
        ->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$requirement->id}/toggle-status", [
            ...$payload,
            'status' => 'completed',
        ])
        ->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$requirement->id}/toggle-priority", [
            ...$payload,
            'priority' => 'high',
        ])
        ->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->deleteJson("/api/tasks/{$requirement->id}?".http_build_query($payload))
        ->assertStatus(403);

    $requirement->refresh();

    expect($requirement->title)->toBe('Owner submitted requirement');
    expect($requirement->description)->toBe('Original requirement details.');
    expect($requirement->status)->toBe('pending');
    expect($requirement->priority)->toBe('medium');
});

test('store rejects tagged external collaborators when the environment is not registered', function () {
    \Illuminate\Support\Facades\Http::fake();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', [
            'title' => 'Task With Unregistered Environment',
            'project' => $this->project->token,
            'user' => $this->externalUserData,
            'metadata' => [
                'url' => 'https://example.com/task/unregistered-environment',
                'environment' => 'production',
            ],
            'external_collaborators' => [
                [
                    'id' => 'client-2',
                    'name' => 'Project User',
                    'email' => 'project@example.com',
                ],
            ],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['environment'])
        ->assertJsonPath('errors.environment.0', 'The selected environment is not registered for this project.');

    \Illuminate\Support\Facades\Http::assertNothingSent();
});

test('external task creation only notifies explicitly tagged internal collaborators', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $taggedInternalCollaborator = User::factory()->create();
    $untaggedProjectUser = User::factory()->create();

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $taggedInternalCollaborator->id,
        'user_email' => $taggedInternalCollaborator->email,
        'user_name' => $taggedInternalCollaborator->name,
        'registration_status' => 'registered',
    ]);

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $untaggedProjectUser->id,
        'user_email' => $untaggedProjectUser->email,
        'user_name' => $untaggedProjectUser->name,
        'registration_status' => 'registered',
    ]);

    $taskData = [
        'title' => 'External Notification Policy Task',
        'description' => 'This task should only notify explicit recipients',
        'project' => $this->project->token,
        'priority' => 'high',
        'status' => 'pending',
        'user' => $this->externalUserData,
        'metadata' => [
            'url' => 'https://example.com/task/notification-policy',
            'environment' => 'testing',
        ],
        'internal_collaborator_ids' => [$taggedInternalCollaborator->id],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', $taskData);

    $response->assertStatus(201);

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $taggedInternalCollaborator,
        \App\Notifications\TaskCreationNotification::class,
        function ($notification, $channels, $notifiable) use ($response) {
            return $notification->toArray($notifiable)['url'] === route('tasks.index', ['task' => $response->json('id')]);
        }
    );

    \Illuminate\Support\Facades\Notification::assertNotSentTo(
        [$this->user, $untaggedProjectUser],
        \App\Notifications\TaskCreationNotification::class,
    );
});
test('index returns tasks with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    // Create another external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    // Create a task submitted by the other external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Task by other user',
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    // Grant access to our external user
    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response->assertStatus(200);
    $response->assertJsonPath('data.0.title', 'Task by other user');
});

test('show returns task with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    // Create another external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    // Create a task submitted by the other external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Task by other user',
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    // Grant access to our external user
    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/tasks/{$task->id}?".http_build_query([
            'project' => $this->project->token,
            'user' => $this->externalUserData,
        ]));

    $response->assertStatus(200);
    $response->assertJson([
        'id' => $task->id,
        'title' => 'Task by other user',
        'can_edit' => false,
        'can_update_status' => false,
        'can_update_priority' => false,
        'can_delete' => false,
        'can_comment' => true,
    ]);
});

test('update is forbidden for non submitter with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    // Create another external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    // Create a task submitted by the other external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Original Title',
        'status' => 'pending',
        'priority' => 'low',
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    // Grant access to our external user
    $task->externalUsers()->attach($this->externalUser->id);

    $updateData = [
        'title' => 'Updated By Access',
        'description' => 'Updated by user with granted access',
        'status' => 'in-progress',
        'priority' => 'high',
        'project' => $this->project->token,
        'user' => [
            'id' => $this->externalUser->external_id,
            'environment' => $this->externalUser->environment,
            'url' => $this->externalUser->url,
        ],
    ];

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->putJson("/api/tasks/{$task->id}", $updateData);

    $response->assertStatus(403);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'Original Title',
        'description' => $task->description,
        'status' => 'pending',
        'priority' => 'low',
    ]);
});

test('toggle status is forbidden for non submitter with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    // Create another external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    // Create a task submitted by the other external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'status' => 'pending',
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    // Grant access to our external user
    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$task->id}/toggle-status", [
            'status' => 'completed',
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id,
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ],
        ]);

    $response->assertStatus(403);

    expect($task->fresh()->status)->toBe('pending');
});

test('toggle status rejects awaiting feedback for non submitter with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-456',
        'name' => 'Other User',
        'email' => 'other2@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'status' => 'pending',
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$task->id}/toggle-status", [
            'status' => 'awaiting-feedback',
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id,
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ],
        ]);

    $response->assertStatus(403);

    expect($task->fresh()->status)->toBe('pending');
});

test('toggle priority is forbidden for non submitter with granted access', function () {
    $this->externalUser->update(['role' => 'client_developer']);

    // Create another external user
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    // Create a task submitted by the other external user
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'priority' => 'low',
    ]);
    $task->submitter()->associate($otherExternalUser)->save();

    // Grant access to our external user
    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$task->id}/toggle-priority", [
            'priority' => 'high',
            'project' => $this->project->token,
            'user' => [
                'id' => $this->externalUser->external_id,
                'environment' => $this->externalUser->environment,
                'url' => $this->externalUser->url,
            ],
        ]);

    $response->assertStatus(403);

    expect($task->fresh()->priority)->toBe('low');
});

test('internal collaborator lookup endpoint returns registered shift users for the project', function () {
    $this->project->client->organisation->update(['name' => 'Northwind Organisation']);

    $registeredUser = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $registeredUser->id,
        'user_email' => $registeredUser->email,
        'user_name' => $registeredUser->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/collaborators/internal?'.http_build_query([
            'project' => $this->project->token,
            'search' => $registeredUser->email,
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('organisation_name', 'Northwind Organisation')
        ->assertJsonFragment(['id' => $registeredUser->id]);
});

test('external submitter can update collaborators through collaborator endpoint', function () {
    $internalCollaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $internalCollaborator->id,
        'user_email' => $internalCollaborator->email,
        'user_name' => $internalCollaborator->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'External collaborator update',
    ]);
    $task->submitter()->associate($this->externalUser)->save();
    $task->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$task->id}/collaborators", [
            'project' => $this->project->token,
            'user' => $this->externalUserData,
            'internal_collaborator_ids' => [$internalCollaborator->id],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('can_manage_collaborators', true)
        ->assertJsonPath('internal_collaborators.0.id', $internalCollaborator->id);

    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'user_id' => $internalCollaborator->id,
    ]);
});

test('external submitter can explicitly remain a collaborator without self notification job', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://example.com/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://example.com',
            'environment' => 'testing',
            'users' => [
                [
                    'id' => 'ext-123',
                    'name' => 'External User',
                    'email' => 'external@example.com',
                ],
            ],
        ], 200),
    ]);
    \Illuminate\Support\Facades\Queue::fake();

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'External self collaborator update',
    ]);
    $task->submitter()->associate($this->externalUser)->save();
    $task->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$task->id}/collaborators", [
            'project' => $this->project->token,
            'user' => $this->externalUserData,
            'external_collaborators' => [
                [
                    'id' => 'ext-123',
                    'name' => 'External User',
                    'email' => 'external@example.com',
                ],
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('external_collaborators.0.id', 'ext-123');

    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'external_user_id' => $this->externalUser->id,
        'kind' => 'external',
    ]);

    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\NotifyExternalCollaboratorAdded::class, function ($job) use ($task) {
        return $job->taskId === $task->id
            && $job->externalUserId === $this->externalUser->id;
    });
});

test('non submitter external collaborator cannot update collaborators', function () {
    $otherExternalUser = ExternalUser::create([
        'external_id' => 'other-123',
        'name' => 'Other User',
        'email' => 'other@example.com',
        'environment' => 'testing',
        'url' => 'https://other.com',
        'project_id' => $this->project->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task->submitter()->associate($otherExternalUser)->save();
    $task->externalUsers()->attach($this->externalUser->id);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/tasks/{$task->id}/collaborators", [
            'project' => $this->project->token,
            'user' => $this->externalUserData,
            'internal_collaborator_ids' => [],
        ]);

    $response->assertStatus(403);
});

test('store requires an environment before syncing external collaborators', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', [
            'title' => 'Needs environment',
            'project' => $this->project->token,
            'external_collaborators' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['environment'])
        ->assertJsonPath('errors.environment.0', 'Select an environment before tagging external collaborators.');
});

test('store sanitizes dangerous external description html', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->postJson('/api/tasks', [
            'title' => 'Sanitized External Task',
            'description' => implode('', [
                '<p>Hello</p>',
                '<script>alert(1)</script>',
                '<blockquote class="shift-reply extra" data-reply-to="42"><p>Reply</p></blockquote>',
                '<p><a href="javascript:alert(1)">bad</a></p>',
            ]),
            'project' => $this->project->token,
            'priority' => 'high',
            'status' => 'pending',
            'user' => $this->externalUserData,
            'metadata' => [
                'url' => 'https://example.com/task/sanitized',
                'environment' => 'testing',
            ],
        ]);

    $response->assertCreated();

    $task = Task::where('title', 'Sanitized External Task')->firstOrFail();

    expect($task->description)->toContain('data-reply-to="42"');
    expect($task->description)->toContain('class="shift-reply"');
    expect($task->description)->not->toContain('<script');
    expect($task->description)->not->toContain('javascript:');

    $responseDescription = (string) $response->json('description');
    expect($responseDescription)->toContain('data-reply-to="42"');
    expect($responseDescription)->not->toContain('<script');
    expect($responseDescription)->not->toContain('javascript:');
});
