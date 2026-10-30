<?php

use App\Services\AppErrors\SourceMaps\SdkBuildStore;
use App\Services\AppErrors\SourceMaps\SdkSourceMapResolver;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/shift-build-store-'.bin2hex(random_bytes(8));
    mkdir($this->scratch, 0700);
    config(['shift.source_maps.path' => $this->scratch.'/private']);
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

function sdkStoreFixture(string $root): array
{
    $input = $root.'/input';
    mkdir($input.'/assets', 0700, true);
    $map = ['version' => 3, 'file' => 'workspace.js', 'sources' => ['sdk/ui/src/Task.vue'], 'names' => [], 'mappings' => 'AAAA', 'sourcesContent' => ['throw new Error("source map test");']];
    file_put_contents($input.'/assets/workspace.js', 'throw Error("compiled");');
    file_put_contents($input.'/assets/workspace.js.map', json_encode($map));
    $manifest = [
        'schema_version' => 1, 'build_id' => str_repeat('a', 64), 'sdk_revision' => str_repeat('b', 40),
        'assets' => ['assets/workspace.js' => [
            'sha256' => hash_file('sha256', $input.'/assets/workspace.js'),
            'map' => 'assets/workspace.js.map',
            'map_sha256' => hash_file('sha256', $input.'/assets/workspace.js.map'),
        ]],
    ];
    file_put_contents($input.'/manifest.json', json_encode($manifest));

    return [$input, $manifest];
}

it('imports once with private file permissions and accepts the identical artifact again', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $store = app(SdkBuildStore::class);
    expect($store->import($input))->toBe($manifest)
        ->and($store->import($input))->toBe($manifest);
    $target = $this->scratch.'/private/'.$manifest['build_id'];
    expect(json_decode(file_get_contents($target.'/manifest.json'), true))->toBe($manifest)
        ->and(fileperms($target.'/assets/workspace.js.map') & 0777)->toBe(0600)
        ->and(fileperms($target) & 0777)->toBe(0700)
        ->and(glob($this->scratch.'/private/.import-*'))->toBe([]);
});

it('refuses different contents under an existing build identity without replacing the stored map', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $store = app(SdkBuildStore::class);
    $store->import($input);
    $original = file_get_contents($this->scratch.'/private/'.$manifest['build_id'].'/manifest.json');
    $manifest['version'] = 'different-manifest';
    file_put_contents($input.'/manifest.json', json_encode($manifest));
    expect(fn () => $store->import($input))->toThrow(RuntimeException::class, 'different artifacts')
        ->and(file_get_contents($this->scratch.'/private/'.$manifest['build_id'].'/manifest.json'))->toBe($original);
});

it('rejects corrupt generated assets and maps before writing a build', function (string $file) {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    file_put_contents($input.'/'.$file, 'corrupt artifact');
    expect(fn () => app(SdkBuildStore::class)->import($input))->toThrow(RuntimeException::class, 'checksum')
        ->and(is_dir($this->scratch.'/private/'.$manifest['build_id']))->toBeFalse();
})->with(['assets/workspace.js', 'assets/workspace.js.map']);

it('rejects traversal and malformed manifest entries', function (string $mutation) {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    if ($mutation === 'map traversal') {
        $manifest['assets']['assets/workspace.js']['map'] = '../../private.map';
    } elseif ($mutation === 'asset traversal') {
        $manifest['assets']['assets/../outside.js'] = $manifest['assets']['assets/workspace.js'];
    } elseif ($mutation === 'invalid id') {
        $manifest['build_id'] = '../outside';
    } elseif ($mutation === 'bad checksum') {
        $manifest['assets']['assets/workspace.js']['map_sha256'] = 'not-a-checksum';
    } else {
        $manifest['schema_version'] = 99;
    }
    file_put_contents($input.'/manifest.json', json_encode($manifest));
    expect(fn () => app(SdkBuildStore::class)->import($input))->toThrow(RuntimeException::class);
})->with(['map traversal', 'asset traversal', 'invalid id', 'bad checksum', 'unsupported schema']);

