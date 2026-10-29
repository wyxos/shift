<?php

use App\Models\Organisation;
use App\Services\OrganisationTransfer\OrganisationTransferExporter;
use App\Services\OrganisationTransfer\OrganisationTransferImporter;
use App\Services\OrganisationTransfer\OrganisationTransferSelection;

function expectOrganisationTransferDirectory(string $requested, string $expected, string $operation): void
{
    $organisation = Organisation::factory()->create();
    $manifest = [
        'organisation' => ['name' => $organisation->name],
        'tables' => [],
        'attachments' => ['count' => 0, 'available_count' => 0, 'missing_count' => 0, 'bytes' => 0],
    ];
    if ($operation !== 'export') {
        $importer = Mockery::mock(OrganisationTransferImporter::class);
        $importer->shouldReceive('inspect')->once()->with($expected)->andReturn($manifest);
        if ($operation === 'import') {
            $importer->shouldReceive('import')->once()->with($expected, $manifest)
                ->andReturn(['verification' => $manifest]);
        } else {
            $importer->shouldReceive('verifyImported')->once()->with($manifest)->andReturn($manifest);
        }
        app()->instance(OrganisationTransferImporter::class, $importer);
        $arguments = ['directory' => $requested];
        if ($operation === 'import') {
            $arguments['--confirm'] = 'IMPORT';
        }
        test()->artisan('shift:organisation-transfer:'.$operation, $arguments)->assertSuccessful();

        return;
    }

    $exporter = Mockery::mock(OrganisationTransferExporter::class);
    $exporter->shouldReceive('export')
        ->once()
        ->with(
            Mockery::on(fn (OrganisationTransferSelection $selection): bool => $selection->organisation->is($organisation)),
            $expected,
        )
        ->andReturn($manifest);
    app()->instance(OrganisationTransferExporter::class, $exporter);

    test()->artisan('shift:organisation-transfer:export', [
        'organisation' => (string) $organisation->id,
        '--output' => $requested,
    ])->assertSuccessful();
}

test('organisation transfer preserves slash rooted paths', function (string $path, string $operation) {
    expectOrganisationTransferDirectory($path, $path, $operation);
})->with([
    'unix root' => '/',
    'unix directory' => '/tmp/shift-export',
    'forward slash network share' => '//server/share/shift-export',
])->with(['export', 'import', 'verify']);

test('organisation transfer resolves relative paths from the application directory', function (string $path, string $operation) {
    expectOrganisationTransferDirectory($path, base_path($path), $operation);
})->with([
    'directory' => 'exports',
    'nested directory' => 'exports/shift',
    'parent directory' => '../shift-export',
    'dot directory' => './shift-export',
    'drive relative directory' => 'C:shift-export',
])->with(['export', 'import', 'verify']);

test('organisation transfer preserves native Windows absolute paths', function (string $path, string $operation) {
    expectOrganisationTransferDirectory($path, $path, $operation);
})->with([
    'drive and backslashes' => 'C:\\exports\\shift',
    'drive and forward slashes' => 'C:/exports/shift',
    'lowercase drive root' => 'd:\\',
    'network share' => '\\\\server\\share\\shift-export',
    'current drive root' => '\\exports\\shift',
    'extended drive path' => '\\\\?\\C:\\exports\\shift',
    'extended network path' => '\\\\?\\UNC\\server\\share\\shift-export',
])->with(['export', 'import', 'verify'])->skip(PHP_OS_FAMILY !== 'Windows', 'Windows native paths');

test('organisation transfer retains backslashes in Unix relative filenames', function (string $operation) {
    $path = 'exports\\shift';

    expectOrganisationTransferDirectory($path, base_path($path), $operation);
})->with(['export', 'import', 'verify'])->skip(PHP_OS_FAMILY === 'Windows', 'Unix filename semantics');
