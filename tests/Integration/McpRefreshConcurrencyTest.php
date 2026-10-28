<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('serializes simultaneous refresh deliveries on MySQL or MariaDB', function () {
    $database = getenv('SHIFT_MCP_RECOVERY_DATABASE');
    if (! is_string($database) || $database === '') {
        $this->markTestSkipped('Set SHIFT_MCP_RECOVERY_DATABASE to an isolated local shift_testing_* database.');
    }
    if (! preg_match('/^shift_testing_[a-z0-9_]+$/D', $database)) {
        throw new RuntimeException('The concurrency database must be an isolated shift_testing_* database.');
    }
    $mysql = config('database.connections.mysql');
    if (! in_array($mysql['host'], ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('The concurrency database must run locally.');
    }
    $mysql['database'] = $database;
    $mysql['url'] = null;
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => $mysql,
        'passport.connection' => 'mysql',
        'app.url' => 'https://shift.test',
        'shift_mcp.issuer' => 'https://shift.test',
        'shift_mcp.resource' => 'https://shift.test/mcp/shift',
    ]);
    DB::purge('mysql');
    expect(DB::selectOne('select database() as name')->name)->toBe($database);
    if (Schema::connection('mysql')->getTables($database) !== []) {
        throw new RuntimeException('The isolated concurrency database must be empty.');
    }
    $this->artisan('migrate', ['--database' => 'mysql', '--force' => true])->assertSuccessful();
    $request = issueMcpRecoveryTokens();
    $config = collect([
        'app.key', 'app.env', 'app.url', 'database.default', 'database.connections.mysql',
        'passport.connection', 'passport.private_key', 'passport.public_key',
        'shift_mcp', 'cache.default', 'session.driver', 'mail.default', 'queue.default',
    ])->mapWithKeys(fn (string $key): array => [$key => config($key)])->all();
    $workers = [];

    try {
        for ($index = 0; $index < 2; $index++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/McpRefreshWorker.php')], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start the refresh worker.');
            }
            $workers[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 30);
            fwrite($pipes[0], json_encode(['config' => $config, 'request' => $request, 'hold_lock' => $index === 0], JSON_THROW_ON_ERROR)."\n");
        }
        foreach ($workers as [, $pipes]) {
            expect(trim((string) fgets($pipes[1])))->toBe('READY');
        }
        fwrite($workers[0][1][0], "GO\n");
        expect(trim((string) fgets($workers[0][1][1])))->toBe('LOCKED');
        fwrite($workers[1][1][0], "GO\n");
        expect(trim((string) fgets($workers[1][1][1])))->toBe('ATTEMPTING');
        fwrite($workers[0][1][0], "RELEASE\n");
        foreach ($workers as [, $pipes]) {
            fclose($pipes[0]);
        }
        $responses = [];
        foreach ($workers as [, $pipes]) {
            $responses[] = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
        }
        expect($responses[0]['status'])->toBe(200)
            ->and($responses[1]['status'])->toBe(200)
            ->and($responses[0]['access_hash'])->not->toBeNull()->toBe($responses[1]['access_hash'])
            ->and($responses[0]['refresh_hash'])->not->toBeNull()->toBe($responses[1]['refresh_hash']);
        $this->assertDatabaseCount('oauth_access_tokens', 2);
        $this->assertDatabaseCount('oauth_refresh_tokens', 2);
        $this->assertDatabaseCount('mcp_refresh_recoveries', 1);
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
        }
    }
});
