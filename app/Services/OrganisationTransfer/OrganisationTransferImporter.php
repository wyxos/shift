<?php

namespace App\Services\OrganisationTransfer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use JsonException;
use RuntimeException;
use Throwable;

class OrganisationTransferImporter
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function inspect(string $directory): array
    {
        $manifestPath = "{$directory}/manifest.json";
        if (! File::isFile($manifestPath)) {
            throw new RuntimeException("Transfer manifest not found at [{$manifestPath}].");
        }

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('Transfer manifest must contain a JSON object.');
        }

        $this->assertManifestContract($manifest);
        $this->verifyTransferFiles($directory, $manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function import(string $directory, array $manifest): array
    {
        $this->assertTargetEmpty();
        $this->assertAttachmentTargetsAvailable($manifest);

        $copiedAttachmentPaths = [];
        $committed = false;

        try {
            foreach ($manifest['attachments']['files'] as $file) {
                $source = fopen("{$directory}/{$file['transfer_file']}", 'rb');
                if ($source === false) {
                    throw new RuntimeException("Unable to open transfer attachment [{$file['id']}].");
                }

                try {
                    if (! Storage::writeStream($file['path'], $source)) {
                        throw new RuntimeException("Unable to write attachment [{$file['id']}] to target storage.");
                    }
                } finally {
                    fclose($source);
                }

                $copiedAttachmentPaths[] = $file['path'];
            }

            DB::transaction(function () use ($directory, $manifest): void {
                foreach (OrganisationTransferSelection::TABLES as $table) {
                    $targetColumns = Schema::getColumnListing($table);

                    foreach ($this->readRows($directory, $table, $manifest['tables'][$table])->chunk(50) as $chunk) {
                        $transformed = [];
                        foreach ($chunk as $row) {
                            $row = $this->transformRow($table, $row, $manifest);
                            $unexpected = array_diff(array_keys($row), $targetColumns);
                            if ($unexpected !== []) {
                                throw new RuntimeException("Table [{$table}] contains columns absent from public SHIFT: ".implode(', ', $unexpected).'.');
                            }

                            $transformed[] = $row;
                        }

                        DB::table($table)->insert($transformed);
                    }
                }
            }, 1);

            $committed = true;
        } catch (Throwable $exception) {
            if (! $committed) {
                foreach ($copiedAttachmentPaths as $path) {
                    Storage::delete($path);
                }
            }

            throw $exception;
        }

        $verification = $this->verifyImported($manifest);
        $receipt = [
            'format' => OrganisationTransferExporter::FORMAT,
            'version' => OrganisationTransferExporter::VERSION,
            'manifest_sha256' => hash_file('sha256', "{$directory}/manifest.json"),
            'imported_at' => now()->utc()->toIso8601String(),
            'organisation' => $manifest['organisation'],
            'verification' => $verification,
        ];

        File::put(
            "{$directory}/import-receipt.json",
            json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
        );
        chmod("{$directory}/import-receipt.json", 0600);

        return $receipt;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{tables: array<string, int>, attachments: array{count: int, bytes: int}}
     */
    public function verifyImported(array $manifest): array
    {
        $counts = [];
        foreach (OrganisationTransferSelection::TABLES as $table) {
            $actual = DB::table($table)->count();
            $expected = (int) $manifest['tables'][$table]['rows'];
            if ($actual !== $expected) {
                throw new RuntimeException("Imported table [{$table}] has [{$actual}] rows; expected [{$expected}].");
            }

            $counts[$table] = $actual;
        }

        $bytes = 0;
        foreach ($manifest['attachments']['files'] as $file) {
            if (! Storage::exists($file['path'])) {
                throw new RuntimeException("Imported attachment [{$file['id']}] is missing at [{$file['path']}].");
            }

            $actualHash = $this->hashStorageFile($file['path']);
            if (! hash_equals((string) $file['sha256'], $actualHash)) {
                throw new RuntimeException("Imported attachment [{$file['id']}] failed checksum verification.");
            }

            $actualBytes = Storage::size($file['path']);
            if ($actualBytes !== (int) $file['bytes']) {
                throw new RuntimeException("Imported attachment [{$file['id']}] failed size verification.");
            }

            $bytes += $actualBytes;
        }

        return [
            'tables' => $counts,
            'attachments' => [
                'count' => count($manifest['attachments']['files']),
                'bytes' => $bytes,
            ],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestContract(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== OrganisationTransferExporter::FORMAT
            || ($manifest['version'] ?? null) !== OrganisationTransferExporter::VERSION) {
            throw new RuntimeException('Unsupported SHIFT organisation-transfer format or version.');
        }

        if (! isset($manifest['organisation']['id'], $manifest['organisation']['name'])
            || ! is_array($manifest['tables'] ?? null)
            || ! is_array($manifest['attachments']['files'] ?? null)) {
            throw new RuntimeException('Transfer manifest is incomplete.');
        }

        $manifestTables = array_keys($manifest['tables']);
        if ($manifestTables !== OrganisationTransferSelection::TABLES) {
            throw new RuntimeException('Transfer manifest tables do not match the public SHIFT transfer contract.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function verifyTransferFiles(string $directory, array $manifest): void
    {
        foreach (OrganisationTransferSelection::TABLES as $table) {
            $path = "{$directory}/data/{$table}.jsonl";
            if (! File::isFile($path)) {
                throw new RuntimeException("Transfer data file for [{$table}] is missing.");
            }

            $actualHash = hash_file('sha256', $path);
            if (! hash_equals((string) ($manifest['tables'][$table]['sha256'] ?? ''), $actualHash)) {
                throw new RuntimeException("Transfer data file for [{$table}] failed checksum verification.");
            }

            $this->readRows($directory, $table, $manifest['tables'][$table])->count();
        }

        $seenIds = [];
        $seenPaths = [];
        foreach ($manifest['attachments']['files'] as $file) {
            if (! isset($file['id'], $file['path'], $file['transfer_file'], $file['bytes'], $file['sha256'])) {
                throw new RuntimeException('Transfer attachment manifest entry is incomplete.');
            }

            $this->assertSafeAttachmentEntry($file);

            if (isset($seenIds[$file['id']]) || isset($seenPaths[$file['path']])) {
                throw new RuntimeException('Transfer attachment manifest contains a duplicate ID or target path.');
            }
            $seenIds[$file['id']] = true;
            $seenPaths[$file['path']] = true;

            $path = "{$directory}/{$file['transfer_file']}";
            if (! File::isFile($path)
                || File::size($path) !== (int) $file['bytes']
                || ! hash_equals((string) $file['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException("Transfer attachment [{$file['id']}] failed file verification.");
            }
        }

        if (count($seenIds) !== (int) ($manifest['attachments']['count'] ?? -1)
            || array_sum(array_map(fn (array $file): int => (int) $file['bytes'], $manifest['attachments']['files'])) !== (int) ($manifest['attachments']['bytes'] ?? -1)) {
            throw new RuntimeException('Transfer attachment totals do not match the manifest.');
        }

        $rowPaths = $this->readRows($directory, 'attachments', $manifest['tables']['attachments'])
            ->mapWithKeys(fn (array $row): array => [(int) $row['id'] => (string) $row['path']])
            ->all();
        if (count($rowPaths) !== count($seenIds)) {
            throw new RuntimeException('Transfer attachment files do not match the attachment table row count.');
        }

        foreach ($manifest['attachments']['files'] as $file) {
            if (($rowPaths[(int) $file['id']] ?? null) !== $file['path']) {
                throw new RuntimeException("Transfer attachment [{$file['id']}] does not match its database row.");
            }
        }
    }

    private function assertTargetEmpty(): void
    {
        $nonEmpty = [];
        foreach (OrganisationTransferSelection::TABLES as $table) {
            $count = DB::table($table)->count();
            if ($count > 0) {
                $nonEmpty[$table] = $count;
            }
        }

        if ($nonEmpty !== []) {
            $summary = collect($nonEmpty)->map(fn (int $count, string $table): string => "{$table}={$count}")->implode(', ');
            throw new RuntimeException("The target is not empty ({$summary}). Organisation transfers never merge into existing domain data.");
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertAttachmentTargetsAvailable(array $manifest): void
    {
        foreach ($manifest['attachments']['files'] as $file) {
            if (Storage::exists($file['path'])) {
                throw new RuntimeException("Target attachment path [{$file['path']}] already exists.");
            }
        }
    }

    /** @param array<string, mixed> $file */
    private function assertSafeAttachmentEntry(array $file): void
    {
        $id = (int) $file['id'];
        $path = (string) $file['path'];
        $transferFile = (string) $file['transfer_file'];

        if ($id < 1
            || $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)
            || ! str_starts_with($path, 'attachments/')
            || $transferFile !== "files/{$id}") {
            throw new RuntimeException("Transfer attachment [{$id}] contains an unsafe path.");
        }
    }

    private function hashStorageFile(string $path): string
    {
        $stream = Storage::readStream($path);
        if ($stream === null || $stream === false) {
            throw new RuntimeException("Unable to read imported attachment at [{$path}].");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function transformRow(string $table, array $row, array $manifest): array
    {
        if ($table !== 'notifications') {
            return $row;
        }

        $sourceUrl = rtrim((string) ($manifest['source']['application_url'] ?? ''), '/');
        $targetUrl = rtrim((string) config('app.url'), '/');
        if ($sourceUrl === '' || $targetUrl === '') {
            throw new RuntimeException('Source and target application URLs are required to migrate notifications safely.');
        }

        $data = json_decode((string) $row['data'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException("Notification [{$row['id']}] contains invalid JSON.");
        }

        if (isset($data['url']) && is_string($data['url']) && str_starts_with($data['url'], $sourceUrl)) {
            $data['url'] = $targetUrl.substr($data['url'], strlen($sourceUrl));
        }

        $row['data'] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $tableManifest
     * @return LazyCollection<int, array<string, mixed>>
     */
    private function readRows(string $directory, string $table, array $tableManifest): LazyCollection
    {
        $path = "{$directory}/data/{$table}.jsonl";

        return LazyCollection::make(function () use ($path, $table, $tableManifest): \Generator {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Unable to read transfer data file for [{$table}].");
            }

            $count = 0;
            try {
                while (($line = fgets($handle)) !== false) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($row)) {
                        throw new RuntimeException("Transfer data file for [{$table}] contains a non-object row.");
                    }

                    $count++;
                    yield $row;
                }
            } finally {
                fclose($handle);
            }

            if ($count !== (int) ($tableManifest['rows'] ?? -1)) {
                throw new RuntimeException("Transfer data file for [{$table}] does not match its row count.");
            }
        });
    }
}
