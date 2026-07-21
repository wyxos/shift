<?php

use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use App\Services\TaskCollaboratorService;
use App\Services\TaskThreadNotificationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    Queue::fake();

    $this->projectOwner = User::factory()->create();
    $this->projectMember = User::factory()->create();
    $this->internalSender = User::factory()->create();
    $this->internalCollaborator = User::factory()->create();

    $this->project = Project::factory()->withAuthor($this->projectOwner->id)->create();

    ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->projectMember->id,
        'user_email' => $this->projectMember->email,
        'user_name' => $this->projectMember->name,
        'registration_status' => 'registered',
    ]);

    $this->externalSender = ExternalUser::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'external-sender',
        'environment' => 'testing',
        'url' => 'https://sender.example.com',
        'email' => 'sender@example.com',
    ]);
    $this->externalCollaborator = ExternalUser::factory()->create([
        'project_id' => $this->project->id,
        'external_id' => 'external-collaborator',
        'environment' => 'testing',
        'url' => 'https://collaborator.example.com',
        'email' => 'collaborator@example.com',
    ]);

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Collaborator notification task',
    ]);
    $this->task->submitter()->associate($this->externalSender)->save();
    $this->task->internalCollaborators()->attach([
        $this->internalSender->id,
        $this->internalCollaborator->id,
    ]);
    $this->task->externalCollaborators()->attach([
        $this->externalSender->id,
        $this->externalCollaborator->id,
    ]);
});

test('external replies from an external collaborator notify only the other task collaborators', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => 'External collaborator reply',
        'sender_name' => $this->externalSender->name,
    ]);
    $thread->sender()->associate($this->externalSender);
    $thread->save();

    app(TaskThreadNotificationService::class)->send($this->task, $thread);

    Notification::assertSentTo(
        [$this->internalSender, $this->internalCollaborator],
        TaskThreadUpdated::class,
    );
    Notification::assertNotSentTo(
        [$this->projectOwner, $this->projectMember],
        TaskThreadUpdated::class,
    );
    Queue::assertPushed(SendTaskThreadNotification::class, 1);

    expect(app(TaskCollaboratorService::class)
        ->externalReplyAudience($this->task, $this->externalSender->id)
        ->pluck('id')
        ->all())
        ->toBe([$this->externalCollaborator->id]);
});

test('internal replies notify only internal task collaborators except the author', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'internal',
        'content' => 'Internal collaborator reply',
        'sender_name' => $this->internalSender->name,
    ]);
    $thread->sender()->associate($this->internalSender);
    $thread->save();

    app(TaskThreadNotificationService::class)->send($this->task, $thread);

    Notification::assertSentTo($this->internalCollaborator, TaskThreadUpdated::class);
    Notification::assertNotSentTo(
        [$this->internalSender, $this->projectOwner, $this->projectMember],
        TaskThreadUpdated::class,
    );
    Queue::assertNothingPushed();
});

test('external replies from an internal collaborator notify internal and external collaborators except the author', function () {
    $thread = new TaskThread([
        'task_id' => $this->task->id,
        'type' => 'external',
        'content' => 'Internal collaborator external reply',
        'sender_name' => $this->internalSender->name,
    ]);
    $thread->sender()->associate($this->internalSender);
    $thread->save();

    app(TaskThreadNotificationService::class)->send($this->task, $thread);

    Notification::assertSentTo($this->internalCollaborator, TaskThreadUpdated::class);
    Notification::assertNotSentTo(
        [$this->internalSender, $this->projectOwner, $this->projectMember],
        TaskThreadUpdated::class,
    );
    Queue::assertPushed(SendTaskThreadNotification::class, 2);
});
