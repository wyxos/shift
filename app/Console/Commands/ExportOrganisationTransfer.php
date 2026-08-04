<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Services\OrganisationTransfer\OrganisationTransferExporter;
use App\Services\OrganisationTransfer\OrganisationTransferSelection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExportOrganisationTransfer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shift:organisation-transfer:export
        {organisation : Organisation ID or exact name}
        {--output= : New transfer directory; defaults to private application storage}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export one organisation and its public SHIFT data into a verified transfer directory';

    /**
     * Execute the console command.
     */
    public function handle(OrganisationTransferExporter $exporter): int
    {
        try {
            $organisation = $this->resolveOrganisation((string) $this->argument('organisation'));
            $directory = $this->resolveOutputDirectory($organisation);
            $manifest = $exporter->export(new OrganisationTransferSelection($organisation), $directory);

            $this->components->info("Exported organisation [{$organisation->name}] to [{$directory}].");
            $this->table(
                ['Table', 'Rows'],
                collect($manifest['tables'])->map(fn (array $details, string $table): array => [$table, $details['rows']])->values()->all(),
            );
            $this->line("Attachments: {$manifest['attachments']['count']} files / {$manifest['attachments']['bytes']} bytes");
            $this->components->warn('The transfer contains password hashes and access tokens. Keep the directory mode-private and delete it after signed-off migration cleanup.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveOrganisation(string $value): Organisation
    {
        $query = Organisation::query();
        $organisation = ctype_digit($value)
            ? $query->find((int) $value)
            : $query->where('name', $value)->first();

        if (! $organisation instanceof Organisation) {
            throw new RuntimeException("Organisation [{$value}] was not found.");
        }

        return $organisation;
    }

    private function resolveOutputDirectory(Organisation $organisation): string
    {
        $requested = $this->option('output');
        if (is_string($requested) && $requested !== '') {
            return str_starts_with($requested, '/') ? $requested : base_path($requested);
        }

        $slug = Str::slug((string) $organisation->name) ?: 'organisation-'.$organisation->getKey();

        return storage_path('app/private/organisation-transfers/'.$slug.'-'.now()->utc()->format('YmdHis'));
    }
}
