<?php

namespace App\Services\AppErrors;

class AppErrorScrubber
{
    private const FILTERED = '[Filtered]';

    /**
     * @return array<string, mixed>
     */
    public function scrubArray(?array $value): array
    {
        if ($value === null) {
            return [];
        }

        return $this->scrubIterable($value);
    }

    public function scrubString(?string $value, int $limit = 8000): ?string
    {
        if ($value === null) {
            return null;
        }

        $scrubbed = preg_replace_callback(
            '/\b((?:proxy[-_])?authorization|x[-_]?authorization)\s*([:=])\s*(?:Bearer|Basic|Digest|Token|OAuth|Negotiate|ApiKey)\s+[^\s,;&\r\n]+/i',
            fn (array $matches): string => $matches[1].$matches[2].($matches[2] === ':' ? ' ' : '').self::FILTERED,
            $value,
        );

        $scrubbed = preg_replace_callback(
            '/\b(password|passwd|token|secret|api[_-]?key|authorization|session|cookie)\s*([:=])\s*([^\s,;&]+)/i',
            fn (array $matches): string => $matches[1].$matches[2].($matches[2] === ':' ? ' ' : '').self::FILTERED,
            $scrubbed ?? $value,
        ) ?? $scrubbed ?? $value;

        return mb_strlen($scrubbed) > $limit ? mb_substr($scrubbed, 0, $limit).'...' : $scrubbed;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function scrubIterable(array $value, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['truncated' => true];
        }

        $scrubbed = [];
        $index = 0;

        foreach ($value as $key => $item) {
            if ($index >= 100) {
                $scrubbed['truncated'] = true;
                break;
            }

            $index++;
            $scrubbed[$key] = $this->isSensitiveKey((string) $key)
                ? self::FILTERED
                : $this->scrubValue($item, $depth + 1);
        }

        return $scrubbed;
    }

    private function scrubValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->scrubIterable($value, $depth);
        }

        if (is_string($value)) {
            return $this->scrubString($value, 4000);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));

        return in_array($normalized, [
            'authorization',
            'x_authorization',
            'password',
            'passwd',
            'project',
            'project_token',
            'shift_project',
            'secret',
            'api_key',
            'apikey',
            'access_token',
            'refresh_token',
            'bearer_token',
            'shift_session',
            'session',
            'session_id',
            'cookie',
        ], true)
            || str_ends_with($normalized, '_token')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_password');
    }
}
