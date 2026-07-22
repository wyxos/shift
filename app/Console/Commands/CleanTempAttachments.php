<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanTempAttachments extends Command
{
    private const MAX_AGE_HOURS = 24;

    private const TEMPORARY_ROOTS = [
        'temp_attachments' => 'temporary attachment',
        'temp_chunks' => 'temporary chunk',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attachments:clean-temp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean temporary upload directories older than 24 hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Cleaning temporary attachments...');

        $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deletedByRoot = array_fill_keys(array_keys(self::TEMPORARY_ROOTS), 0);

        foreach (self::TEMPORARY_ROOTS as $root => $label) {
            foreach (Storage::directories($root) as $directory) {
                if (! $this->isWithinRoot($directory, $root)) {
                    continue;
                }

                if ($this->lastModifiedAt($directory) > $cutoff) {
                    continue;
                }

                if (! Storage::deleteDirectory($directory)) {
                    $this->warn("Failed to delete: {$directory}");

                    continue;
                }

                $this->info("Deleted: {$directory}");
                $deletedByRoot[$root]++;
            }

            $this->info("Cleaned {$deletedByRoot[$root]} {$label} directories.");
        }

        return Command::SUCCESS;
    }

    private function isWithinRoot(string $directory, string $root): bool
    {
        $rootPath = realpath(Storage::path($root));
        $directoryPath = realpath(Storage::path($directory));

        if ($rootPath === false || $directoryPath === false) {
            return false;
        }

        return str_starts_with($directoryPath, $rootPath.DIRECTORY_SEPARATOR);
    }

    private function lastModifiedAt(string $directory): int
    {
        $paths = [
            $directory,
            ...Storage::allDirectories($directory),
            ...Storage::allFiles($directory),
        ];

        return max(array_map(
            fn (string $path): int => Storage::lastModified($path),
            $paths,
        ));
    }
}
