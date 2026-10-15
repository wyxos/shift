<?php

use App\Enums\TaskCollaboratorKind;
use App\Models\ExternalIdentity;
use App\Models\ExternalInboxNotification;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\Task;
use App\Models\TaskCollaboratorNotification;
use App\Models\User;
use App\Services\ExternalInboxService;
use App\Services\ProjectParticipantService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('inbox-test')->plainTextToken;
    $this->project = Project::factory()->create(['author_id' => $this->user->id, 'token' => 'inbox-test-project']);
    $this->inbox = app(ExternalInboxService::class);
    $this->accounts = [];

    foreach (['production', 'sandbox'] as $environment) {
        $registered = $this->project->environments()->create([
            'environment' => $environment,
            'url' => 'https://'.$environment.'.voidcare.test',
        ]);
        $participant = ProjectParticipant::query()->create(['project_id' => $this->project->id]);
        $this->accounts[$environment] = ExternalIdentity::factory()->create([
            'project_id' => $this->project->id,
            'project_participant_id' => $participant->id,
            'project_environment_id' => $registered->id,
            'external_id' => '42',
            'name' => 'David',
            'email' => 'david@example.test',
            'environment' => $environment,
            'url' => $registered->url,
        ]);
    }

    $this->context = fn (ExternalIdentity $identity): array => [
        'project' => $this->project->token,
        'user' => [
            'id' => $identity->external_id,
            'environment' => $identity->environment,
            'url' => $identity->url,
        ],
    ];

    $this->listInbox = fn (array $context) => $this->withToken($this->token)
        ->getJson('/api/inbox?'.http_build_query($context));

    $this->reply = function (ExternalIdentity $recipient, ?Task $task = null): ExternalInboxNotification {
        $task ??= Task::factory()->for($this->project)->create(['title' => 'Voidcare issue']);
        $task->externalCollaborators()->syncWithoutDetaching([$recipient->id]);
        $thread = $task->threads()->create([
            'type' => 'external',
            'content' => '<p>A reply &amp; next steps.</p>',
            'sender_type' => User::class,
            'sender_id' => $this->user->id,
            'sender_name' => $this->user->name,
            'sender_email' => $this->user->email,
        ]);
        $this->inbox->recordThread($thread, $recipient);

        return ExternalInboxNotification::query()->where('task_thread_id', $thread->id)
            ->where('external_identity_id', $recipient->id)->sole();
    };
});

test('inbox requires authentication and an integration principal with access to the project', function () {
    $context = ($this->context)($this->accounts['production']);
    $this->getJson('/api/inbox?'.http_build_query($context))->assertUnauthorized();

    $other = User::factory()->create();
    $this->withToken($other->createToken('other')->plainTextToken)
        ->getJson('/api/inbox?'.http_build_query($context))->assertNotFound();

    ($this->listInbox)(array_replace($context, ['project' => 'unknown-project']))->assertNotFound();
});

test('unlinked accounts with identical IDs names and emails retain separate inboxes and read states', function () {
    $production = ($this->reply)($this->accounts['production']);
    $sandbox = ($this->reply)($this->accounts['sandbox']);

    foreach (['production' => $production, 'sandbox' => $sandbox] as $environment => $record) {
        ($this->listInbox)(($this->context)($this->accounts[$environment]))
            ->assertOk()->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $record->id)
            ->assertJsonPath('notifications.0.environment', $environment)
            ->assertJsonPath('notifications.0.body', 'A reply & next steps.')
            ->assertJsonPath('unread_count', 1)->assertJsonPath('total', 1);
    }

    $this->withToken($this->token)->patchJson('/api/inbox/'.$sandbox->id.'/read', ($this->context)($this->accounts['production']))
        ->assertNotFound();
    $this->withToken($this->token)->patchJson('/api/inbox/'.$production->id.'/read', ($this->context)($this->accounts['production']))
        ->assertOk()->assertJsonPath('unread_count', 0);

    expect($production->fresh()->read_at)->not->toBeNull()
        ->and($sandbox->fresh()->read_at)->toBeNull();
});

