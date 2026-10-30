<?php

use App\Mcp\Servers\ShiftServer;
use App\Mcp\Tools\ListTaskErrorOccurrencesTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

function sourceMapIngestionFixture(): array
{
    $root = sys_get_temp_dir().'/shift-source-ingestion-'.bin2hex(random_bytes(8));
    mkdir($root.'/input/assets', 0700, true);
    $buildId = str_repeat('a', 64);
    $asset = 'assets/workspace-test.js';
    $code = 'function save(){return task.title.trim()}';
    $map = json_encode([
        'version' => 3, 'file' => basename($asset), 'sources' => ['sdk/ui/src/components/Example.vue'], 'names' => ['save'],
        'sourcesContent' => ['return task.title.trim(); // password=source-secret'], 'mappings' => 'AAAAA',
    ], JSON_THROW_ON_ERROR);
    file_put_contents($root.'/input/'.$asset, $code);
    file_put_contents($root.'/input/'.$asset.'.map', $map);
    file_put_contents($root.'/input/manifest.json', json_encode([
        'schema_version' => 1, 'build_id' => $buildId, 'sdk_revision' => str_repeat('b', 40), 'sdk_dirty' => false,
        'assets' => [$asset => ['sha256' => hash('sha256', $code), 'map' => $asset.'.map', 'map_sha256' => hash('sha256', $map)]],
    ], JSON_THROW_ON_ERROR));
    config(['shift.source_maps.path' => $root.'/store']);
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

    return [$root, $buildId];
}

function sourceMapIngestionPayload(string $buildId): array
{
    return [
        'project' => 'mapped-project', 'source' => 'ui', 'environment' => 'local',
        'error' => ['name' => 'TypeError', 'message' => 'Cannot read title'],
        'stacktrace' => ['frames' => [['file' => '/shift-assets/assets/workspace-test.js', 'line' => 1, 'column' => 1, 'function' => 'a', 'in_app' => true]]],
        'metadata' => ['component' => 'shift_sdk', 'surface' => 'workspace_ui', 'frontend_build_id' => $buildId, 'sdk_version' => '1.3.7'],
    ];
}

it('persists resolved SDK source with raw positions and exposes both through authenticated MCP', function () {
    Notification::fake();
    [$root, $buildId] = sourceMapIngestionFixture();
    $this->artisan('shift:sdk-build:import', ['path' => $root.'/input'])->assertSuccessful();
    $owner = User::factory()->create();
    Project::factory()->withAuthor($owner->id)->create(['token' => 'mapped-project', 'mcp_enabled' => true]);
    $this->withToken($owner->createToken('sdk-diagnostics')->plainTextToken);
    $response = $this->postJson('/api/errors', sourceMapIngestionPayload($buildId))->assertCreated();
    $task = Task::findOrFail($response->json('task.id'));
    $event = $task->errorOccurrences()->firstOrFail();

    expect($task->error_culprit_file)->toBe('sdk/ui/src/components/Example.vue')
        ->and($event->stacktrace['frames'][0]['file'])->toBe('/shift-assets/assets/workspace-test.js')
        ->and($event->stacktrace['resolved_frames'][0]['file'])->toBe('sdk/ui/src/components/Example.vue')
        ->and($event->stacktrace['resolution']['status'])->toBe('resolved')
        ->and($event->metadata['frontend_build_id'])->toBe($buildId)
        ->and(json_encode($event->stacktrace))->not->toContain('source-secret');

    ShiftServer::actingAs($owner)->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $task->id])
        ->assertOk()->assertSee(['resolved_frames', 'Example.vue', 'workspace-test.js', $buildId, 'return task.title.trim();', '[Filtered]'])
        ->assertDontSee('source-secret');
    ShiftServer::actingAs(User::factory()->create())->tool(ListTaskErrorOccurrencesTool::class, ['task_id' => $task->id])
        ->assertHasErrors()->assertDontSee(['Example.vue', 'return task.title.trim();']);
});

it('keeps unresolved browser reports usable when the build is missing or mismatched', function (string $kind, string $status) {
    Notification::fake();
    [$root, $buildId] = sourceMapIngestionFixture();
    $this->artisan('shift:sdk-build:import', ['path' => $root.'/input'])->assertSuccessful();
    $owner = User::factory()->create();
    Project::factory()->withAuthor($owner->id)->create(['token' => 'mapped-project']);
    $this->withToken($owner->createToken('sdk-diagnostics')->plainTextToken);
    $payload = sourceMapIngestionPayload($buildId);
    if ($kind === 'unknown') {
        $payload['metadata']['frontend_build_id'] = str_repeat('c', 64);
    } elseif ($kind === 'invalid') {
        $payload['metadata']['frontend_build_id'] = '../../private';
    } else {
        $payload['stacktrace']['frames'][0]['file'] = '/shift-assets/assets/other.js';
    }
    $id = $this->postJson('/api/errors', $payload)->assertCreated()->json('task.id');
    $event = Task::findOrFail($id)->errorOccurrences()->firstOrFail();
    expect($event->stacktrace['resolution']['status'])->toBe($status)
        ->and($event->stacktrace['frames'][0]['column'])->toBe(1)
        ->and($event->payload['stacktrace']['frames'][0]['file'])->toBe($payload['stacktrace']['frames'][0]['file']);
})->with([['unknown', 'missing_build'], ['invalid', 'invalid_build'], ['wrong asset', 'unmapped']]);

it('discards forged resolved source claims from application reports', function () {
    Notification::fake();
    $owner = User::factory()->create();
    Project::factory()->withAuthor($owner->id)->create(['token' => 'mapped-project']);
    $this->withToken($owner->createToken('sdk-diagnostics')->plainTextToken);
    $payload = sourceMapIngestionPayload(str_repeat('a', 64));
    $payload['source'] = 'backend';
    $payload['metadata'] = [];
    $payload['stacktrace']['resolution'] = ['status' => 'resolved'];
    $payload['stacktrace']['resolved_frames'] = [['file' => 'forged.vue']];
    $id = $this->postJson('/api/errors', $payload)->assertCreated()->json('task.id');
    $trace = Task::findOrFail($id)->errorOccurrences()->firstOrFail()->stacktrace;
    expect($trace)->not->toHaveKeys(['resolution', 'resolved_frames']);
});
