<?php

namespace App\Console\Commands;

use App\Services\OrganisationTransfer\OrganisationTransferImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportOrganisationTransfer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shift:organisation-transfer:import
        {directory : Verified transfer directory}
        {--confirm= : Must be IMPORT}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a verified organisation transfer into an empty public SHIFT installation';

    /**
     * Execute the console command.
     */
    public function handle(OrganisationTransferImporter $importer): int
    {
        if ($this->option('confirm') !== 'IMPORT') {
            $this->components->error('Import refused. Pass --confirm=IMPORT after reviewing the migration plan.');

            return self::FAILURE;
        }

        try {
            $directory = $this->resolveDirectory((string) $this->argument('directory'));
            $manifest = $importer->inspect($directory);
            $receipt = $importer->import($directory, $manifest);

            $this->components->info("Imported organisation [{$manifest['organisation']['name']}] and verified the result.");
            $this->table(
                ['Table', 'Rows'],
                collect($receipt['verification']['tables'])->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
            );
            $this->line("Attachments: {$receipt['verification']['attachments']['count']} files / {$receipt['verification']['attachments']['bytes']} bytes");
            $this->line("Receipt: {$directory}/import-receipt.json");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveDirectory(string $directory): string
    {
        return str_starts_with($directory, '/') ? $directory : base_path($directory);
    }
}
