<?php

use App\Enums\RequirementStatus;
use App\Models\ExternalUser;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\RequirementBatch;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('index displays tasks', function () {
    // Create a project owned by the user
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create tasks for the project
    $tasks = Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);

    // Set the submitter for each task
    foreach ($tasks as $task) {
        $task->submitter()->associate($this->user)->save();
    }

    $response = $this->actingAs($this->user)
        ->get(route('tasks.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 3)
        ->has('projects', 1)
    );
});

test('tasks index can be scoped by organisation route', function () {
    $firstOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $secondOrganisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $scopedProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $firstOrganisation->id,
    ]);
    $otherProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $secondOrganisation->id,
    ]);

    $scopedTask = Task::factory()->create([
        'project_id' => $scopedProject->id,
        'status' => 'pending',
    ]);
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'status' => 'pending',
    ]);

    $scopedTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('organisation.tasks', $firstOrganisation));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $scopedTask->id)
        ->where('filters.organisation_id', $firstOrganisation->id)
    );
});

test('tasks v2 can filter tasks by project query and includes project summary', function () {
    $firstProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Billing Console',
    ]);
    $secondProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Retail Dashboard',
    ]);

    $firstTask = Task::factory()->create([
        'project_id' => $firstProject->id,
        'status' => 'pending',
    ]);
    Task::factory()->create([
        'project_id' => $secondProject->id,
        'status' => 'pending',
    ]);

    $firstTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.index', ['project_id' => $firstProject->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $firstTask->id)
        ->where('tasks.data.0.project.id', $firstProject->id)
        ->where('tasks.data.0.project.name', 'Billing Console')
        ->where('filters.project_id', $firstProject->id)
    );
});

test('tasks v2 defaults to excluding completed tasks', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $pendingTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $completedTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'completed',
    ]);
    $onHoldTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'on-hold',
    ]);

    $pendingTask->submitter()->associate($this->user)->save();
    $completedTask->submitter()->associate($this->user)->save();
    $onHoldTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->get(route('tasks.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 2)
        ->where('filters.status', ['pending', 'in-progress', 'awaiting-feedback', 'on-hold'])
    );
});

test('tasks v2 excludes requirement phase items from the active task list', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Active task',
        'status' => 'pending',
    ]);
    $requirement = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Requirement item',
        'status' => 'pending',
    ]);

    $task->submitter()->associate($this->user)->save();
    $requirement->submitter()->associate($this->user)->save();
    $requirement->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'submitted_title' => 'Requirement item',
        'submitted_description' => 'Requirement details.',
    ]);

    $response = $this->actingAs($this->user)->get(route('tasks.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $task->id)
        ->where('surface', 'tasks')
    );
});

test('requirements index lists requirement intake items for review', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Requirement item',
        'status' => 'pending',
    ]);
    $normalTask = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Normal task',
        'status' => 'pending',
    ]);

    $requirement->submitter()->associate($this->user)->save();
    $normalTask->submitter()->associate($this->user)->save();
    $requirement->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'submitted_title' => 'Requirement item',
        'submitted_description' => 'Requirement details.',
    ]);

    $response = $this->actingAs($this->user)->get(route('requirements.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $requirement->id)
        ->where('tasks.data.0.phase', 'requirement')
        ->where('tasks.data.0.requirement_status', 'submitted')
        ->where('surface', 'requirements')
    );
});

test('requirements index filters by requirement lifecycle instead of task status', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $ready = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Ready requirement',
        'status' => 'pending',
    ]);
    $parked = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Parked requirement',
        'status' => 'pending',
    ]);

    foreach ([[$ready, 'ready-to-finalize'], [$parked, 'parked']] as [$requirement, $status]) {
        $requirement->submitter()->associate($this->user)->save();
        $requirement->metadata()->create([
            'environment' => 'production',
            'url' => 'https://example.com/requirement',
            'phase' => 'requirement',
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
            'requirement_status' => $status,
            'submitted_title' => $requirement->title,
            'submitted_description' => 'Requirement details.',
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('requirements.index', ['status' => ['parked']]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $parked->id)
        ->where('tasks.data.0.status', 'pending')
        ->where('tasks.data.0.requirement_status', 'parked')
        ->where('filters.status', ['parked'])
    );
});

