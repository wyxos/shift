<?php

use App\Services\AppErrors\SourceMaps\SdkBuildStore;
use App\Services\AppErrors\SourceMaps\SdkSourceMapResolver;
use App\Services\AppErrors\SourceMaps\SourceMapReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/shift-sourcemaps-'.bin2hex(random_bytes(8));
    mkdir($this->scratch, 0700);
    config(['shift.source_maps.path' => $this->scratch.'/private']);
    Http::preventStrayRequests();
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

function sourceMapFixture(string $root, array $mapChanges = [], array $manifestChanges = []): array
{
    $input = $root.'/input-'.bin2hex(random_bytes(4));
    mkdir($input.'/assets', 0700, true);
    $asset = 'assets/workspace-fixture.js';
    $map = [
        'version' => 3,
        'file' => basename($asset),
        'sources' => ['sdk/ui/src/components/TaskList.vue'],
        'sourcesContent' => ["const draft = null;\nfunction saveTask() {\n  return draft.title;\n}\npassword=source-secret"],
        'names' => ['saveTask'],
        'mappings' => 'AAAAA,UAEAA;AACA',
        ...$mapChanges,
    ];
    file_put_contents($input.'/'.$asset, 'function saveTask(){return null.title}');
    file_put_contents($input.'/'.$asset.'.map', json_encode($map, JSON_THROW_ON_ERROR));
    $manifest = [
        'schema_version' => 1,
        'build_id' => str_repeat('a', 64),
        'sdk_revision' => str_repeat('b', 40),
        'portal_revision' => str_repeat('c', 40),
        'assets' => [$asset => [
            'sha256' => hash_file('sha256', $input.'/'.$asset),
            'map' => $asset.'.map',
            'map_sha256' => hash_file('sha256', $input.'/'.$asset.'.map'),
        ]],
        ...$manifestChanges,
    ];
    file_put_contents($input.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    return [$input, $manifest, $map];
}

function sourceMapPayload(?string $id = null, array $frameChanges = []): array
{
    return [
        'source' => 'ui',
        'metadata' => ['component' => 'shift_sdk', 'frontend_build_id' => $id ?? str_repeat('a', 64)],
        'stacktrace' => [
            'frames' => [['file' => '/shift-assets/assets/workspace-fixture.js', 'line' => 1, 'column' => 11, 'function' => 'minified', ...$frameChanges]],
            'resolved_frames' => [['file' => '/forged/file.php']],
            'resolution' => ['status' => 'forged'],
        ],
    ];
}

it('resolves browser columns to original SDK source and keeps the compiled stack unchanged', function () {
    [$input] = sourceMapFixture($this->scratch);
    app(SdkBuildStore::class)->import($input);
    $payload = sourceMapPayload();
    $result = app(SdkSourceMapResolver::class)->resolve($payload);
    expect($result['stacktrace']['frames'])->toBe($payload['stacktrace']['frames'])
        ->and($result['stacktrace']['resolution'])->toMatchArray([
            'status' => 'resolved', 'build_id' => str_repeat('a', 64), 'matched_frames' => 1, 'total_frames' => 1,
            'sdk_revision' => str_repeat('b', 40),
        ]);
    $frame = $result['stacktrace']['resolved_frames'][0];
    expect($frame)->toMatchArray([
        'file' => 'sdk/ui/src/components/TaskList.vue', 'line' => 3, 'column' => 1,
        'function' => null, 'symbol' => 'saveTask', 'in_app' => true, 'resolved' => true,
        'generated' => $payload['stacktrace']['frames'][0],
    ])->and($frame['generated']['function'])->toBe('minified')
        ->and($frame['context']['lines'][2])->toBe(['number' => 3, 'text' => '  return draft.title;', 'active' => true])
        ->and(json_encode($frame['context']))->not->toContain('source-secret');
    Http::assertNothingSent();
});

it('uses the greatest mapped generated column without crossing lines or unmapped boundaries', function () {
    [$input] = sourceMapFixture($this->scratch, ['mappings' => 'AAAAA,UAEAA,K;AACA']);
    app(SdkBuildStore::class)->import($input);
    $payload = sourceMapPayload();
    $payload['stacktrace']['frames'] = array_map(fn (array $position): array => [...$payload['stacktrace']['frames'][0], ...$position], [
        ['line' => 1, 'column' => 10], ['line' => 1, 'column' => 11],
        ['line' => 1, 'column' => 15], ['line' => 1, 'column' => 16], ['line' => 2, 'column' => 1], ['line' => 3, 'column' => 1],
    ]);
    $result = app(SdkSourceMapResolver::class)->resolve($payload);
    expect($result['stacktrace']['resolution']['status'])->toBe('partial')
        ->and(array_column($result['stacktrace']['resolved_frames'], 'resolved'))->toBe([true, true, true, false, true, false])
        ->and(array_column($result['stacktrace']['resolved_frames'], 'line'))->toBe([1, 3, 3, 1, 4, 3]);
});

it('reports missing and invalid builds without trusting supplied resolution data or fetching URLs', function (?string $id, string $status) {
    $payload = sourceMapPayload($id);
    $payload['stacktrace']['frames'][0]['file'] = 'https://169.254.169.254/latest/meta-data';
    $result = app(SdkSourceMapResolver::class)->resolve($payload);
    expect($result['stacktrace']['resolution']['status'])->toBe($status)
        ->and($result['stacktrace']['resolved_frames'])->toBe([])
        ->and($result['stacktrace']['frames'])->toBe($payload['stacktrace']['frames']);
    Http::assertNothingSent();
})->with([
    'unknown build' => [str_repeat('d', 64), 'missing_build'],
    'path traversal' => ['../../private', 'invalid_build'],
    'URL' => ['https://example.test/build', 'invalid_build'],
]);

it('supports absent embedded source content without reading any original source path', function () {
    $private = $this->scratch.'/not-allowed.php';
    file_put_contents($private, 'arbitrary-file-secret');
    [$input] = sourceMapFixture($this->scratch, ['sources' => [$private], 'sourcesContent' => [null]]);
    app(SdkBuildStore::class)->import($input);
    $result = app(SdkSourceMapResolver::class)->resolve(sourceMapPayload());
    expect($result['stacktrace']['resolution']['status'])->toBe('resolved')
        ->and($result['stacktrace']['resolved_frames'][0]['context'])->toBeNull()
        ->and(json_encode($result))->not->toContain('arbitrary-file-secret');
});

it('normalizes relative source roots for display only', function () {
    [$input] = sourceMapFixture($this->scratch, ['sourceRoot' => 'sdk/ui/src/components', 'sources' => ['../TaskList.vue']]);
    app(SdkBuildStore::class)->import($input);
    $result = app(SdkSourceMapResolver::class)->resolve(sourceMapPayload());
    expect($result['stacktrace']['resolved_frames'][0]['file'])->toBe('sdk/ui/src/TaskList.vue');
});

it('ignores missing columns and unrelated assets while preserving ordered generated locations', function () {
    [$input] = sourceMapFixture($this->scratch);
    app(SdkBuildStore::class)->import($input);
    $payload = sourceMapPayload();
    $payload['stacktrace']['frames'][] = ['file' => '/consumer.js', 'line' => 1, 'column' => 10];
    $payload['stacktrace']['frames'][] = ['file' => '/shift-assets/assets/workspace-fixture.js', 'line' => 1];
    $result = app(SdkSourceMapResolver::class)->resolve($payload);
    expect($result['stacktrace']['resolution']['status'])->toBe('partial')
        ->and($result['stacktrace']['resolution']['matched_frames'])->toBe(1)
        ->and(array_column($result['stacktrace']['resolved_frames'], 'generated'))->toBe($payload['stacktrace']['frames']);
});

it('contains corrupted imported artifacts and mismatched build identity', function (string $mutation) {
    [$input, $manifest] = sourceMapFixture($this->scratch);
    app(SdkBuildStore::class)->import($input);
    $stored = $this->scratch.'/private/'.$manifest['build_id'];
    if ($mutation === 'map') {
        file_put_contents($stored.'/assets/workspace-fixture.js.map', '{}');
    } else {
        $manifest['build_id'] = str_repeat('e', 64);
        file_put_contents($stored.'/manifest.json', json_encode($manifest));
    }
    $result = app(SdkSourceMapResolver::class)->resolve(sourceMapPayload());
    expect($result['stacktrace']['resolution']['status'])->toBe('invalid_artifact')
        ->and($result['stacktrace']['resolved_frames'])->toBe([]);
})->with(['map', 'identity']);

it('reports an empty stack against an available build', function () {
    [$input] = sourceMapFixture($this->scratch);
    app(SdkBuildStore::class)->import($input);
    $payload = sourceMapPayload();
    $payload['stacktrace']['frames'] = [];
    expect(app(SdkSourceMapResolver::class)->resolve($payload)['stacktrace']['resolution']['status'])->toBe('no_frames');
});

it('rejects invalid VLQ and map structures', function (array $changes) {
    $map = ['version' => 3, 'sources' => ['sdk/test.ts'], 'names' => [], 'mappings' => 'AAAA', ...$changes];
    expect(fn () => app(SourceMapReader::class)->validate($map))->toThrow(RuntimeException::class);
})->with([
    'unsupported indexed map' => [['sections' => []]], 'invalid version' => [['version' => 2]],
    'invalid character' => [['mappings' => '!']], 'truncated VLQ' => [['mappings' => 'g']],
    'too many fields' => [['mappings' => 'AAAAAA']], 'invalid field count' => [['mappings' => 'AA']],
    'negative generated column' => [['mappings' => 'D']], 'invalid source index' => [['mappings' => 'ACAA']],
    'invalid symbol index' => [['mappings' => 'AAAAA']], 'missing sources' => [['sources' => []]],
]);

it('preserves modified-source provenance separately from the source revision', function () {
    [$input] = sourceMapFixture($this->scratch, [], ['sdk_dirty' => true, 'portal_dirty' => false]);
    app(SdkBuildStore::class)->import($input);
    $result = app(SdkSourceMapResolver::class)->resolve(sourceMapPayload());
    expect($result['stacktrace']['resolution'])->toMatchArray([
        'sdk_revision' => str_repeat('b', 40), 'sdk_dirty' => true,
        'portal_revision' => str_repeat('c', 40), 'portal_dirty' => false,
    ]);
});
