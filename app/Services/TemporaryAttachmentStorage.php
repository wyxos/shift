<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use JsonException;

class TemporaryAttachmentStorage
{
    public const IDENTIFIER_RULE = 'regex:/\A[A-Za-z0-9_-]{1,128}\z/';

    private const OWNER_METADATA = '.owner.meta';

    private const ROOT = 'temp_attachments';

    public function claim(string $tempIdentifier, ?int $userId): ?string
    {
        $directory = $this->canonicalDirectory($tempIdentifier);
        if ($directory === null || $userId === null) {
            return null;
        }

        if ($this->isOwnedBy($directory, $userId)) {
            return $directory;
        }

        $ownerPath = $this->ownerPath($directory);
        if (Storage::exists($ownerPath)) {
            return null;
        }

        if (Storage::exists($directory)
            && (Storage::files($directory) !== [] || Storage::directories($directory) !== [])) {
            return null;
        }

        Storage::makeDirectory($directory);
        $metadata = json_encode(['user_id' => $userId], JSON_THROW_ON_ERROR);
        $ownerFile = @fopen(Storage::path($ownerPath), 'x');
        if ($ownerFile === false) {
            return null;
        }

        $metadataWritten = false;
        try {
            $bytesWritten = fwrite($ownerFile, $metadata);
            $metadataWritten = $bytesWritten === strlen($metadata) && fflush($ownerFile);
        } finally {
            fclose($ownerFile);
        }

        if (! $metadataWritten) {
            Storage::delete($ownerPath);

            return null;
        }

        return $this->isOwnedBy($directory, $userId) ? $directory : null;
    }

    public function ownedDirectory(string $tempIdentifier, ?int $userId): ?string
    {
        $directory = $this->canonicalDirectory($tempIdentifier);
        if ($directory === null || $userId === null || ! $this->isOwnedBy($directory, $userId)) {
            return null;
        }

        return $directory;
    }

    public function canonicalFilePath(string $path): ?string
    {
        if (! preg_match('#\A'.preg_quote(self::ROOT, '#').'/([^/]+)/([^/]+)\z#D', $path, $matches)) {
            return null;
        }

        $canonicalPath = $this->filePath($matches[1], $matches[2]);

        return $canonicalPath === $path ? $canonicalPath : null;
    }

    public function ownedFilePath(string $path, ?int $userId): ?string
    {
        $canonicalPath = $this->canonicalFilePath($path);
        if ($canonicalPath === null) {
            return null;
        }

        $tempIdentifier = explode('/', $canonicalPath, 3)[1];

        return $this->ownedDirectory($tempIdentifier, $userId) === null ? null : $canonicalPath;
    }

    public function ownedRouteFilePath(string $tempIdentifier, string $filename, ?int $userId): ?string
    {
        $path = $this->filePath($tempIdentifier, $filename);
        if ($path === null || $this->ownedDirectory($tempIdentifier, $userId) === null) {
            return null;
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    public function ownedFiles(string $tempIdentifier, ?int $userId): array
    {
        $directory = $this->ownedDirectory($tempIdentifier, $userId);
        if ($directory === null) {
            return [];
        }

        return array_values(array_filter(
            Storage::files($directory),
            fn (string $path): bool => $this->canonicalFilePath($path) === $path,
        ));
    }

    public function deleteOwnedDirectory(string $tempIdentifier, ?int $userId): void
    {
        $directory = $this->ownedDirectory($tempIdentifier, $userId);
        if ($directory !== null) {
            Storage::deleteDirectory($directory);
        }
    }

    private function canonicalDirectory(string $tempIdentifier): ?string
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $tempIdentifier)) {
            return null;
        }

        return self::ROOT.'/'.$tempIdentifier;
    }

    private function filePath(string $tempIdentifier, string $filename): ?string
    {
        $directory = $this->canonicalDirectory($tempIdentifier);
        if ($directory === null
            || $filename === ''
            || strlen($filename) > 255
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename)
            || str_ends_with(strtolower($filename), '.meta')) {
            return null;
        }

        return $directory.'/'.$filename;
    }

    private function ownerPath(string $directory): string
    {
        return $directory.'/'.self::OWNER_METADATA;
    }

    private function isOwnedBy(string $directory, int $userId): bool
    {
        $ownerPath = $this->ownerPath($directory);
        if (! Storage::exists($ownerPath)) {
            return false;
        }

        try {
            $metadata = json_decode(Storage::get($ownerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($metadata)
            && isset($metadata['user_id'])
            && hash_equals((string) $userId, (string) $metadata['user_id']);
    }
}