test('requirements index includes batch summaries for grouped review', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $batch = RequirementBatch::query()->create([
        'project_id' => $project->id,
        'title' => 'June client requirements',
    ]);

    $firstRequirement = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Renewal report',
        'status' => 'pending',
    ]);
    $secondRequirement = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'CSV export',
        'status' => 'pending',
    ]);

    foreach ([$firstRequirement, $secondRequirement] as $requirement) {
        $requirement->submitter()->associate($this->user)->save();
        $requirement->metadata()->create([
            'environment' => 'production',
            'url' => 'https://example.com/requirement',
            'phase' => 'requirement',
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
            'requirement_batch_id' => $batch->id,
            'submitted_title' => $requirement->title,
            'submitted_description' => 'Client wording.',
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('requirements.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 2)
        ->where('tasks.data.0.batch.id', $batch->id)
        ->where('tasks.data.0.batch.title', 'June client requirements')
        ->where('tasks.data.0.batch.total_items', 2)
        ->where('tasks.data.0.batch.requirement_items', 2)
        ->where('tasks.data.0.batch.finalized_items', 0)
    );
});

test('requirements index can be scoped by organisation route', function () {
    $organisation = Organisation::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $scopedProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'organisation_id' => $organisation->id,
    ]);
    $otherProject = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $scopedRequirement = Task::factory()->create([
        'project_id' => $scopedProject->id,
        'title' => 'Scoped requirement',
        'status' => 'pending',
    ]);
    $otherRequirement = Task::factory()->create([
        'project_id' => $otherProject->id,
        'title' => 'Other requirement',
        'status' => 'pending',
    ]);

    foreach ([$scopedRequirement, $otherRequirement] as $requirement) {
        $requirement->submitter()->associate($this->user)->save();
        $requirement->metadata()->create([
            'environment' => 'production',
            'url' => 'https://example.com/requirement',
            'phase' => 'requirement',
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
            'submitted_title' => $requirement->title,
            'submitted_description' => 'Client wording.',
        ]);
    }

    $response = $this->actingAs($this->user)->get(route('organisation.requirements', $organisation));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $scopedRequirement->id)
        ->where('surface', 'requirements')
        ->where('filters.organisation_id', $organisation->id)
    );
});

test('requirements index can filter requirement intake items by project', function () {
    $firstProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Billing Console',
    ]);
    $secondProject = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Retail Dashboard',
    ]);

    $firstRequirement = Task::factory()->create([
        'project_id' => $firstProject->id,
        'title' => 'Billing requirement',
        'status' => 'pending',
    ]);
    $secondRequirement = Task::factory()->create([
        'project_id' => $secondProject->id,
        'title' => 'Retail requirement',
        'status' => 'pending',
    ]);

    foreach ([$firstRequirement, $secondRequirement] as $requirement) {
        $requirement->submitter()->associate($this->user)->save();
        $requirement->metadata()->create([
            'environment' => 'production',
            'url' => 'https://example.com/requirement',
            'phase' => 'requirement',
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
            'submitted_title' => $requirement->title,
            'submitted_description' => 'Client wording.',
        ]);
    }

    $response = $this->actingAs($this->user)
        ->get(route('requirements.index', ['project_id' => $firstProject->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $firstRequirement->id)
        ->where('tasks.data.0.project.id', $firstProject->id)
        ->where('tasks.data.0.project.name', 'Billing Console')
        ->where('surface', 'requirements')
        ->where('filters.project_id', $firstProject->id)
    );
});

test('task detail includes external requirement submitter in collaborator list', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $externalUser = ExternalUser::query()->create([
        'external_id' => 'client-123',
        'name' => 'Client User',
        'email' => 'client@example.com',
        'environment' => 'testing',
        'url' => 'https://example.com',
        'project_id' => $project->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $project->id,
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
        'submitted_title' => 'Requirement item',
        'submitted_description' => 'Client wording.',
    ]);

    $response = $this->actingAs($this->user)->getJson(route('tasks.v2.show', $requirement));

    $response
        ->assertOk()
        ->assertJsonPath('external_collaborators.0.id', 'client-123')
        ->assertJsonPath('external_collaborators.0.name', 'Client User')
        ->assertJsonPath('external_collaborators.0.email', 'client@example.com');
});

