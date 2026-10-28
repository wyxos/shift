<?php

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->uploadRoot = sys_get_temp_dir().'/shift-upload-security-'.Str::uuid();
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => $this->uploadRoot]));
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('integration-a')->plainTextToken;
    $this->project = Project::factory()->create(['author_id' => $this->user->id, 'token' => Str::random(60)]);
    ProjectEnvironment::create([
        'project_id' => $this->project->id,
        'environment' => 'production',
        'url' => 'https://consumer.example.test',
    ]);
    $this->context = [
        'project' => $this->project->token,
        'user' => ['id' => 'actor-a', 'environment' => 'production', 'url' => 'https://consumer.example.test'],
    ];
    $this->identifier = (string) Str::uuid();
    $this->apiRequest = function (string $method, string $uri, array $data = [], ?string $token = null) {
        app('auth')->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($method, $uri, $data);
    };
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->uploadRoot);
});

test('embedded temporary uploads isolate actors projects environments and integration tokens', function (string $boundary) {
    $upload = ($this->apiRequest)('POST', '/api/attachments/upload', $this->context + [
        'temp_identifier' => $this->identifier,
        'file' => UploadedFile::fake()->createWithContent('evidence.txt', 'private evidence'),
    ])->assertOk();
    $path = $upload->json('path');
    $otherContext = $this->context;
    $otherToken = null;
    if ($boundary === 'actor') {
        $otherContext['user']['id'] = 'actor-b';
    } elseif ($boundary === 'project') {
        $otherContext['project'] = Project::factory()->create(['author_id' => $this->user->id, 'token' => Str::random(60)])->token;
    } elseif ($boundary === 'environment') {
        $otherContext['user']['environment'] = 'staging';
    } elseif ($boundary === 'url') {
        $otherContext['user']['url'] = 'https://wrong.example.test';
    } else {
        $otherToken = $this->user->createToken('integration-b')->plainTextToken;
    }

    ($this->apiRequest)('GET', '/api/attachments/list-temp', $otherContext + ['temp_identifier' => $this->identifier], $otherToken)
        ->assertOk()->assertJsonPath('files', []);
    ($this->apiRequest)('GET', '/api/attachments/temp/'.$this->identifier.'/'.basename($path), $otherContext, $otherToken)
        ->assertNotFound();
    ($this->apiRequest)('DELETE', '/api/attachments/remove-temp', $otherContext + ['path' => $path], $otherToken)
        ->assertNotFound();
    Storage::assertExists($path);
    ($this->apiRequest)('GET', '/api/attachments/list-temp', $this->context + ['temp_identifier' => $this->identifier])
        ->assertOk()->assertJsonPath('files.0.path', $path);
})->with(['actor', 'project', 'environment', 'url', 'integration']);

test('chunk sessions reject another external actor throughout resumable upload', function () {
    $init = ($this->apiRequest)('POST', '/api/attachments/upload-init', $this->context + [
        'temp_identifier' => $this->identifier, 'filename' => 'file.txt', 'size' => 5,
    ])->assertOk();
    $uploadId = $init->json('upload_id');
    $other = $this->context;
    $other['user']['id'] = 'actor-b';
    ($this->apiRequest)('GET', '/api/attachments/upload-status', $other + ['upload_id' => $uploadId])->assertNotFound();
    ($this->apiRequest)('POST', '/api/attachments/upload-chunk', $other + [
        'upload_id' => $uploadId, 'chunk_index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'hello'),
    ])->assertNotFound();
    ($this->apiRequest)('POST', '/api/attachments/upload-complete', $other + ['upload_id' => $uploadId])->assertNotFound();
    ($this->apiRequest)('POST', '/api/attachments/upload-chunk', $this->context + [
        'upload_id' => $uploadId, 'chunk_index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', 'hello'),
    ])->assertOk();
    ($this->apiRequest)('POST', '/api/attachments/upload-complete', $this->context + ['upload_id' => $uploadId])->assertOk();
});

test('legacy temporary upload ownership cannot be silently claimed', function () {
    $directory = 'temp_attachments/'.$this->identifier;
    Storage::put($directory.'/.owner.meta', json_encode(['user_id' => $this->user->id]));
    Storage::put($directory.'/legacy.txt', 'private');
    ($this->apiRequest)('GET', '/api/attachments/list-temp', ['temp_identifier' => $this->identifier])
        ->assertOk()->assertJsonPath('files', []);
    ($this->apiRequest)('POST', '/api/attachments/upload', [
        'temp_identifier' => $this->identifier, 'file' => UploadedFile::fake()->createWithContent('new.txt', 'new'),
    ])->assertNotFound();
});

test('active temporary documents are downloads with browser isolation headers', function (string $name, string $mime, string $content) {
    $upload = ($this->apiRequest)('POST', '/api/attachments/upload', $this->context + [
        'temp_identifier' => $this->identifier, 'file' => UploadedFile::fake()->createWithContent($name, $content),
    ])->assertOk();
    ($this->apiRequest)('GET', '/api/attachments/temp/'.$this->identifier.'/'.basename($upload->json('path')), $this->context)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Disposition', 'attachment; filename='.basename($upload->json('path')));
})->with([
    ['script.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>document.title="unsafe"</script></svg>'],
    ['script.html', 'text/html', '<script>document.title="unsafe"</script>'],
]);

test('saved SVG files are downloaded while raster images retain inline previews', function (string $filename, string $expectedDisposition, string $expectedMime) {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $attachment = $task->attachments()->create(['original_filename' => $filename, 'path' => 'attachments/'.$filename]);
    Storage::put($attachment->path, 'synthetic');
    $response = $this->actingAs($this->user)->get(route('attachments.download', $attachment));
    $response->assertOk()->assertHeader('Content-Type', $expectedMime)
        ->assertHeader('Content-Disposition', $expectedDisposition.'; filename='.$filename)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    ['script.svg', 'attachment', 'application/octet-stream'],
    ['image.png', 'inline', 'image/png'],
]);

test('task creation cannot promote another actor uploads but preserves the uploader promotion', function () {
    $upload = ($this->apiRequest)('POST', '/api/attachments/upload', $this->context + [
        'temp_identifier' => $this->identifier,
        'file' => UploadedFile::fake()->createWithContent('evidence.txt', 'private evidence'),
    ])->assertOk();
    $path = $upload->json('path');
    $other = $this->context;
    $other['user']['id'] = 'actor-b';
    $otherTask = ($this->apiRequest)('POST', '/api/tasks', $other + [
        'title' => 'Another actor task', 'temp_identifier' => $this->identifier,
    ])->assertCreated();
    $this->assertDatabaseMissing('attachments', ['attachable_id' => $otherTask->json('id')]);
    Storage::assertExists($path);
    ($this->apiRequest)('POST', '/api/tasks/'.$otherTask->json('id').'/threads', $other + [
        'type' => 'external', 'content' => '<p>Another actor reply</p>', 'temp_identifier' => $this->identifier,
    ])->assertCreated()->assertJsonCount(0, 'thread.attachments');
    Storage::assertExists($path);

    $ownTask = ($this->apiRequest)('POST', '/api/tasks', $this->context + [
        'title' => 'Uploader task', 'temp_identifier' => $this->identifier,
    ])->assertCreated();
    $this->assertDatabaseHas('attachments', ['attachable_id' => $ownTask->json('id'), 'original_filename' => 'evidence.txt']);
    Storage::assertMissing($path);
});
