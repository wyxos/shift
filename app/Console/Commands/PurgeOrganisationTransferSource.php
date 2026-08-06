<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Services\OrganisationTransfer\OrganisationTransferPurger;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class PurgeOrganisationTransferSource extends Command
{
    /** @var string */
    protected $signature = 'shift:organisation-transfer:purge-source
        {organisation : Organisation ID or exact name}
        {--delete-users : Delete transferred users that have no remaining domain references}
        {--expected-fingerprint= : Exact fingerprint emitted by the reviewed dry run}
        {--confirm= : Must be PURGE to perform the deletion}';

    /** @var string */
    protected $description = 'Dry-run or purge a transferred organisation from its source installation';

    public function handle(OrganisationTransferPurger $purger): int
    {
        try {
            $organisation = $this->resolveOrganisation((string) $this->argument('organisation'));
            $deleteUsers = (bool) $this->option('delete-users');
            $report = $purger->inspect($organisation, $deleteUsers);
            $this->displayReport($report);

            if ($this->option('confirm') !== 'PURGE') {
                $this->components->warn('Dry run only. No data or files were changed.');
                $this->line("Reviewed fingerprint: {$report['fingerprint']}");

                return self::SUCCESS;
            }

            $expectedFingerprint = $this->option('expected-fingerprint');
            if (! is_string($expectedFingerprint) || $expectedFingerprint === '') {
                throw new RuntimeException('Purge refused. --expected-fingerprint must match a reviewed dry run.');
            }

            $purger->purge($organisation, $deleteUsers, $expectedFingerprint);
            $this->components->info("Purged organisation [{$organisation->name}] from this SHIFT installation.");

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

    /** @param array<string, mixed> $report */
    private function displayReport(array $report): void
    {
        $this->components->info("Purge scope for organisation [{$report['organisation']['name']}] (ID {$report['organisation']['id']}).");
        $this->table(
            ['Table', 'Rows'],
            collect($report['tables'])
                ->reject(fn (int $count, string $table): bool => $table === 'users')
                ->map(fn (int $count, string $table): array => [$table, $count])
                ->values()
                ->all(),
        );
        $this->line("Attachments: {$report['attachments']['count']} records / {$report['attachments']['available_count']} files / {$report['attachments']['missing_count']} already missing");

        if (! $report['users']['requested']) {
            $this->line('Transferred user accounts: preserved (use --delete-users to evaluate exclusive accounts).');

            return;
        }

        $deleteIds = implode(', ', $report['users']['delete_ids']) ?: 'none';
        $this->line("Exclusive transferred user IDs to delete: {$deleteIds}");
        foreach ($report['users']['preserved'] as $userId => $references) {
            $this->line("User ID {$userId} will be preserved; remaining references: ".implode(', ', $references));
        }
    }
}