test('tasks v2 show includes created and updated timestamps', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Created at task',
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->getJson(route('tasks.v2.show', $task));

    $response->assertOk();
    $response->assertJsonPath('id', $task->id);
    $response->assertJsonStructure(['created_at', 'updated_at']);
    expect($response->json('created_at'))->toBeString()->not->toBeEmpty();
    expect($response->json('updated_at'))->toBeString()->not->toBeEmpty();
});

test('can finalize ready requirement items in a visible batch without promoting parked items', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $externalUser = ExternalUser::query()->create([
        'external_id' => 'client-123',
        'name' => 'Client User',
        'email' => 'client@example.com',
        'environment' => 'production',
        'url' => 'https://example.com',
        'project_id' => $project->id,
    ]);

    $batch = RequirementBatch::query()->create([
        'project_id' => $project->id,
        'external_user_id' => $externalUser->id,
        'title' => 'June client requirements',
    ]);

    $requirements = collect(['Renewal report', 'CSV export'])->map(function (string $title) use ($project, $externalUser, $batch) {
        $requirement = Task::factory()->create([
            'project_id' => $project->id,
            'title' => $title,
            'status' => 'pending',
        ]);
        $requirement->submitter()->associate($externalUser)->save();
        $requirement->metadata()->create([
            'environment' => 'production',
            'url' => 'https://example.com/requirement',
            'phase' => 'requirement',
            'source' => 'embedded_requirement_pack',
            'intake_type' => 'requirement',
            'requirement_batch_id' => $batch->id,
            'requirement_status' => 'ready-to-finalize',
            'submitted_title' => $title,
            'submitted_description' => 'Client wording.',
        ]);

        $thread = new TaskThread([
            'type' => 'external',
            'content' => '<p>Can you clarify scope?</p>',
            'sender_name' => $externalUser->name,
        ]);
        $thread->sender()->associate($externalUser);
        $requirement->threads()->save($thread);

        return $requirement;
    });
    $parkedRequirement = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Budget dependent workflow',
        'status' => 'pending',
    ]);
    $parkedRequirement->submitter()->associate($externalUser)->save();
    $parkedRequirement->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/requirement',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_batch_id' => $batch->id,
        'requirement_status' => 'parked',
        'submitted_title' => 'Budget dependent workflow',
        'submitted_description' => 'Park until budget is approved.',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson(route('requirements.v2.batches.finalize', $batch));

    $response
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('finalized_count', 2)
        ->assertJsonCount(2, 'tasks');

    foreach ($requirements as $requirement) {
        $this->assertDatabaseHas('task_metadata', [
            'task_id' => $requirement->id,
            'phase' => 'task',
            'requirement_batch_id' => $batch->id,
            'finalized_by' => $this->user->id,
        ]);

        expect($requirement->threads()->where('content', '<p>Requirement finalized as task.</p>')->exists())->toBeTrue();
        expect($requirement->threads()->where('content', '<p>Can you clarify scope?</p>')->exists())->toBeTrue();
    }

    $this->assertDatabaseHas('task_metadata', [
        'task_id' => $parkedRequirement->id,
        'phase' => 'requirement',
        'requirement_batch_id' => $batch->id,
        'requirement_status' => 'parked',
    ]);
});

test('tasks v2 can filter tasks by environment', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $stagingTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $productionTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);

    $stagingTask->submitter()->associate($this->user)->save();
    $productionTask->submitter()->associate($this->user)->save();

    $stagingTask->metadata()->create([
        'environment' => 'staging',
        'url' => 'https://example.com/staging',
    ]);
    $productionTask->metadata()->create([
        'environment' => 'production',
        'url' => 'https://example.com/production',
    ]);

    $response = $this->actingAs($this->user)->get(route('tasks.index', [
        'environment' => 'staging',
    ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $stagingTask->id)
        ->where('tasks.data.0.environment', 'staging')
        ->where('filters.environment', 'staging')
    );
});

test('tasks v2 defaults to sorting by updated_at descending', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $olderTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'updated_at' => now()->subHour(),
    ]);
    $newerTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'updated_at' => now(),
    ]);

    $olderTask->submitter()->associate($this->user)->save();
    $newerTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->get(route('tasks.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->where('tasks.data.0.id', $newerTask->id)
        ->where('tasks.data.1.id', $olderTask->id)
        ->where('filters.sort_by', 'updated_at')
    );
});