test('explicitly linked accounts share inbox access and read state without changing recipients', function () {
    $production = ($this->reply)($this->accounts['production']);
    $sandbox = ($this->reply)($this->accounts['sandbox']);
    app(ProjectParticipantService::class)->link($this->accounts['production'], $this->accounts['sandbox'], $this->user);

    foreach ($this->accounts as $account) {
        ($this->listInbox)(($this->context)($account))->assertOk()
            ->assertJsonCount(2, 'notifications')->assertJsonPath('unread_count', 2);
    }

    $this->withToken($this->token)->patchJson('/api/inbox/'.$production->id.'/read', ($this->context)($this->accounts['sandbox']))
        ->assertOk()->assertJsonPath('unread_count', 1);
    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonPath('unread_count', 1);

    expect($production->fresh()->external_identity_id)->toBe($this->accounts['production']->id)
        ->and($sandbox->fresh()->external_identity_id)->toBe($this->accounts['sandbox']->id);
});

test('the same reply groups linked recipient rows and marks all visible copies read', function () {
    $production = ($this->reply)($this->accounts['production']);
    $task = $production->task;
    $task->externalCollaborators()->attach($this->accounts['sandbox']->id);
    $this->inbox->recordThread($production->taskThread, $this->accounts['sandbox']);
    $production->update(['read_at' => now()]);

    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonPath('unread_count', 0);
    ($this->listInbox)(($this->context)($this->accounts['sandbox']))->assertJsonPath('unread_count', 1);

    app(ProjectParticipantService::class)->link($this->accounts['production'], $this->accounts['sandbox']);
    ($this->listInbox)(($this->context)($this->accounts['sandbox']))->assertOk()
        ->assertJsonCount(1, 'notifications')->assertJsonPath('total', 1)
        ->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.0.read_at', null);

    $this->withToken($this->token)->patchJson('/api/inbox/'.$production->id.'/read', ($this->context)($this->accounts['sandbox']))
        ->assertOk()->assertJsonPath('unread_count', 0);
    expect(ExternalInboxNotification::query()->whereNull('read_at')->count())->toBe(0);
});

test('unlinking removes shared history access while retaining each source history and completed reads', function () {
    $production = ($this->reply)($this->accounts['production']);
    $sandbox = ($this->reply)($this->accounts['sandbox']);
    $participants = app(ProjectParticipantService::class);
    $participants->link($this->accounts['production'], $this->accounts['sandbox']);
    $this->withToken($this->token)->patchJson('/api/inbox/read-all', ($this->context)($this->accounts['sandbox']))
        ->assertOk()->assertJsonPath('unread_count', 0);
    $participants->unlink($this->accounts['production']->fresh(), $this->accounts['sandbox']->fresh());

    foreach (['production' => $production, 'sandbox' => $sandbox] as $environment => $record) {
        ($this->listInbox)(($this->context)($this->accounts[$environment]))->assertOk()
            ->assertJsonCount(1, 'notifications')->assertJsonPath('notifications.0.id', $record->id)
            ->assertJsonPath('unread_count', 0);
    }

    $this->withToken($this->token)->patchJson('/api/inbox/'.$production->id.'/read', ($this->context)($this->accounts['sandbox']))
        ->assertNotFound();
    expect(ExternalInboxNotification::query()->count())->toBe(2);
});