it('refuses symlinked maps and stored build directories', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $map = $input.'/assets/workspace.js.map';
    rename($map, $this->scratch.'/external.map');
    symlink($this->scratch.'/external.map', $map);
    expect(fn () => app(SdkBuildStore::class)->import($input))->toThrow(RuntimeException::class, 'unsafe');
    unlink($map);
    rename($this->scratch.'/external.map', $map);
    mkdir($this->scratch.'/private', 0700);
    symlink($input, $this->scratch.'/private/'.$manifest['build_id']);
    expect(fn () => app(SdkBuildStore::class)->import($input))->toThrow(RuntimeException::class, 'immutable');
});

it('refuses storing private source maps inside the web root', function () {
    [$input] = sdkStoreFixture($this->scratch);
    $public = $this->scratch.'/web-root';
    mkdir($public, 0700);
    $original = $this->app->publicPath();
    $this->app->usePublicPath($public);
    try {
        expect(fn () => app(SdkBuildStore::class)->import($input, $public.'/source-maps'))->toThrow(RuntimeException::class, 'outside the public');
    } finally {
        $this->app->usePublicPath($original);
    }
});

it('strips reserved symbolication fields from ordinary app reports', function () {
    $payload = ['source' => 'backend', 'stacktrace' => ['frames' => [['file' => 'app/Task.php']], 'resolved_frames' => [['file' => 'forged']], 'resolution' => ['status' => 'resolved']]];
    $resolved = app(SdkSourceMapResolver::class)->resolve($payload);
    expect($resolved)->toBe(['source' => 'backend', 'stacktrace' => ['frames' => [['file' => 'app/Task.php']]]]);
});

it('provides the import command and contains invalid artifact failures', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $this->artisan('shift:sdk-build:import', ['path' => $input])->expectsOutputToContain('Imported SDK build '.$manifest['build_id'])->assertSuccessful();
    $this->artisan('shift:sdk-build:import', ['path' => $this->scratch.'/missing'])->expectsOutputToContain('SDK build import failed')->assertFailed();
});

it('accepts truthful missing Git provenance and rejects malformed revision metadata', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $manifest['sdk_revision'] = null;
    $manifest['portal_revision'] = null;
    $manifest['sdk_dirty'] = null;
    file_put_contents($input.'/manifest.json', json_encode($manifest));
    expect(app(SdkBuildStore::class)->import($input)['sdk_revision'])->toBeNull();
    $manifest['portal_revision'] = ['untrusted'];
    file_put_contents($input.'/manifest.json', json_encode($manifest));
    expect(fn () => app(SdkBuildStore::class)->import($input))->toThrow(RuntimeException::class, 'revision');
});

it('returns verified machine receipts for import and read-back checks', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $arguments = ['path' => $input, '--json' => true];
    expect(\Illuminate\Support\Facades\Artisan::call('shift:sdk-build:import', $arguments))->toBe(0);
    $imported = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($imported)->toBe([
        'schema_version' => 1, 'build_id' => $manifest['build_id'],
        'manifest_sha256' => hash_file('sha256', $input.'/manifest.json'),
        'assets_count' => 1, 'verified' => true,
    ]);
    expect(\Illuminate\Support\Facades\Artisan::call('shift:sdk-build:verify', ['build_id' => $manifest['build_id'], '--json' => true]))->toBe(0);
    expect(json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($imported);
});

it('verifies alternate private stores and refuses corrupted or missing registered builds', function () {
    [$input, $manifest] = sdkStoreFixture($this->scratch);
    $alternate = $this->scratch.'/alternate';
    $store = app(SdkBuildStore::class);
    $store->import($input, $alternate);
    expect($store->verify($manifest['build_id'], $alternate)['verified'])->toBeTrue();
    expect(fn () => $store->verify($manifest['build_id']))->toThrow(RuntimeException::class, 'not registered');
    file_put_contents($alternate.'/'.$manifest['build_id'].'/assets/workspace.js', 'corrupted');
    expect(fn () => $store->verify($manifest['build_id'], $alternate))->toThrow(RuntimeException::class, 'checksum');
    $this->artisan('shift:sdk-build:verify', ['build_id' => $manifest['build_id'], '--store' => $alternate, '--json' => true])->assertFailed();
    $this->artisan('shift:sdk-build:verify', ['build_id' => '../../private'])->assertFailed();
});