test('tasks v2 can sort tasks by priority', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $lowTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'low',
    ]);
    $mediumTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $highTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);

    $lowTask->submitter()->associate($this->user)->save();
    $mediumTask->submitter()->associate($this->user)->save();
    $highTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)->get(route('tasks.index', [
        'sort_by' => 'priority',
    ]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->where('tasks.data.0.id', $highTask->id)
        ->where('tasks.data.1.id', $mediumTask->id)
        ->where('tasks.data.2.id', $lowTask->id)
        ->where('filters.sort_by', 'priority')
    );
});

test('index filters tasks by status query', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $pendingTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $completedTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'completed',
    ]);

    $pendingTask->submitter()->associate($this->user)->save();
    $completedTask->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.index', ['status' => ['pending']]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Tasks/Index')
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $pendingTask->id)
    );
});

test('create route redirects to tasks index', function () {
    $response = $this->actingAs($this->user)
        ->get(route('tasks.create'));

    $response->assertRedirect(route('tasks.index'));
});

test('store v2 creates new task and returns json', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $taskData = [
        'title' => 'JSON Task',
        'description' => '<p>Rich description</p>',
        'project_id' => $project->id,
        'priority' => 'medium',
    ];

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), $taskData);

    $response->assertCreated();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('data.title', 'JSON Task');
    $response->assertJsonPath('data.description', '<p>Rich description</p>');
    $response->assertJsonPath('data.priority', 'medium');

    $this->assertDatabaseHas('tasks', [
        'title' => 'JSON Task',
        'description' => '<p>Rich description</p>',
        'project_id' => $project->id,
        'priority' => 'medium',
    ]);
});

test('store v2 can create portal requirement items', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Portal requirement',
            'description' => '<p>Review this request.</p>',
            'project_id' => $project->id,
            'priority' => 'medium',
            'phase' => 'requirement',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'Portal requirement')
        ->assertJsonPath('data.phase', 'requirement')
        ->assertJsonPath('data.requirement_status', RequirementStatus::Submitted->value);

    $task = Task::query()->where('title', 'Portal requirement')->firstOrFail();

    $this->assertDatabaseHas('task_metadata', [
        'task_id' => $task->id,
        'phase' => 'requirement',
        'requirement_status' => RequirementStatus::Submitted->value,
        'submitted_title' => 'Portal requirement',
        'submitted_description' => '<p>Review this request.</p>',
    ]);
});

test('store v2 syncs grouped collaborators and returns them in the response', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://client-app.test/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://client-app.test',
            'environment' => 'production',
            'users' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ], 200),
    ]);

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token',
    ]);
    $project->environments()->create([
        'environment' => 'production',
        'url' => 'https://client-app.test',
    ]);

    $internalCollaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $internalCollaborator->id,
        'user_email' => $internalCollaborator->email,
        'user_name' => $internalCollaborator->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Task with collaborators',
            'project_id' => $project->id,
            'environment' => 'production',
            'internal_collaborator_ids' => [$internalCollaborator->id],
            'external_collaborators' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ]);

    $response->assertCreated();

    $task = Task::where('title', 'Task with collaborators')->firstOrFail();
    expect($task->metadata?->environment)->toBe('production');
    $externalUser = ExternalUser::where('project_id', $project->id)
        ->where('external_id', 'client-7')
        ->first();

    expect($externalUser)->not->toBeNull();
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'user_id' => $internalCollaborator->id,
        'kind' => 'internal',
    ]);
    $this->assertDatabaseHas('task_collaborators', [
        'task_id' => $task->id,
        'external_user_id' => $externalUser->id,
        'kind' => 'external',
    ]);

    $response->assertJsonPath('data.internal_collaborators.0.id', $internalCollaborator->id);
    $response->assertJsonPath('data.external_collaborators.0.id', 'client-7');
});

