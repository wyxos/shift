<?php

namespace App\Services\AppErrors\SourceMaps;

use App\Services\AppErrors\AppErrorScrubber;
use Throwable;

class SdkSourceMapResolver
{
    public function __construct(
        private readonly SdkBuildStore $store,
        private readonly SourceMapReader $reader,
        private readonly AppErrorScrubber $scrubber,
    ) {}

    public function resolve(array $payload): array
    {
        if (is_array($payload['stacktrace'] ?? null)) {
            unset($payload['stacktrace']['resolved_frames'], $payload['stacktrace']['resolution']);
        }
        if (($payload['source'] ?? null) !== 'ui' || data_get($payload, 'metadata.component') !== 'shift_sdk') {
            return $payload;
        }
        $id = data_get($payload, 'metadata.frontend_build_id');
        $frames = data_get($payload, 'stacktrace.frames', []);
        $frames = is_array($frames) ? array_slice($frames, 0, 100, true) : [];
        $resolution = ['status' => 'missing_build', 'build_id' => is_string($id) ? $id : null, 'matched_frames' => 0, 'total_frames' => count($frames)];
        $resolved = [];
        try {
            if ($id !== null && ! $this->store->validId($id)) {
                $resolution['status'] = 'invalid_build';
            } elseif (is_string($id) && ($root = $this->store->build($id)) !== null) {
                $manifest = $this->store->manifest($root);
                if ($manifest['build_id'] !== $id) {
                    throw new \RuntimeException('Stored SDK build does not match the report.');
                }
                $resolved = $this->frames($frames, $root, $manifest);
                $matched = count(array_filter($resolved, fn (array $frame): bool => $frame['resolved']));
                $resolution = [
                    ...$resolution,
                    'status' => $frames === [] ? 'no_frames' : ($matched === 0 ? 'unmapped' : ($matched === count($frames) ? 'resolved' : 'partial')),
                    'matched_frames' => $matched,
                    'sdk_revision' => $manifest['sdk_revision'] ?? null,
                    'portal_revision' => $manifest['portal_revision'] ?? null,
                    'sdk_dirty' => $manifest['sdk_dirty'] ?? null,
                    'portal_dirty' => $manifest['portal_dirty'] ?? null,
                ];
            }
        } catch (Throwable) {
            $resolution['status'] = 'invalid_artifact';
            $resolved = [];
        }
        $payload['stacktrace'] = [
            ...(is_array($payload['stacktrace'] ?? null) ? $payload['stacktrace'] : []),
            'resolved_frames' => $resolved,
            'resolution' => $resolution,
        ];

        return $payload;
    }

    private function frames(array $frames, string $root, array $manifest): array
    {
        $groups = [];
        $resolved = [];
        foreach ($frames as $index => $frame) {
            if (! is_array($frame)) {
                continue;
            }
            $resolved[$index] = [...$frame, 'resolved' => false, 'generated' => $this->generated($frame)];
            $asset = $this->asset($frame['file'] ?? null);
            if ($asset !== null && isset($manifest['assets'][$asset]) && is_int($frame['line'] ?? null) && $frame['line'] > 0
                && is_int($frame['column'] ?? null) && $frame['column'] > 0) {
                $groups[$asset][$index] = ['line' => $frame['line'], 'column' => $frame['column']];
            }
        }
        foreach ($groups as $asset => $positions) {
            $map = $this->store->map($root, $manifest['assets'][$asset]);
            foreach ($this->reader->positions($map, $positions) as $index => $position) {
                if ($position === null) {
                    continue;
                }
                $source = $this->source($map, $position['source']);
                $resolved[$index] = [
                    'file' => $source,
                    'line' => $position['line'],
                    'column' => $position['column'],
                    'function' => null,
                    'symbol' => $this->scrubber->scrubString($position['name'], 512),
                    'in_app' => str_starts_with($source, 'sdk/') || str_starts_with($source, 'portal/'),
                    'resolved' => true,
                    'context' => $this->context($map['sourcesContent'][$position['source']] ?? null, $position['line']),
                    'generated' => $this->generated($frames[$index]),
                ];
            }
        }
        ksort($resolved);

        return array_values($resolved);
    }

    private function generated(array $frame): array
    {
        return array_intersect_key($frame, array_flip(['file', 'line', 'column', 'function']));
    }

    private function asset(mixed $file): ?string
    {
        if (! is_string($file)) {
            return null;
        }
        $path = parse_url($file, PHP_URL_PATH);
        if (! is_string($path) || ! preg_match('~^/?shift-assets/(assets/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.js)$~D', $path, $match)) {
            return null;
        }

        return $match[1];
    }

    private function source(array $map, int $source): string
    {
        $name = str_replace('\\', '/', trim(($map['sourceRoot'] ?? '').'/'.$map['sources'][$source], '/'));
        $name = preg_replace('~^(?:[A-Za-z][A-Za-z0-9+.-]*://)?(?:[A-Za-z]:)?/+~', '', $name) ?? $name;
        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return $this->scrubber->scrubString(implode('/', $parts), 2048) ?? '[unknown source]';
    }

    private function context(?string $source, int $line): ?array
    {
        if ($source === null) {
            return null;
        }
        $lines = explode("\n", $source);
        if ($line > count($lines)) {
            return null;
        }
        $start = max(1, $line - 3);
        $end = min(count($lines), $line + 3);
        $context = [];
        for ($number = $start; $number <= $end; $number++) {
            $context[] = ['number' => $number, 'text' => $this->scrubber->scrubString($lines[$number - 1], 2000), 'active' => $number === $line];
        }

        return ['start_line' => $start, 'lines' => $context];
    }
}
