<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanTempAttachments extends Command
{
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
    protected $description = 'Clean temporary attachments older than 24 hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Cleaning temporary attachments...');

        // Get all directories in the temp_attachments folder
        $tempDirs = Storage::directories('temp_attachments');
        $count = 0;

        foreach ($tempDirs as $dir) {
            // Get the last modified time of the directory
            $lastModified = Storage::lastModified($dir);
            $lastModifiedDate = Carbon::createFromTimestamp($lastModified);

            // If the directory is older than 24 hours, delete it
            if ($lastModifiedDate->diffInHours(now()) >= 24) {
                Storage::deleteDirectory($dir);
                $this->info("Deleted: {$dir}");
                $count++;
            }
        }

        $this->info("Cleaned {$count} temporary attachment directories.");

        return Command::SUCCESS;
    }
}
