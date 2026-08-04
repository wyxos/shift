<?php

namespace App\Services\OrganisationTransfer;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

class OrganisationTransferExporter
{
    public const FORMAT = 'shift-public-organisation-transfer';

    public const VERSION = 2;

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function export(OrganisationTransferSelection $selection, string $directory): array
    {
        if (File::exists($directory)) {
            throw new RuntimeException("Transfer directory [{$directory}] already exists.");
        }

        try {
            File::makeDirectory($directory, 0700, true);
            File::makeDirectory("{$directory}/data", 0700);
            File::makeDirectory("{$directory}/files", 0700);

            $tables = [];
            foreach (OrganisationTransferSelection::TABLES as $table) {
                $path = "{$directory}/data/{$table}.jsonl";
                $handle = fopen($path, 'wb');
                $rowCount = 0;

                if ($handle === false) {
                    throw new RuntimeException("Unable to create transfer data file for [{$table}].");
                }

                try {
                    foreach ($selection->rows($table) as $row) {
                        $encoded = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        if (fwrite($handle, $encoded."\n") === false) {
                            throw new RuntimeException("Unable to write transfer data file for [{$table}].");
                        }

                        $rowCount++;
                    }
                } finally {
                    fclose($handle);
                }

                chmod($path, 0600);
                $tables[$table] = [
                    'rows' => $rowCount,
                    'sha256' => hash_file('sha256', $path),
                ];
            }

            $attachments = $this->exportAttachments($selection, $directory);
            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'exported_at' => now()->utc()->toIso8601String(),
                'source' => [
                    'application' => config('app.name'),
                    'application_url' => rtrim((string) config('app.url'), '/'),
                    'database_driver' => (string) config('database.default'),
                ],
                'organisation' => [
                    'id' => (int) $selection->organisation->getKey(),
                    'name' => (string) $selection->organisation->name,
                ],
                'project_ids' => $selection->projectIds(),
                'user_ids' => $selection->userIds(),
                'tables' => $tables,
                'attachments' => [
                    'count' => count($attachments),
                    'available_count' => collect($attachments)->where('availability', 'available')->count(),
                    'missing_count' => collect($attachments)->where('availability', 'missing_at_source')->count(),
                    'bytes' => collect($attachments)->where('availability', 'available')->sum('bytes'),
                    'files' => $attachments,
                ],
                'exclusions' => [
                    'operational_tables' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens'],
                    'user_scoped_tables' => ['agent_conversations', 'agent_conversation_messages', 'personal_access_tokens'],
                    'hosted_only_tables' => ['billing_settings', 'paypal_subscription_plans', 'paypal_webhook_events', 'user_impersonation_sessions', 'user_subscriptions'],
                    'hosted_only_columns' => ['users.is_admin'],
                ],
            ];

            $manifestPath = "{$directory}/manifest.json";
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            chmod($manifestPath, 0600);

            return $manifest;
        } catch (Throwable $exception) {
            if (File::isDirectory($directory)) {
                File::deleteDirectory($directory);
            }

            throw $exception;
        }
    }

    /**
     * @return list<array{id: int, path: string, availability: string, transfer_file?: string, bytes?: int, sha256?: string}>
     */
    private function exportAttachments(OrganisationTransferSelection $selection, string $directory): array
    {
        $files = [];
        $seenPaths = [];

        foreach ($selection->rows('attachments') as $attachment) {
            $id = (int) $attachment['id'];
            $path = (string) $attachment['path'];
            $this->assertSafeAttachmentPath($path, $id);

            if (isset($seenPaths[$path])) {
                throw new RuntimeException("Attachments [{$seenPaths[$path]}] and [{$id}] use the same storage path [{$path}].");
            }
            $seenPaths[$path] = $id;

            if (! Storage::exists($path)) {
                $files[] = [
                    'id' => $id,
                    'path' => $path,
                    'availability' => 'missing_at_source',
                ];

                continue;
            }

            $transferFile = "files/{$id}";
            $target = "{$directory}/{$transferFile}";
            $source = Storage::readStream($path);
            $destination = fopen($target, 'wb');

            if ($source === null || $source === false || $destination === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($destination)) {
                    fclose($destination);
                }

                throw new RuntimeException("Unable to open attachment [{$id}] for transfer.");
            }

            try {
                if (stream_copy_to_stream($source, $destination) === false) {
                    throw new RuntimeException("Unable to copy attachment [{$id}] into the transfer.");
                }
            } finally {
                fclose($source);
                fclose($destination);
            }

            chmod($target, 0600);
            $files[] = [
                'id' => $id,
                'path' => $path,
                'availability' => 'available',
                'transfer_file' => $transferFile,
                'bytes' => File::size($target),
                'sha256' => hash_file('sha256', $target),
            ];
        }

        return $files;
    }

    private function assertSafeAttachmentPath(string $path, int $id): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)
            || ! str_starts_with($path, 'attachments/')) {
            throw new RuntimeException("Attachment [{$id}] has unsafe storage path [{$path}].");
        }
    }
}
