<?php

namespace App\Services\AppErrors\SourceMaps;

use Illuminate\Support\Facades\File;
use RuntimeException;

class SdkBuildStore
{
    public function __construct(private readonly SourceMapReader $reader) {}

    public function path(): string
    {
        return (string) config('shift.source_maps.path', storage_path('app/private/shift-sdk-builds'));
    }

    public function import(string $input, ?string $destination = null): array
    {
        $root = realpath($input);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('SDK build artifact directory does not exist.');
        }
        [$manifest, $files] = $this->inspect($root);
        $store = $destination ?? $this->path();
        $this->privateDirectory($store);
        $store = realpath($store);
        if ($store === false) {
            throw new RuntimeException('Unable to establish the private SDK build store.');
        }
        $public = realpath(public_path());
        if ($public !== false && ($store === $public || str_starts_with($store, $public.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('SDK source maps must stay outside the public directory.');
        }
        $target = $store.'/'.$manifest['build_id'];
        if (file_exists($target) || is_link($target)) {
            $this->sameBuild($root, $target, $files);

            return $manifest;
        }
        $temporary = $store.'/.import-'.bin2hex(random_bytes(12));
        $this->privateDirectory($temporary);
        try {
            foreach (array_unique($files) as $relative) {
                $this->privateDirectory(dirname($temporary.'/'.$relative));
                if (! copy($this->file($root, $relative, 32000000), $temporary.'/'.$relative) || ! chmod($temporary.'/'.$relative, 0600)) {
                    throw new RuntimeException('Unable to copy a private SDK build artifact.');
                }
            }
            if (! rename($temporary, $target)) {
                throw new RuntimeException('Unable to activate the immutable SDK build artifact.');
            }
        } finally {
            if (is_dir($temporary)) {
                File::deleteDirectory($temporary);
            }
        }

        return $manifest;
    }

    public function verify(string $id, ?string $destination = null): array
    {
        if (! $this->validId($id)) {
            throw new RuntimeException('Invalid SDK build identifier.');
        }
        $root = $this->buildDirectory($id, $destination ?? $this->path());
        if ($root === null) {
            throw new RuntimeException('SDK build is not registered.');
        }
        [$manifest] = $this->inspect($root);
        if ($manifest['build_id'] !== $id) {
            throw new RuntimeException('Stored SDK build does not match the requested identifier.');
        }

        return [
            'schema_version' => 1,
            'build_id' => $id,
            'manifest_sha256' => hash_file('sha256', $this->file($root, 'manifest.json', 1000000)),
            'assets_count' => count($manifest['assets']),
            'verified' => true,
        ];
    }

    /** @return array{array, array} */
    private function inspect(string $root): array
    {
        $manifest = $this->manifest($root);
        $files = ['manifest.json'];
        $totalBytes = 0;
        foreach ($manifest['assets'] as $asset => $attributes) {
            foreach ([$asset => $attributes['sha256'], $attributes['map'] => $attributes['map_sha256']] as $relative => $digest) {
                $path = $this->file($root, $relative, str_ends_with($relative, '.map') ? 32000000 : 16000000);
                $totalBytes += filesize($path);
                if ($totalBytes > 128000000 || ! hash_equals($digest, hash_file('sha256', $path))) {
                    throw new RuntimeException('SDK build artifact checksum or size does not match.');
                }
                $files[] = $relative;
            }
            $map = $this->json($this->file($root, $attributes['map'], 32000000));
            $this->reader->validate($map);
            if (isset($map['file']) && (! is_string($map['file']) || basename($map['file']) !== basename($asset))) {
                throw new RuntimeException('Source map does not identify its generated asset.');
            }
        }

        return [$manifest, $files];
    }

    public function manifest(string $root): array
    {
        $manifest = $this->json($this->file($root, 'manifest.json', 1000000));
        if (($manifest['schema_version'] ?? null) !== 1 || ! $this->validId($manifest['build_id'] ?? null)
            || ! is_array($manifest['assets'] ?? null) || count($manifest['assets']) < 1 || count($manifest['assets']) > 100) {
            throw new RuntimeException('Invalid SDK build manifest.');
        }
        foreach (['sdk_revision', 'portal_revision'] as $revision) {
            if (isset($manifest[$revision]) && (! is_string($manifest[$revision]) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $manifest[$revision]) !== 1)) {
                throw new RuntimeException('Invalid SDK build revision.');
            }
        }
        foreach (['sdk_dirty', 'portal_dirty'] as $dirty) {
            if (isset($manifest[$dirty]) && ! is_bool($manifest[$dirty])) {
                throw new RuntimeException('Invalid SDK build source state.');
            }
        }
        foreach ($manifest['assets'] as $asset => $attributes) {
            if (! $this->relative($asset) || ! str_ends_with($asset, '.js') || ! is_array($attributes)
                || ! $this->relative($attributes['map'] ?? null) || $attributes['map'] !== $asset.'.map'
                || ! $this->validId($attributes['sha256'] ?? null) || ! $this->validId($attributes['map_sha256'] ?? null)) {
                throw new RuntimeException('Invalid SDK build asset entry.');
            }
        }

        return $manifest;
    }

    public function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public function build(string $id): ?string
    {
        if (! $this->validId($id)) {
            return null;
        }

        return $this->buildDirectory($id, $this->path());
    }

    private function buildDirectory(string $id, string $path): ?string
    {
        $store = realpath($path);
        $public = realpath(public_path());
        if ($store !== false && $public !== false && ($store === $public || str_starts_with($store, $public.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('SDK source maps must stay outside the public directory.');
        }
        $target = $store === false ? false : realpath($store.'/'.$id);
        if ($store === false || $target === false) {
            return null;
        }
        if (is_link($store.'/'.$id) || $target !== $store.'/'.$id || ! is_dir($target)) {
            throw new RuntimeException('SDK build path is not private storage.');
        }

        return $target;
    }

    public function map(string $root, array $asset): array
    {
        $path = $this->file($root, $asset['map'], 32000000);
        if (! hash_equals($asset['map_sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Stored SDK source map checksum does not match.');
        }

        return $this->json($path);
    }

    private function sameBuild(string $input, string $target, array $files): void
    {
        if (is_link($target) || ! is_dir($target)) {
            throw new RuntimeException('Existing SDK build is not an immutable directory.');
        }
        foreach (array_unique($files) as $relative) {
            if (! hash_equals(hash_file('sha256', $this->file($input, $relative, 32000000)), hash_file('sha256', $this->file($target, $relative, 32000000)))) {
                throw new RuntimeException('An SDK build with this identity already has different artifacts.');
            }
        }
    }

    private function relative(mixed $path): bool
    {
        return is_string($path) && strlen($path) <= 255 && preg_match('~^assets/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$~D', $path) === 1;
    }

    private function file(string $root, string $relative, int $limit): string
    {
        if ($relative !== 'manifest.json' && ! $this->relative($relative)) {
            throw new RuntimeException('Invalid SDK artifact path.');
        }
        $candidate = $root.'/'.$relative;
        $path = realpath($candidate);
        if ($path === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || is_link($candidate) || is_link(dirname($candidate)) || filesize($path) > $limit) {
            throw new RuntimeException('Missing, oversized or unsafe SDK artifact.');
        }

        return $path;
    }

    private function json(string $path): array
    {
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('SDK artifact JSON must be an object.');
        }

        return $value;
    }

    private function privateDirectory(string $path): void
    {
        if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true))) {
            throw new RuntimeException('Unable to create a private SDK artifact directory.');
        }
    }
}
