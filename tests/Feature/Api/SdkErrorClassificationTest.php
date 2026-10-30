<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AppErrors\AppErrorSignature;
use Illuminate\Support\Facades\Notification;

function sdkClassificationPayload(array $metadata = []): array
{
    return [
        'project' => 'sdk-project-token',
        'source' => 'ui',
        'environment' => 'local',
        'error' => ['name' => 'TypeError', 'message' => 'Unable to save task'],
        'stacktrace' => ['frames' => [['file' => 'shift-assets/task.js', 'line' => 17, 'in_app' => true]]],
        'metadata' => $metadata,
    ];
}

it('separates SDK failures from application failures and groups repeated SDK versions', function () {
    Notification::fake();
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create(['token' => 'sdk-project-token']);
    $this->withToken($user->createToken('sdk')->plainTextToken);

    $application = $this->postJson('/api/errors', sdkClassificationPayload())->assertCreated();
    $sdk = $this->postJson('/api/errors', sdkClassificationPayload([
        'component' => 'shift_sdk', 'surface' => 'workspace_ui', 'sdk_version' => '1.3.7',
    ]))->assertCreated()->assertJsonPath('task.component', 'shift_sdk');
    $repeat = $this->postJson('/api/errors', sdkClassificationPayload([
        'component' => 'shift_sdk', 'surface' => 'workspace_ui', 'sdk_version' => '1.3.8',
    ]))->assertCreated()->assertJsonPath('task.occurrences_count', 2);

    expect($sdk->json('task.id'))->not->toBe($application->json('task.id'))
        ->and($repeat->json('task.id'))->toBe($sdk->json('task.id'))
        ->and(Task::query()->count())->toBe(2)
        ->and(Task::findOrFail($sdk->json('task.id'))->error_component)->toBe('shift_sdk');

    $events = Task::findOrFail($sdk->json('task.id'))->errorOccurrences()->orderBy('id')->get();
    expect($events->pluck('metadata.sdk_version')->all())->toBe(['1.3.7', '1.3.8']);

    $signature = app(AppErrorSignature::class)->build($project, sdkClassificationPayload(['component' => 'arbitrary']));
    expect($signature['signature'])->toBe($application->json('task.signature'))
        ->and($signature['component'])->toBeNull();
});

it('filters SDK errors across visible projects without exposing inaccessible projects', function () {
    $user = User::factory()->create();
    $projects = Project::factory()->count(2)->withAuthor($user->id)->create();
    $hiddenProject = Project::factory()->create();
    $sdkIds = [];
    foreach ([...$projects, $hiddenProject] as $project) {
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'pending',
            'error_signature' => hash('sha256', (string) $project->id),
            'error_component' => 'shift_sdk',
        ]);
        $task->submitter()->associate($project->author)->save();
        if ($project->author_id === $user->id) {
            $sdkIds[] = $task->id;
        }
    }
    $application = Task::factory()->create([
        'project_id' => $projects[0]->id,
        'status' => 'pending',
        'error_signature' => str_repeat('e', 64),
    ]);
    $application->submitter()->associate($user)->save();

    $this->actingAs($user)->get('/error-reports?component=shift_sdk')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->has('tasks.data', 2)
        ->where('filters.component', 'shift_sdk')
        ->where('tasks.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($sdkIds)->sort()->values()->all())
        );
    $this->get('/error-reports?component=application')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->has('tasks.data', 1)->where('tasks.data.0.id', $application->id)
        );
    $this->get('/error-reports?component=anything')
        ->assertOk()->assertInertia(fn ($page) => $page
        ->has('tasks.data', 3)->where('filters.component', null)
        );
});

it('keeps SDK operations separate without grouping by release or correlation id', function () {
    $project = Project::factory()->create();
    $signatures = app(AppErrorSignature::class);
    $payload = sdkClassificationPayload(['component' => 'shift_sdk', 'surface' => 'sdk_backend', 'operation' => 'save']);
    $payload['source'] = 'backend';
    $first = $signatures->build($project, $payload);
    $payload['metadata']['operation'] = 'load';
    $second = $signatures->build($project, $payload);
    expect($first['signature'])->not->toBe($second['signature']);
    $payload['metadata']['correlation_id'] = 'new-request';
    $payload['metadata']['sdk_version'] = '1.3.9';
    expect($signatures->build($project, $payload)['signature'])->toBe($second['signature']);
});