test('store v2 persists temp attachments and rewrites editor temp urls', function () {
    \Illuminate\Support\Facades\Storage::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $tempIdentifier = 'task-create-temp';
    $tempPath = "temp_attachments/{$tempIdentifier}/task-screenshot.png";

    \Illuminate\Support\Facades\Storage::put($tempPath, 'image-bytes');
    \Illuminate\Support\Facades\Storage::put(
        "{$tempPath}.meta",
        json_encode(['original_filename' => 'Task Screenshot.png'], JSON_THROW_ON_ERROR),
    );

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Task with inline upload',
        'description' => "<p><img src=\"/attachments/temp/{$tempIdentifier}/task-screenshot.png\"></p>",
        'project_id' => $project->id,
        'priority' => 'medium',
        'temp_identifier' => $tempIdentifier,
    ]);

    $response->assertCreated();

    $task = Task::where('title', 'Task with inline upload')->firstOrFail();
    $attachment = $task->attachments()->first();

    expect($attachment)->not->toBeNull();
    expect($attachment->original_filename)->toBe('Task Screenshot.png');

    $task->refresh();
    $downloadUrl = route('attachments.download', $attachment, false);

    expect($task->description)->toContain($downloadUrl);
    \Illuminate\Support\Facades\Storage::assertExists($attachment->path);
    \Illuminate\Support\Facades\Storage::assertMissing($tempPath);
    \Illuminate\Support\Facades\Storage::assertMissing("{$tempPath}.meta");
});

test('store v2 rejects inaccessible project ids', function () {
    $otherUsersProject = Project::factory()->create();

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Blocked task',
        'project_id' => $otherUsersProject->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('project_id');
});

test('store v2 requires an environment before syncing external collaborators', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token',
    ]);

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Needs environment',
        'project_id' => $project->id,
        'external_collaborators' => [
            [
                'id' => 'client-7',
                'name' => 'Client User',
                'email' => 'client@example.com',
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('environment');
});

test('internal collaborator can view task details without broader project visibility', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Collaborator visible task',
    ]);
    $task->submitter()->associate($owner)->save();
    $task->internalCollaborators()->attach($this->user->id);

    $response = $this->actingAs($this->user)->getJson(route('tasks.v2.show', $task));

    $response
        ->assertOk()
        ->assertJsonPath('id', $task->id)
        ->assertJsonPath('internal_collaborators.0.id', $this->user->id);
});

test('collaborator candidate endpoint requires an environment before external lookup', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $registeredUser = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $registeredUser->id,
        'user_email' => $registeredUser->email,
        'user_name' => $registeredUser->name,
        'registration_status' => 'registered',
    ]);

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => null,
        'user_email' => 'pending@example.com',
        'user_name' => 'Pending Invite',
        'registration_status' => 'pending',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('tasks.v2.collaborators', $project));

    $response
        ->assertOk()
        ->assertJsonPath('external_available', false)
        ->assertJsonPath('external_error', 'Select an environment before tagging external collaborators.')
        ->assertJsonPath('external_label', "{$project->name} users")
        ->assertJsonCount(2, 'internal');
    $response->assertJsonFragment(['id' => $this->user->id]);
    $response->assertJsonFragment(['id' => $registeredUser->id]);
});

test('collaborator candidate endpoint uses the selected environment registration for external lookup', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://staging-client.test/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://staging-client.test',
            'environment' => 'staging',
            'users' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ], 200),
    ]);

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token',
    ]);
    $project->environments()->create([
        'environment' => 'staging',
        'url' => 'https://staging-client.test',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('tasks.v2.collaborators', [
            'project' => $project,
            'environment' => 'staging',
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('external_available', true)
        ->assertJsonPath('external.0.id', 'client-7');
});

test('edit route redirects to v2 task view', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.edit', $task));

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));
});

test('update v2 updates an owned task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Original Title',
        'status' => 'pending',
        'priority' => 'low',
    ]);
    $task->submitter()->associate($this->user)->save();

    $updateData = [
        'title' => 'Updated Title',
        'description' => '<p>Updated description</p>',
        'status' => 'in-progress',
        'priority' => 'high',
    ];

    $response = $this->actingAs($this->user)
        ->putJson(route('tasks.v2.update', $task), $updateData);

    $response
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('task.title', 'Updated Title')
        ->assertJsonPath('task.status', 'in-progress')
        ->assertJsonPath('task.priority', 'high');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'Updated Title',
        'description' => '<p>Updated description</p>',
        'status' => 'in-progress',
        'priority' => 'high',
    ]);
});