test('stale identity context cannot read former linked history after unlinking', function () {
    $production = ($this->reply)($this->accounts['production']);
    $sandbox = ($this->reply)($this->accounts['sandbox']);
    $participants = app(ProjectParticipantService::class);
    $participants->link($this->accounts['production'], $this->accounts['sandbox']);
    $staleSandbox = $this->accounts['sandbox']->fresh();
    expect($this->inbox->page($staleSandbox)['total'])->toBe(2);

    $participants->unlink($this->accounts['production']->fresh(), $staleSandbox);

    expect($this->inbox->page($staleSandbox)['total'])->toBe(1)
        ->and($this->inbox->unreadCount($staleSandbox))->toBe(1);
    expect(fn () => $this->inbox->read($staleSandbox, $production->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->inbox->readAll($staleSandbox);
    expect($production->fresh()->read_at)->toBeNull()
        ->and($sandbox->fresh()->read_at)->not->toBeNull();
});

test('project owner role never expands notification recipients', function () {
    $production = ($this->reply)($this->accounts['production']);
    $this->accounts['sandbox']->update(['role' => 'owner']);

    ($this->listInbox)(($this->context)($this->accounts['sandbox']))->assertOk()
        ->assertJsonCount(0, 'notifications')->assertJsonPath('unread_count', 0);
    $this->withToken($this->token)->patchJson('/api/inbox/'.$production->id.'/read', ($this->context)($this->accounts['sandbox']))
        ->assertNotFound();
});

test('unknown identities guests and incorrect environment URLs cannot acquire an inbox by matching email', function () {
    ($this->reply)($this->accounts['production']);
    $context = ($this->context)($this->accounts['production']);
    $context['user'] = [...$context['user'], 'id' => 'not-registered', 'email' => 'david@example.test', 'name' => 'David'];
    $identityCount = ExternalIdentity::query()->count();

    ($this->listInbox)($context)->assertOk()->assertJsonCount(0, 'notifications');
    ($this->listInbox)(['project' => $this->project->token])->assertOk()->assertJsonCount(0, 'notifications');
    $this->withToken($this->token)->patchJson('/api/inbox/read-all', $context)->assertNotFound();
    $context['user']['id'] = '42';
    $context['user']['url'] = 'https://unregistered.voidcare.test';
    ($this->listInbox)($context)->assertOk()->assertJsonCount(0, 'notifications');

    expect(ExternalIdentity::query()->count())->toBe($identityCount);

    $guest = ExternalIdentity::factory()->create([
        'project_id' => $this->project->id, 'external_id' => '99',
        'environment' => 'production', 'url' => $this->accounts['production']->url,
        'email' => 'david@example.test', 'role' => 'guest',
    ]);
    ($this->reply)($guest);
    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonCount(1, 'notifications');
});

test('revoked task access hides notification previews and prevents individual or bulk read mutations', function () {
    $record = ($this->reply)($this->accounts['production']);
    $record->task->externalCollaborators()->detach($this->accounts['production']->id);

    ($this->listInbox)(($this->context)($this->accounts['production']))->assertOk()
        ->assertJsonCount(0, 'notifications')->assertJsonPath('unread_count', 0);
    $this->withToken($this->token)->patchJson('/api/inbox/'.$record->id.'/read', ($this->context)($this->accounts['production']))
        ->assertNotFound();
    $this->withToken($this->token)->patchJson('/api/inbox/read-all', ($this->context)($this->accounts['production']))
        ->assertOk();
    expect($record->fresh()->read_at)->toBeNull();
});

test('linked identities do not inherit one another role permissions when checking notification access', function () {
    $record = ($this->reply)($this->accounts['production']);
    $this->accounts['production']->update(['role' => 'owner']);
    app(ProjectParticipantService::class)->link($this->accounts['production'], $this->accounts['sandbox']);
    $record->task->externalCollaborators()->detach();

    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonCount(1, 'notifications');
    ($this->listInbox)(($this->context)($this->accounts['sandbox']))->assertJsonCount(0, 'notifications');
});

test('draft and team replies are never recorded and later audience changes hide existing snapshots', function () {
    $record = ($this->reply)($this->accounts['production']);
    $thread = $record->taskThread;
    $thread->update(['type' => 'internal']);

    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonCount(0, 'notifications');
    $this->withToken($this->token)->patchJson('/api/inbox/'.$record->id.'/read', ($this->context)($this->accounts['production']))
        ->assertNotFound();

    $internal = $thread->replicate();
    $internal->save();
    $this->inbox->recordThread($internal, $this->accounts['production']);
    $draft = $thread->replicate();
    $draft->fill(['type' => 'external', 'is_draft' => true])->save();
    $this->inbox->recordThread($draft, $this->accounts['production']);
    expect(ExternalInboxNotification::query()->count())->toBe(1);

    $thread->update(['type' => 'external', 'is_draft' => true]);
    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonCount(0, 'notifications');
    $this->withToken($this->token)->patchJson('/api/inbox/read-all', ($this->context)($this->accounts['production']))->assertOk();
    expect($record->fresh()->read_at)->toBeNull();
    $thread->delete();
    expect($record->fresh())->toBeNull();
});

test('recording repeated events is idempotent and preserves read state without delivering emails', function () {
    $record = ($this->reply)($this->accounts['production']);
    $record->update(['read_at' => now()]);
    $this->inbox->recordThread($record->taskThread, $this->accounts['production']);
    $otherProject = Project::factory()->create();
    $otherIdentity = ExternalIdentity::factory()->create(['project_id' => $otherProject->id, 'external_id' => '99']);
    $this->inbox->recordThread($record->taskThread, $otherIdentity);

    expect(ExternalInboxNotification::query()->count())->toBe(1)
        ->and($record->fresh()->read_at)->not->toBeNull();
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('collaborator events record only external recipients and survive later callback cancellation', function () {
    $record = ($this->reply)($this->accounts['production']);
    $pending = TaskCollaboratorNotification::query()->create([
        'task_id' => $record->task_id, 'kind' => TaskCollaboratorKind::External,
        'external_identity_id' => $this->accounts['production']->id,
        'event' => TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED,
        'scheduled_at' => now(),
    ]);
    $this->inbox->recordCollaborator($pending);
    $this->inbox->recordCollaborator($pending);
    $pending->markCancelled();

    ($this->listInbox)(($this->context)($this->accounts['production']))->assertJsonCount(2, 'notifications');
    $cancelled = $pending->replicate();
    $cancelled->save();
    $this->inbox->recordCollaborator($cancelled);
    expect(ExternalInboxNotification::query()->count())->toBe(2);
    $pending->forceFill(['cancelled_at' => null, 'event' => TaskCollaboratorNotification::EVENT_TASK_CREATED])->save();
    $this->inbox->recordCollaborator($pending);
    $this->inbox->recordCollaborator($pending);
    expect(ExternalInboxNotification::query()->count())->toBe(3);

    $pending->forceFill(['event' => TaskCollaboratorNotification::EVENT_WIDGET_FEEDBACK_CREATED])->save();
    $this->inbox->recordCollaborator($pending);
    $pending->forceFill(['event' => TaskCollaboratorNotification::EVENT_COLLABORATOR_ADDED, 'kind' => TaskCollaboratorKind::Internal])->save();
    $this->inbox->recordCollaborator($pending);
    expect(ExternalInboxNotification::query()->count())->toBe(3);
});

test('inbox paginates deterministically with a global unread count and no recipient personal data', function () {
    $first = ($this->reply)($this->accounts['production']);
    $second = ($this->reply)($this->accounts['production']);
    $context = [...($this->context)($this->accounts['production']), 'per_page' => 1];
    $response = ($this->listInbox)($context)->assertOk()->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $second->id)->assertJsonPath('unread_count', 2)
        ->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)->assertJsonPath('per_page', 1);
    expect(array_keys($response->json('notifications.0')))->toBe([
        'id', 'type', 'title', 'body', 'task_id', 'thread_id', 'environment', 'read_at', 'created_at',
    ]);
    ($this->listInbox)([...$context, 'page' => 2])->assertJsonPath('notifications.0.id', $first->id)
        ->assertJsonPath('current_page', 2);
    ($this->listInbox)([...$context, 'per_page' => 101])->assertUnprocessable();
    ($this->listInbox)([...$context, 'page' => 0])->assertUnprocessable();
    $context['user']['id'] = ['invalid'];
    ($this->listInbox)($context)->assertUnprocessable();
});

test('deleting a task cascades inbox history and bulk reads never affect unlinked accounts', function () {
    $production = ($this->reply)($this->accounts['production']);
    $sandbox = ($this->reply)($this->accounts['sandbox']);
    $this->withToken($this->token)->patchJson('/api/inbox/read-all', ($this->context)($this->accounts['production']))
        ->assertOk()->assertJsonPath('unread_count', 0);
    expect($sandbox->fresh()->read_at)->toBeNull();
    $production->task->delete();
    expect($production->fresh())->toBeNull();
});
