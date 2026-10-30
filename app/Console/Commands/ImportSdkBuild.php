<?php

namespace App\Console\Commands;

use App\Services\AppErrors\SourceMaps\SdkBuildStore;
use Illuminate\Console\Command;
use Throwable;

class ImportSdkBuild extends Command
{
    protected $signature = 'shift:sdk-build:import {path : SDK private artifact directory} {--store= : Private destination directory}';

    protected $description = 'Import an immutable SDK build and private source maps for error diagnosis';

    public function handle(SdkBuildStore $store): int
    {
        try {
            $manifest = $store->import((string) $this->argument('path'), $this->option('store') ?: null);
        } catch (Throwable $exception) {
            $this->error('SDK build import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Imported SDK build '.$manifest['build_id'].' ('.count($manifest['assets']).' JavaScript assets).');

        return self::SUCCESS;
    }
}