test('update v2 preserves requirement metadata when no environment is selected', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $requirement = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Original requirement',
        'description' => '<p>Original requirement details.</p>',
        'status' => 'pending',
        'priority' => 'medium',
    ]);
    $requirement->submitter()->associate($this->user)->save();
    $requirement->metadata()->create([
        'url' => 'https://shift.test',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_status' => RequirementStatus::Submitted->value,
        'submitted_title' => 'Original requirement',
        'submitted_description' => '<p>Original requirement details.</p>',
    ]);

    $this->actingAs($this->user)
        ->putJson(route('tasks.v2.update', $requirement), [
            'title' => 'Updated requirement',
            'description' => '<p>Updated requirement details.</p>',
            'status' => 'pending',
            'priority' => 'high',
            'requirement_status' => RequirementStatus::InReview->value,
        ])
        ->assertOk()
        ->assertJsonPath('task.phase', 'requirement')
        ->assertJsonPath('task.requirement_status', RequirementStatus::InReview->value);

    $this->assertDatabaseHas('task_metadata', [
        'task_id' => $requirement->id,
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_status' => RequirementStatus::InReview->value,
        'submitted_title' => 'Original requirement',
        'submitted_description' => '<p>Original requirement details.</p>',
    ]);
});

test('destroy v2 deletes task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

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

    $response = $this->actingAs($this->user)
        ->deleteJson(route('tasks.v2.destroy', $task));

    $response
        ->assertOk()
        ->assertJsonPath('ok', true);

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

test('update v2 blocks an attached collaborator from updating status', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    $task->submitter()->associate($owner)->save();
    $task->internalCollaborators()->attach($this->user->id);

    $response = $this->actingAs($this->user)
        ->putJson(route('tasks.v2.update', $task), [
            'status' => 'awaiting-feedback',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'pending',
    ]);
});

test('task creation notifies the submitter and explicitly tagged collaborators only', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $taggedCollaborator = User::factory()->create();
    $untaggedProjectUser = User::factory()->create();

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $taggedCollaborator->id,
        'user_email' => $taggedCollaborator->email,
        'user_name' => $taggedCollaborator->name,
        'registration_status' => 'registered',
    ]);

    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $untaggedProjectUser->id,
        'user_email' => $untaggedProjectUser->email,
        'user_name' => $untaggedProjectUser->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Notification Policy Task',
            'project_id' => $project->id,
            'internal_collaborator_ids' => [$taggedCollaborator->id],
        ]);

    $response->assertCreated();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $this->user,
        \App\Notifications\TaskCreationNotification::class,
    );

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $taggedCollaborator,
        \App\Notifications\TaskCreationNotification::class,
    );

    \Illuminate\Support\Facades\Notification::assertNotSentTo(
        $untaggedProjectUser,
        \App\Notifications\TaskCreationNotification::class,
    );
});

test('task creation notifies tagged internal collaborators', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $collaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $collaborator->id,
        'user_email' => $collaborator->email,
        'user_name' => $collaborator->name,
        'registration_status' => 'registered',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Tagged internal collaborator task',
            'project_id' => $project->id,
            'internal_collaborator_ids' => [$collaborator->id],
        ]);

    $response->assertCreated();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $collaborator,
        \App\Notifications\TaskCreationNotification::class,
    );
});

test('store v2 dispatches create notification jobs for tagged external collaborators', function () {
    \Illuminate\Support\Facades\Http::fake([
        'https://client-app.test/shift/api/collaborators/external*' => \Illuminate\Support\Facades\Http::response([
            'url' => 'https://client-app.test',
            'environment' => 'local',
            'users' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ], 200),
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'project-token-external-create',
    ]);
    $project->environments()->create([
        'environment' => 'local',
        'url' => 'https://client-app.test',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('tasks.v2.store'), [
            'title' => 'Tagged external collaborator task',
            'project_id' => $project->id,
            'environment' => 'local',
            'external_collaborators' => [
                [
                    'id' => 'client-7',
                    'name' => 'Client User',
                    'email' => 'client@example.com',
                ],
            ],
        ]);

    $response->assertCreated();

    $task = Task::query()->where('title', 'Tagged external collaborator task')->firstOrFail();
    $externalUser = ExternalUser::query()->where('email', 'client@example.com')->firstOrFail();

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\NotifyExternalUser::class, function ($job) use ($task, $externalUser) {
        return $job->taskId === $task->id
            && $job->externalUserId === $externalUser->id;
    });
});

test('edit route redirects for internal task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create external users with different environments
    $externalUser1 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'name' => 'Production User',
    ]);
    $externalUser2 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'staging',
        'name' => 'Staging User',
    ]);

    // Create a task submitted by an internal user
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.edit', $task));

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));
});

