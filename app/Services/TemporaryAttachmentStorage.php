<?php

namespace App\Services;

use App\Support\TemporaryAttachmentOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use JsonException;

class TemporaryAttachmentStorage
{
    public const IDENTIFIER_RULE = 'regex:/\A[A-Za-z0-9_-]{1,128}\z/';

    private const OWNER_METADATA = '.owner.meta';

    private const ROOT = 'temp_attachments';

    public function __construct(private readonly TemporaryAttachmentOwnerResolver $owners) {}

    public function ownerForRequest(Request $request): ?TemporaryAttachmentOwner
    {
        return $this->owners->resolve($request);
    }

    public function fingerprint(TemporaryAttachmentOwner|int|null $owner): ?string
    {
        return is_int($owner) ? TemporaryAttachmentOwner::portal($owner)->fingerprint : $owner?->fingerprint;
    }

    public function claim(string $tempIdentifier, TemporaryAttachmentOwner|int|null $owner): ?string
    {
        $directory = $this->canonicalDirectory($tempIdentifier);
        if ($directory === null || $owner === null) {
            return null;
        }

        if ($this->isOwnedBy($directory, $owner)) {
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
        $metadata = json_encode(['version' => 2, 'owner' => $this->fingerprint($owner)], JSON_THROW_ON_ERROR);
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

        return $this->isOwnedBy($directory, $owner) ? $directory : null;
    }

    public function ownedDirectory(string $tempIdentifier, TemporaryAttachmentOwner|int|null $owner): ?string
    {
        $directory = $this->canonicalDirectory($tempIdentifier);
        if ($directory === null || $owner === null || ! $this->isOwnedBy($directory, $owner)) {
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

    public function ownedFilePath(string $path, TemporaryAttachmentOwner|int|null $owner): ?string
    {
        $canonicalPath = $this->canonicalFilePath($path);
        if ($canonicalPath === null) {
            return null;
        }

        $tempIdentifier = explode('/', $canonicalPath, 3)[1];

        return $this->ownedDirectory($tempIdentifier, $owner) === null ? null : $canonicalPath;
    }

    public function ownedRouteFilePath(string $tempIdentifier, string $filename, TemporaryAttachmentOwner|int|null $owner): ?string
    {
        $path = $this->filePath($tempIdentifier, $filename);
        if ($path === null || $this->ownedDirectory($tempIdentifier, $owner) === null) {
            return null;
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    public function ownedFiles(string $tempIdentifier, TemporaryAttachmentOwner|int|null $owner): array
    {
        $directory = $this->ownedDirectory($tempIdentifier, $owner);
        if ($directory === null) {
            return [];
        }

        return array_values(array_filter(
            Storage::files($directory),
            fn (string $path): bool => $this->canonicalFilePath($path) === $path,
        ));
    }

    public function deleteOwnedDirectory(string $tempIdentifier, TemporaryAttachmentOwner|int|null $owner): void
    {
        $directory = $this->ownedDirectory($tempIdentifier, $owner);
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

    private function isOwnedBy(string $directory, TemporaryAttachmentOwner|int $owner): bool
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
            && ($metadata['version'] ?? null) === 2
            && is_string($metadata['owner'] ?? null)
            && hash_equals($this->fingerprint($owner), $metadata['owner']);
    }
}
