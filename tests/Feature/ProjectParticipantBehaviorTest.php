<?php

use App\Enums\ExternalIdentityRole;
use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\ExternalIdentityService;
use App\Services\ExternalTaskAccess;
use App\Services\ProjectParticipantService;
use App\Services\TaskThreadNotificationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->create(['author_id' => $this->owner->id, 'token' => 'participant-project']);
    $this->token = $this->owner->createToken('participant-test')->plainTextToken;
    foreach (['production', 'sandbox', 'testing', 'preview'] as $environment) {
        $this->project->environments()->create([
            'environment' => $environment,
            'url' => 'https://'.$environment.'.sample-app.test',
            'callback_trusted_at' => now(),
        ]);
    }

    $this->identity = function (string $environment, string $id = '42', string $role = 'user'): ExternalIdentity {
        return app(ExternalIdentityService::class)->upsert($this->project, [
            'external_id' => $id,
            'name' => 'Example User',
            'email' => 'member@sample-app.test',
            'environment' => $environment,
            'url' => 'https://'.$environment.'.sample-app.test',
            'role' => $role,
        ]);
    };
    $this->payload = fn (ExternalIdentity $identity): array => [
        'project' => $this->project->token,
        'user' => [
            'id' => $identity->external_id,
            'name' => $identity->name,
            'email' => $identity->email,
            'environment' => $identity->environment,
            'url' => $identity->url,
        ],
    ];
    $this->submittedTask = function (ExternalIdentity $identity): Task {
        $task = Task::factory()->create(['project_id' => $this->project->id]);
        $task->submitter()->associate($identity)->save();

        return $task;
    };
});

test('a linked identity can read and comment without inheriting authorship permissions', function () {
    Queue::fake();
    Notification::fake();
    $production = ($this->identity)('production');
    $sandbox = ($this->identity)('sandbox', '18');
    app(ProjectParticipantService::class)->link($production, $sandbox, $this->owner);
    $task = ($this->submittedTask)($production);
    $task->externalCollaborators()->attach($production->id);
    $originalThread = TaskThread::query()->create([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => 'Production message',
        'sender_name' => $production->name,
        'sender_type' => ExternalIdentity::class,
        'sender_id' => $production->id,
    ]);
    $payload = ($this->payload)($sandbox);
    $this->withHeader('Authorization', 'Bearer '.$this->token);

    $this->getJson('/api/tasks/'.$task->id.'?'.http_build_query($payload))
        ->assertOk()
        ->assertJsonPath('can_edit', false)
        ->assertJsonPath('can_comment', true)
        ->assertJsonPath('can_manage_collaborators', false);
    $response = $this->postJson('/api/tasks/'.$task->id.'/threads', [...$payload, 'type' => 'external', 'content' => 'Sandbox reply']);
    $response->assertCreated();
    $this->assertDatabaseHas('task_threads', ['id' => $response->json('thread.id'), 'sender_id' => $sandbox->id, 'sender_type' => ExternalIdentity::class]);

    $this->putJson('/api/tasks/'.$task->id, [...$payload, 'title' => 'Overwritten title'])->assertForbidden();
    $this->patchJson('/api/tasks/'.$task->id.'/toggle-status', $payload)->assertForbidden();
    $this->patchJson('/api/tasks/'.$task->id.'/toggle-priority', $payload)->assertForbidden();
    $this->patchJson('/api/tasks/'.$task->id.'/collaborators', [...$payload, 'internal_collaborator_ids' => []])->assertForbidden();
    $this->putJson('/api/tasks/'.$task->id.'/threads/'.$originalThread->id, [...$payload, 'type' => 'external', 'content' => 'Overwritten message'])->assertForbidden();
    $this->deleteJson('/api/tasks/'.$task->id.'/threads/'.$originalThread->id, $payload)->assertForbidden();
    $this->deleteJson('/api/tasks/'.$task->id, $payload)->assertForbidden();

    expect($task->fresh()->submitter_id)->toBe($production->id)
        ->and($originalThread->fresh()->content)->toBe('Production message')
        ->and($task->externalCollaborators()->pluck('external_identities.id')->all())->toBe([$production->id]);
});

test('participant links preserve account roles and do not grant an owner role to linked accounts', function () {
    $production = ($this->identity)('production', '42', 'owner');
    $sandbox = ($this->identity)('sandbox', '18');
    $stranger = ($this->identity)('testing', '99');
    $task = ($this->submittedTask)($stranger);
    app(ProjectParticipantService::class)->link($production, $sandbox, $this->owner);
    $access = app(ExternalTaskAccess::class);

    expect($production->fresh()->role)->toBe(ExternalIdentityRole::Owner)
        ->and($sandbox->fresh()->role)->toBe(ExternalIdentityRole::User)
        ->and($access->canViewProjectItem($task, $production->fresh()))->toBeTrue()
        ->and($access->canViewProjectItem($task, $sandbox->fresh()))->toBeFalse()
        ->and($access->canSubmitRequirements($sandbox->fresh()))->toBeFalse();
});

