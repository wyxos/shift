<?php

use App\Enums\ExternalIdentityRole;
use App\Jobs\SendTaskThreadNotification;
use App\Models\ExternalIdentity;
use App\Models\ExternalNotificationDelivery;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\ExternalIdentityService;
use App\Services\ExternalNotificationService;
use App\Services\ProjectParticipantService;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Support\Facades\Http;

function legacyParticipantModelRestorer(): object
{
    return new class
    {
        use SerializesAndRestoresModelIdentifiers;

        public function restore(ModelIdentifier $identifier): mixed
        {
            return $this->getRestoredPropertyValue($identifier);
        }
    };
}

test('historical serialized model identifiers restore canonical identity and participant records', function () {
    $project = Project::factory()->create();
    $identity = app(ExternalIdentityService::class)->upsert($project, [
        'external_id' => '42', 'name' => 'Example User', 'email' => 'member@sample-app.test',
        'environment' => 'production', 'url' => 'https://sample-app.test',
    ]);
    $restorer = legacyParticipantModelRestorer();
    $legacyIdentity = new ModelIdentifier('App\\Models\\ExternalUser', $identity->id, [], $identity->getConnectionName());
    $legacyParticipant = new ModelIdentifier('App\\Models\\ExternalContact', $identity->project_participant_id, [], $identity->getConnectionName());
    $restoredIdentity = $restorer->restore(unserialize(serialize($legacyIdentity)));
    $restoredParticipant = $restorer->restore(unserialize(serialize($legacyParticipant)));

    expect($restoredIdentity)->toBeInstanceOf(ExternalIdentity::class)
        ->and($restoredIdentity->getTable())->toBe('external_identities')
        ->and($restoredIdentity->id)->toBe($identity->id)
        ->and($restoredParticipant)->toBeInstanceOf(ProjectParticipant::class)
        ->and($restoredParticipant->getTable())->toBe('project_participants')
        ->and($restoredParticipant->id)->toBe($identity->project_participant_id);
});

test('historical serialized external role enum values hydrate as the canonical enum', function () {
    foreach (ExternalIdentityRole::cases() as $role) {
        $legacyCase = 'App\\Enums\\ExternalUserRole:'.$role->name;
        $serialized = 'E:'.strlen($legacyCase).':"'.$legacyCase.'";';

        expect(unserialize($serialized))->toBe($role);
    }
});

test('a serialized queued notification keeps its historical property and destination when accounts are linked', function () {
    Http::fake(['https://production.sample-app.test/shift/api/notifications' => Http::response(['production' => true])]);
    $owner = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $owner->id, 'token' => 'callback-token']);
    $identities = [];
    foreach (['production', 'sandbox'] as $environment) {
        $url = 'https://'.$environment.'.sample-app.test';
        $project->environments()->create(['environment' => $environment, 'url' => $url, 'callback_trusted_at' => now()]);
        $identities[$environment] = app(ExternalIdentityService::class)->upsert($project, [
            'external_id' => '42', 'name' => 'Example User', 'email' => 'member@sample-app.test', 'environment' => $environment, 'url' => $url,
        ]);
    }
    $task = Task::factory()->create(['project_id' => $project->id]);
    $thread = TaskThread::query()->create([
        'task_id' => $task->id, 'type' => 'external', 'content' => 'Existing queued reply',
        'sender_name' => $owner->name, 'sender_type' => User::class, 'sender_id' => $owner->id,
    ]);
    $data = ['external_id' => '42', 'email' => 'member@sample-app.test', 'url' => $identities['production']->url];
    $payload = ['type' => 'task_thread', 'user_id' => '42', 'task_id' => $task->id, 'task_title' => $task->title, 'thread_id' => $thread->id, 'content' => $thread->content];
    $serialized = serialize(new SendTaskThreadNotification($thread->id, $data, $payload));

    expect($serialized)->toContain("\0*\0externalUserData");
    app(ProjectParticipantService::class)->link($identities['production'], $identities['sandbox'], $owner);
    $job = unserialize($serialized);
    $job->handle(app(ExternalNotificationService::class));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://production.sample-app.test/shift/api/notifications'
        && $request['payload']['user_id'] === '42');
    expect(ExternalNotificationDelivery::findForTaskThread($thread->id, 'identity:'.$identities['production']->id)->completed_at)->not->toBeNull();
});

test('the SDK external role API still accepts the external_user payload and returns its original contract', function () {
    $owner = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create(['author_id' => $owner->id, 'organisation_id' => $organisation->id, 'client_id' => null, 'token' => 'legacy-role-token']);
    $project->environments()->create(['environment' => 'production', 'url' => 'https://sample-app.test', 'callback_trusted_at' => now()]);

    $this->withToken($owner->createToken('legacy-role-api')->plainTextToken)->putJson('/api/external-roles', [
        'project' => $project->token,
        'external_user' => ['id' => '42', 'name' => 'Example User', 'email' => 'member@sample-app.test'],
        'role' => 'owner', 'environment' => 'production',
        'metadata' => ['environment' => 'production', 'url' => 'https://sample-app.test'],
    ])->assertOk()->assertJsonPath('user.id', '42')->assertJsonPath('user.role', 'owner');

    $this->assertDatabaseHas('external_identities', ['project_id' => $project->id, 'external_id' => '42', 'role' => 'owner']);
});

test('the SDK task index retains historical submitter type and contact fields on the wire', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $owner->id, 'token' => 'task-index-compatibility']);
    $project->environments()->create(['environment' => 'production', 'url' => 'https://sample-app.test', 'callback_trusted_at' => now()]);
    $identity = app(ExternalIdentityService::class)->upsert($project, [
        'external_id' => '42', 'name' => 'Example User', 'email' => 'member@sample-app.test',
        'environment' => 'production', 'url' => 'https://sample-app.test',
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $task->submitter()->associate($identity)->save();

    $this->withToken($owner->createToken('task-index-api')->plainTextToken)
        ->getJson('/api/tasks?'.http_build_query([
            'project' => $project->token,
            'user' => ['id' => '42', 'environment' => 'production', 'url' => 'https://sample-app.test'],
        ]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $task->id)
        ->assertJsonPath('data.0.submitter_type', 'App\\Models\\ExternalUser')
        ->assertJsonPath('data.0.submitter.external_contact_id', $identity->project_participant_id)
        ->assertJsonMissingPath('data.0.submitter.project_participant_id');
});
