<?php

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create();
    $this->project = Project::factory()->withAuthor($this->user->id)->create(['token' => 'reaction-project']);
    $this->external = ExternalIdentity::factory()->for($this->project)->create([
        'external_id' => 'reader',
        'environment' => 'testing',
        'url' => 'https://example.test',
    ]);
    $this->task = Task::factory()->for($this->project)->create();
    $this->task->submitter()->associate($this->external)->save();
    $this->thread = $this->task->threads()->create([
        'type' => 'external',
        'content' => '<p>Ready for review</p>',
        'sender_name' => $this->user->name,
        'sender_type' => User::class,
        'sender_id' => $this->user->id,
    ]);
});

test('reactions persist, are idempotent, and distinguish portal and embedded actors', function () {
    $portalRoute = route('task-threads.reaction', [$this->task, $this->thread]);
    $selected = ['emoji' => '👍', 'reacted' => true];
    $this->actingAs($this->user)
        ->putJson($portalRoute, $selected)->assertOk()->assertJsonPath('reactions.0.count', 1);
    $this->putJson($portalRoute, $selected)->assertOk()->assertJsonPath('reactions.0.count', 1);

    $externalRoute = route('api.task-threads.reaction', [$this->task, $this->thread->id]);
    $context = [
        'project' => $this->project->token,
        'user' => [
            'id' => $this->external->external_id,
            'environment' => $this->external->environment,
            'url' => $this->external->url,
        ],
    ];
    $apiList = route('api.task-threads.index', ['task' => $this->task, ...$context]);
    $this->getJson($apiList)->assertOk()->assertJsonPath('external.0.reactions.0.reacted', false);

    $this->putJson($externalRoute, $context + $selected)->assertOk()->assertJsonPath('reactions.0.count', 2);
    $this->getJson($apiList)->assertOk()->assertJsonPath('external.0.reactions.0.reacted', true);

    $this->putJson($portalRoute, ['emoji' => '👍', 'reacted' => false])
        ->assertOk()->assertJsonPath('reactions.0.count', 1)->assertJsonPath('reactions.0.reacted', false);
    $this->getJson(route('task-threads.index', $this->task))
        ->assertOk()->assertJsonPath('threads.0.reactions', [['emoji' => '👍', 'count' => 1, 'reacted' => false]]);

    $this->putJson($portalRoute, ['emoji' => '🤣', 'reacted' => true])
        ->assertOk()
        ->assertJsonFragment(['emoji' => '👍', 'count' => 1, 'reacted' => false])
        ->assertJsonFragment(['emoji' => '🤣', 'count' => 1, 'reacted' => true]);
    $this->assertDatabaseHas('task_thread_reactions', ['emoji_key' => 'thumbs_up']);
    $this->assertDatabaseHas('task_thread_reactions', ['emoji_key' => 'laughing']);
    Notification::assertNothingSent();
});

test('only visible published messages accept supported reactions', function () {
    $teamThread = $this->thread->replicate();
    $teamThread->type = 'internal';
    $teamThread->save();
    $draft = $this->thread->replicate();
    $draft->is_draft = true;
    $draft->save();

    $portalRoute = route('task-threads.reaction', [$this->task, $this->thread]);
    $selected = ['emoji' => '👍', 'reacted' => true];
    $this->actingAs(User::factory()->create())->putJson($portalRoute, $selected)->assertNotFound();
    $this->putJson($portalRoute, [])->assertNotFound();

    $this->actingAs($this->user)
        ->putJson($portalRoute, ['emoji' => '🔥', 'reacted' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('emoji');
    $this->putJson(route('task-threads.reaction', [$this->task, $draft]), $selected)->assertNotFound();

    $context = [
        'project' => $this->project->token,
        'user' => [
            'id' => $this->external->external_id,
            'environment' => $this->external->environment,
            'url' => $this->external->url,
        ],
    ];
    $this->putJson(route('api.task-threads.reaction', [$this->task, $teamThread->id]), $context + $selected)->assertNotFound();
    $this->putJson(route('api.task-threads.reaction', [$this->task, $draft->id]), $context + $selected)->assertNotFound();

    $this->assertDatabaseEmpty('task_thread_reactions');
});
