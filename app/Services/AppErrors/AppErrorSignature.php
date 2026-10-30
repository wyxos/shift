<?php

namespace App\Services\AppErrors;

use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AppErrorSignature
{
    public function __construct(private readonly AppErrorScrubber $scrubber) {}

    /**
     * @return array{
     *     signature: string,
     *     component: string|null,
     *     source: string,
     *     environment: string|null,
     *     release: string|null,
     *     git_sha: string|null,
     *     exception_class: string|null,
     *     error_name: string|null,
     *     culprit_file: string|null,
     *     culprit_line: int|null,
     *     culprit_function: string|null
     * }
     */
    public function build(Project $project, array $payload): array
    {
        $frames = Arr::get($payload, 'stacktrace.frames', []);
        if (Arr::get($payload, 'metadata.component') === 'shift_sdk' && ($payload['source'] ?? null) === 'ui') {
            $resolved = array_values(array_filter(
                Arr::get($payload, 'stacktrace.resolved_frames', []),
                fn (array $frame): bool => ($frame['resolved'] ?? false) === true,
            ));
            if ($resolved !== []) {
                $frames = $resolved;
            }
        }
        $frame = $this->topFrame($frames);
        $exceptionClass = $this->nullableString(Arr::get($payload, 'exception.class'));
        $errorName = $this->nullableString(Arr::get($payload, 'error.name'));
        $culpritFile = $this->normalizeFile($frame['file'] ?? null);
        $culpritLine = $this->nullableInt($frame['line'] ?? null);
        $culpritFunction = $this->nullableString($frame['function'] ?? null);

        $components = [
            'project_id' => $project->id,
            'environment' => $this->nullableString($payload['environment'] ?? null),
            'source' => (string) $payload['source'],
            'name' => $exceptionClass ?? $errorName ?? 'Error',
            'file' => $culpritFile,
            'line' => $culpritLine,
            'function' => $culpritFunction,
        ];

        $component = Arr::get($payload, 'metadata.component') === 'shift_sdk' ? 'shift_sdk' : null;

        if ($component !== null) {
            $components['component'] = $component;
            $components['surface'] = $this->nullableString(Arr::get($payload, 'metadata.surface'));
            $components['operation'] = $this->nullableString(Arr::get($payload, 'metadata.operation') ?? Arr::get($payload, 'context.operation'));

            if ($payload['source'] === 'ui') {
                $components['column'] = $this->nullableInt($frame['column'] ?? null);
            }

            if ($culpritFile === null) {
                $components['message'] = $this->fallbackMessage($payload);
            }
        }

        return [
            'component' => $component,
            'signature' => hash('sha256', json_encode($components, JSON_THROW_ON_ERROR)),
            'source' => (string) $payload['source'],
            'environment' => $this->nullableString($payload['environment'] ?? null),
            'release' => $this->nullableString($payload['release'] ?? null),
            'git_sha' => $this->nullableString($payload['git_sha'] ?? null),
            'exception_class' => $exceptionClass,
            'error_name' => $errorName,
            'culprit_file' => $culpritFile,
            'culprit_line' => $culpritLine,
            'culprit_function' => $culpritFunction,
        ];
    }

    private function fallbackMessage(array $payload): ?string
    {
        $message = $this->nullableString(Arr::get($payload, 'exception.message')
            ?? Arr::get($payload, 'error.message') ?? Arr::get($payload, 'message'));
        $message = $this->scrubber->scrubString($message, 4000);

        if ($message === null) {
            return null;
        }

        $message = preg_replace('/\b[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\b/i', ':uuid', $message) ?? $message;
        $message = preg_replace('/(?<=\/)\d+(?=\/|[?#\s]|$)/', ':id', $message) ?? $message;
        $message = preg_replace('/\b((?:task|request|user|record|item|invoice)(?:[_ -]?id)?[ #:=]+)\d+\b/i', '$1:id', $message) ?? $message;

        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    private function topFrame(mixed $frames): array
    {
        if (! is_array($frames)) {
            return [];
        }

        foreach ($frames as $frame) {
            if (is_array($frame) && ($frame['in_app'] ?? false) === true) {
                return $frame;
            }
        }

        foreach ($frames as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $file = (string) ($frame['file'] ?? '');

            if ($file !== '' && ! str_contains($file, '/vendor/') && ! str_contains($file, '\\vendor\\') && ! str_contains($file, 'node_modules')) {
                return $frame;
            }
        }

        return is_array($frames[0] ?? null) ? $frames[0] : [];
    }

    private function normalizeFile(mixed $file): ?string
    {
        $file = $this->nullableString($file);

        if ($file === null) {
            return null;
        }

        $normalized = str_replace('\\', '/', $file);
        $basePath = str_replace('\\', '/', base_path());

        if (Str::startsWith($normalized, $basePath.'/')) {
            return Str::after($normalized, $basePath.'/');
        }

        $appOffset = strpos($normalized, '/app/');

        if ($appOffset !== false) {
            return substr($normalized, $appOffset + 1);
        }

        return ltrim($normalized, '/');
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
