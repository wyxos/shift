<?php

namespace App\Console\Commands;

use App\Services\OrganisationTransfer\OrganisationTransferImporter;
use Illuminate\Console\Command;
use Throwable;

class VerifyOrganisationTransfer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shift:organisation-transfer:verify
        {directory : Transfer directory already imported into this installation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify imported table counts and attachment checksums against a SHIFT transfer manifest';

    /**
     * Execute the console command.
     */
    public function handle(OrganisationTransferImporter $importer): int
    {
        try {
            $requested = (string) $this->argument('directory');
            $directory = str_starts_with($requested, '/') ? $requested : base_path($requested);
            $manifest = $importer->inspect($directory);
            $verification = $importer->verifyImported($manifest);

            $this->components->info("Organisation [{$manifest['organisation']['name']}] matches the transfer manifest.");
            $this->table(
                ['Table', 'Rows'],
                collect($verification['tables'])->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
            );
            $this->line("Attachments: {$verification['attachments']['count']} files / {$verification['attachments']['bytes']} bytes");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