test('link merges the entire participant and unlink separates only the selected identity without rewriting history', function () {
    $production = ($this->identity)('production');
    $sandbox = ($this->identity)('sandbox', '18');
    $testing = ($this->identity)('testing', '7');
    $preview = ($this->identity)('preview', '8');
    $participants = app(ProjectParticipantService::class);
    $participants->link($production, $sandbox, $this->owner);
    $participants->link($testing, $preview, $this->owner);
    $task = ($this->submittedTask)($testing);
    $assignedTask = ($this->submittedTask)($testing);
    $assignedTask->externalCollaborators()->attach($preview->id);
    $thread = TaskThread::query()->create([
        'task_id' => $task->id, 'type' => 'external', 'content' => 'Original testing message',
        'sender_name' => $testing->name, 'sender_type' => ExternalIdentity::class, 'sender_id' => $testing->id,
    ]);
    $participants->link($sandbox->fresh(), $testing->fresh(), $this->owner);

    expect(ExternalIdentity::query()->where('project_participant_id', $production->fresh()->project_participant_id)->count())->toBe(4);
    $access = app(ExternalTaskAccess::class);
    expect($access->canViewProjectItem($task, $production->fresh()))->toBeTrue();

    $participants->unlink($production->fresh(), $testing->fresh(), $this->owner);

    expect($testing->fresh()->project_participant_id)->not->toBe($production->fresh()->project_participant_id)
        ->and($preview->fresh()->project_participant_id)->toBe($production->fresh()->project_participant_id)
        ->and($sandbox->fresh()->project_participant_id)->toBe($production->fresh()->project_participant_id)
        ->and($access->canViewProjectItem($task->fresh(), $production->fresh()))->toBeFalse()
        ->and($access->canViewProjectItem($task->fresh(), $testing->fresh()))->toBeTrue()
        ->and($task->fresh()->submitter_id)->toBe($testing->id)
        ->and($thread->fresh()->sender_id)->toBe($testing->id)
        ->and($assignedTask->externalCollaborators()->pluck('external_identities.id')->all())->toBe([$preview->id])
        ->and($access->canViewProjectItem($assignedTask->fresh(), $production->fresh()))->toBeTrue();
});

test('thread notifications keep the selected account destination and source id after participant changes', function () {
    Queue::fake();
    Notification::fake();
    $production = ($this->identity)('production');
    $sandbox = ($this->identity)('sandbox');
    $participants = app(ProjectParticipantService::class);
    $participants->link($production, $sandbox, $this->owner);
    $task = ($this->submittedTask)($production);
    $task->externalCollaborators()->attach($sandbox->id);
    $thread = TaskThread::query()->create([
        'task_id' => $task->id, 'type' => 'external', 'content' => 'Team reply',
        'sender_name' => $this->owner->name, 'sender_type' => User::class, 'sender_id' => $this->owner->id,
    ]);
    app(TaskThreadNotificationService::class)->send($task, $thread);
    $participants->unlink($production->fresh(), $sandbox->fresh(), $this->owner);

    Queue::assertPushed(SendTaskThreadNotification::class, 1);
    Queue::assertPushed(SendTaskThreadNotification::class, function (SendTaskThreadNotification $job) use ($sandbox): bool {
        $data = (new ReflectionProperty($job, 'externalUserData'))->getValue($job);
        $payload = (new ReflectionProperty($job, 'payload'))->getValue($job);

        expect($data)->toMatchArray(['url' => $sandbox->url, 'external_id' => '42'])
            ->and((string) $payload['user_id'])->toBe('42');

        return true;
    });
});

test('guest intake using an authenticated participants email does not link their identities or tickets', function () {
    Queue::fake();
    $production = ($this->identity)('production');
    $task = ($this->submittedTask)($production);
    $this->project->update(['external_widget_enabled' => true, 'external_widget_guest_submissions_enabled' => true]);
    $this->project->environments()->where('environment', 'production')->update(['external_widget_enabled' => true, 'external_widget_guest_submissions_enabled' => true]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/widget/tasks', [
        'project' => $this->project->token, 'kind' => 'issue', 'title' => 'Guest report', 'anonymous' => false,
        'metadata' => ['environment' => 'production', 'url' => $production->url.'/report'],
        'user' => ['name' => 'Example User', 'email' => $production->email, 'authenticated' => false],
    ]);
    $response->assertCreated();
    $guest = Task::query()->findOrFail($response->json('id'))->submitter;

    expect($guest)->toBeInstanceOf(ExternalIdentity::class)
        ->and($guest->external_id)->toStartWith('guest:')
        ->and($guest->project_participant_id)->not->toBe($production->project_participant_id)
        ->and(app(ExternalTaskAccess::class)->canViewProjectItem($task, $guest))->toBeFalse()
        ->and(ProjectParticipant::query()->where('project_id', $this->project->id)->count())->toBe(2);
});
