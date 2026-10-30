<?php

namespace App\Console\Commands;

use App\Services\AppErrors\SourceMaps\SdkBuildStore;
use Illuminate\Console\Command;
use Throwable;

class VerifySdkBuild extends Command
{
    protected $signature = 'shift:sdk-build:verify {build_id : Registered SDK build identifier} {--store= : Private build store} {--json : Return a verification receipt}';

    protected $description = 'Verify registered SDK JavaScript and private source-map checksums';

    public function handle(SdkBuildStore $store): int
    {
        try {
            $receipt = $store->verify((string) $this->argument('build_id'), $this->option('store') ?: null);
        } catch (Throwable $exception) {
            $this->error('SDK build verification failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line($this->option('json')
            ? json_encode($receipt, JSON_THROW_ON_ERROR)
            : 'Verified SDK build '.$receipt['build_id'].' ('.$receipt['assets_count'].' JavaScript assets).');

        return self::SUCCESS;
    }
}