test('edit route redirects for external submitted task', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    // Create external users with different environments
    $externalUser1 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'name' => 'Production User',
    ]);
    $externalUser2 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'staging',
        'name' => 'Staging User',
    ]);
    $externalUser3 = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'environment' => 'production',
        'name' => 'Another Production User',
    ]);

    // Create a task submitted by an external user from production
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($externalUser1)->save();

    $response = $this->actingAs($this->user)
        ->get(route('tasks.edit', $task));

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));
});

test('attached internal collaborator cannot update collaborators through the v2 collaborator endpoint', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $manager = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $manager->id,
        'user_email' => $manager->email,
        'user_name' => $manager->name,
        'registration_status' => 'registered',
    ]);

    $newCollaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $newCollaborator->id,
        'user_email' => $newCollaborator->email,
        'user_name' => $newCollaborator->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Collaborator managed task',
    ]);
    $task->submitter()->associate($owner)->save();
    $task->internalCollaborators()->attach($manager->id);

    $response = $this->actingAs($manager)
        ->patchJson(route('tasks.v2.collaborators.update', $task), [
            'internal_collaborator_ids' => [$manager->id, $newCollaborator->id],
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('task_collaborators', [
        'task_id' => $task->id,
        'user_id' => $newCollaborator->id,
    ]);
});

test('visible project member without task attachment cannot update collaborators', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create([
        'author_id' => $owner->id,
    ]);

    $viewer = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $viewer->id,
        'user_email' => $viewer->email,
        'user_name' => $viewer->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($owner)->save();

    $response = $this->actingAs($viewer)
        ->patchJson(route('tasks.v2.collaborators.update', $task), [
            'internal_collaborator_ids' => [$viewer->id],
        ]);

    $response->assertForbidden();
});

test('adding an internal collaborator to an existing task sends collaborator added notification', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $collaborator = User::factory()->create();
    \App\Models\ProjectUser::factory()->create([
        'project_id' => $project->id,
        'user_id' => $collaborator->id,
        'user_email' => $collaborator->email,
        'user_name' => $collaborator->name,
        'registration_status' => 'registered',
    ]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Existing task',
    ]);
    $task->submitter()->associate($this->user)->save();

    $response = $this->actingAs($this->user)
        ->patchJson(route('tasks.v2.collaborators.update', $task), [
            'internal_collaborator_ids' => [$collaborator->id],
        ]);

    $response->assertOk();

    \Illuminate\Support\Facades\Notification::assertSentTo(
        $collaborator,
        \App\Notifications\TaskCollaboratorAddedNotification::class,
    );
});

test('store v2 sanitizes dangerous description html without breaking inline uploads', function () {
    \Illuminate\Support\Facades\Storage::fake();

    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $tempIdentifier = 'task-sanitize-temp';
    $tempPath = "temp_attachments/{$tempIdentifier}/task-screenshot.png";

    \Illuminate\Support\Facades\Storage::put($tempPath, 'image-bytes');
    \Illuminate\Support\Facades\Storage::put(
        "{$tempPath}.meta",
        json_encode(['original_filename' => 'Task Screenshot.png'], JSON_THROW_ON_ERROR),
    );

    $response = $this->actingAs($this->user)->postJson(route('tasks.v2.store'), [
        'title' => 'Task with sanitized inline upload',
        'description' => implode('', [
            "<p><img src=\"/attachments/temp/{$tempIdentifier}/task-screenshot.png\" class=\"editor-tile extra\" onerror=\"alert(1)\"></p>",
            '<script>alert(1)</script>',
            '<blockquote class="shift-reply extra" data-reply-to="42"><p>Reply</p></blockquote>',
        ]),
        'project_id' => $project->id,
        'priority' => 'medium',
        'temp_identifier' => $tempIdentifier,
    ]);

    $response->assertCreated();

    $task = Task::where('title', 'Task with sanitized inline upload')->firstOrFail();
    $attachment = $task->attachments()->first();
    $task->refresh();

    expect($attachment)->not->toBeNull();
    expect($task->description)->toContain(route('attachments.download', $attachment, false));
    expect($task->description)->toContain('data-reply-to="42"');
    expect($task->description)->toContain('class="editor-tile"');
    expect($task->description)->not->toContain('<script');
    expect($task->description)->not->toContain('onerror');
});