it('separates UI failures at different SDK files and lines', function () {
    $project = Project::factory()->create();
    $signatures = app(AppErrorSignature::class);
    $payload = sdkClassificationPayload(['component' => 'shift_sdk', 'surface' => 'workspace_ui', 'operation' => 'runtime']);
    $first = $signatures->build($project, $payload);
    $payload['stacktrace']['frames'][0]['file'] = 'shift-assets/inbox.js';
    $second = $signatures->build($project, $payload);
    expect($second['signature'])->not->toBe($first['signature']);
    $payload['stacktrace']['frames'][0]['line'] = 93;
    expect($signatures->build($project, $payload)['signature'])->not->toBe($second['signature']);
});

it('uses scrubbed SDK messages to distinguish failures without a captured frame', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'sdk-project-token']);
    $this->withToken($user->createToken('sdk')->plainTextToken);
    $payload = sdkClassificationPayload(['component' => 'shift_sdk', 'surface' => 'workspace_ui', 'operation' => 'runtime']);
    $payload['stacktrace'] = ['frames' => []];
    $payload['error']['message'] = 'Unable to save task 42 token=first-secret';
    $first = $this->postJson('/api/errors', $payload)->assertCreated();
    $payload['error']['message'] = 'Unable to load task 42 token=first-secret';
    $second = $this->postJson('/api/errors', $payload)->assertCreated();
    expect($second->json('task.id'))->not->toBe($first->json('task.id'));

    $payload['error']['message'] = 'Unable to save task 97 token=rotated-secret';
    $payload['metadata']['sdk_version'] = 'new-version';
    $payload['metadata']['correlation_id'] = 'new-request';
    $this->postJson('/api/errors', $payload)->assertCreated()
        ->assertJsonPath('task.id', $first->json('task.id'))
        ->assertJsonPath('task.occurrences_count', 2);
});

it('leaves application signatures unchanged when frame-less messages differ', function () {
    $project = Project::factory()->create();
    $signatures = app(AppErrorSignature::class);
    $payload = sdkClassificationPayload();
    $payload['stacktrace'] = ['frames' => []];
    $first = $signatures->build($project, $payload);
    $payload['error']['message'] = 'A different failure';
    expect($signatures->build($project, $payload)['signature'])->toBe($first['signature']);
});

it('separates minified SDK UI errors by column while grouping repeated positions', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create(['token' => 'sdk-project-token']);
    $this->withToken($user->createToken('sdk')->plainTextToken);
    $payload = sdkClassificationPayload(['component' => 'shift_sdk', 'surface' => 'workspace_ui', 'operation' => 'runtime']);
    $payload['stacktrace']['frames'][0] = ['file' => 'shift-assets/main.js', 'line' => 1, 'column' => 318, 'in_app' => true];
    $first = $this->postJson('/api/errors', $payload)->assertCreated();
    $this->postJson('/api/errors', $payload)->assertCreated()
        ->assertJsonPath('task.id', $first->json('task.id'))
        ->assertJsonPath('task.occurrences_count', 2);
    $payload['stacktrace']['frames'][0]['column'] = 749;
    $second = $this->postJson('/api/errors', $payload)->assertCreated();
    expect($second->json('task.id'))->not->toBe($first->json('task.id'));
    expect(Task::findOrFail($second->json('task.id'))->errorOccurrences()->firstOrFail()->stacktrace['frames'][0]['column'])->toBe(749);
    $payload['stacktrace']['frames'][0]['column'] = 'invalid';
    $this->postJson('/api/errors', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors('stacktrace.frames.0.column');
});

it('preserves application and SDK backend hashes when a frame column is supplied', function (array $metadata, string $source) {
    $project = Project::factory()->create();
    $signatures = app(AppErrorSignature::class);
    $payload = sdkClassificationPayload($metadata);
    $payload['source'] = $source;
    $first = $signatures->build($project, $payload);
    $payload['stacktrace']['frames'][0]['column'] = 318;
    expect($signatures->build($project, $payload)['signature'])->toBe($first['signature']);
})->with([
    'application UI' => [[], 'ui'],
    'application backend' => [[], 'backend'],
    'SDK backend' => [['component' => 'shift_sdk', 'surface' => 'sdk_backend'], 'backend'],
]);
