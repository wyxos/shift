<?php

namespace App\Services\AppErrors\SourceMaps;

use RuntimeException;

class SourceMapReader
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    public function validate(array $map): void
    {
        $this->shape($map);
        $this->scan($map, [], true);
    }

    /** @param array<int, array{line: int, column: int}> $positions */
    public function positions(array $map, array $positions): array
    {
        $this->shape($map);

        return $this->scan($map, $positions);
    }

    private function shape(array $map): void
    {
        if (($map['version'] ?? null) !== 3 || isset($map['sections'])
            || ! is_string($map['mappings'] ?? null) || strlen($map['mappings']) > 16000000
            || ! is_array($map['sources'] ?? null) || ! array_is_list($map['sources']) || count($map['sources']) > 20000
            || ! is_array($map['names'] ?? null) || ! array_is_list($map['names']) || count($map['names']) > 100000
            || isset($map['sourceRoot']) && ! is_string($map['sourceRoot'])
            || isset($map['sourcesContent']) && (! is_array($map['sourcesContent']) || ! array_is_list($map['sourcesContent']) || count($map['sourcesContent']) !== count($map['sources']))) {
            throw new RuntimeException('Unsupported or oversized source map.');
        }
        foreach ($map['sources'] as $source) {
            if (! is_string($source) || strlen($source) > 4096 || str_contains($source, "\0")) {
                throw new RuntimeException('Invalid source map source name.');
            }
        }
        foreach ($map['names'] as $name) {
            if (! is_string($name) || strlen($name) > 4096) {
                throw new RuntimeException('Invalid source map symbol name.');
            }
        }
        foreach ($map['sourcesContent'] ?? [] as $content) {
            if ($content !== null && (! is_string($content) || strlen($content) > 4000000)) {
                throw new RuntimeException('Invalid source map source content.');
            }
        }
    }

    private function scan(array $map, array $positions, bool $validateOnly = false): array
    {
        $queries = [];
        foreach ($positions as $key => $position) {
            $queries[$position['line'] - 1][$key] = $position['column'] - 1;
        }
        $lastLine = $validateOnly ? PHP_INT_MAX : ($queries === [] ? -1 : max(array_keys($queries)));
        $result = array_fill_keys(array_keys($positions), null);
        $source = $originalLine = $originalColumn = $name = 0;
        foreach (explode(';', $map['mappings']) as $line => $encodedLine) {
            if ($line > $lastLine) {
                break;
            }
            $generatedColumn = 0;
            $candidate = null;
            $pending = $queries[$line] ?? [];
            asort($pending);
            foreach ($encodedLine === '' ? [] : explode(',', $encodedLine) as $encodedSegment) {
                $values = $this->decode($encodedSegment);
                if (! in_array(count($values), [1, 4, 5], true)) {
                    throw new RuntimeException('Invalid source map segment.');
                }
                $nextColumn = $generatedColumn + $values[0];
                if ($values[0] < 0 || $nextColumn > 2147483647) {
                    throw new RuntimeException('Invalid generated source map column.');
                }
                foreach ($pending as $key => $column) {
                    if ($column >= $nextColumn) {
                        break;
                    }
                    $result[$key] = $candidate;
                    unset($pending[$key]);
                }
                $generatedColumn = $nextColumn;
                $candidate = null;
                if (count($values) > 1) {
                    $source += $values[1];
                    $originalLine += $values[2];
                    $originalColumn += $values[3];
                    if ($source < 0 || $source >= count($map['sources']) || $originalLine < 0 || $originalLine > 2147483647 || $originalColumn < 0 || $originalColumn > 2147483647) {
                        throw new RuntimeException('Invalid original source map position.');
                    }
                    if (isset($values[4])) {
                        $name += $values[4];
                        if ($name < 0 || $name >= count($map['names'])) {
                            throw new RuntimeException('Invalid source map name index.');
                        }
                    }
                    $candidate = ['source' => $source, 'line' => $originalLine + 1, 'column' => $originalColumn + 1, 'name' => isset($values[4]) ? $map['names'][$name] : null];
                }
            }
            foreach ($pending as $key => $column) {
                $result[$key] = $candidate;
            }
        }

        return $result;
    }

    private function decode(string $segment): array
    {
        $values = [];
        $value = $shift = 0;
        for ($offset = 0, $length = strlen($segment); $offset < $length; $offset++) {
            $digit = strpos(self::ALPHABET, $segment[$offset]);
            if ($digit === false || $shift > 30) {
                throw new RuntimeException('Invalid source map VLQ.');
            }
            $value += ($digit & 31) << $shift;
            if (($digit & 32) === 0) {
                $values[] = ($value & 1) === 1 ? -($value >> 1) : $value >> 1;
                $value = $shift = 0;
                if (count($values) > 5) {
                    throw new RuntimeException('Source map segment has too many fields.');
                }
            } else {
                $shift += 5;
            }
        }
        if ($shift !== 0) {
            throw new RuntimeException('Truncated source map VLQ.');
        }

        return $values;
    }
}
