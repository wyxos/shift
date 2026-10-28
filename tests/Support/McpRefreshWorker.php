<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config($input['config']);
DB::purge();

fwrite(STDOUT, "READY\n");
if (trim(fgets(STDIN)) !== 'GO') {
    exit(1);
}

if ($input['hold_lock']) {
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query): void {
        if (str_contains($query->sql, '`oauth_clients`') && str_contains($query->sql, 'for update')) {
            fwrite(STDOUT, "LOCKED\n");
            if (trim(fgets(STDIN)) !== 'RELEASE') {
                throw new RuntimeException('Refresh lock barrier was not released.');
            }
        }
    });
} else {
    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_contains($query, '`oauth_clients`') && str_contains($query, 'for update')) {
            fwrite(STDOUT, "ATTEMPTING\n");
        }
    });
}

$request = Request::create('https://shift.test/oauth/token', 'POST', $input['request'], server: [
    'HTTP_ACCEPT' => 'application/json',
]);
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
$payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'access_hash' => isset($payload['access_token']) ? hash('sha256', $payload['access_token']) : null,
    'refresh_hash' => isset($payload['refresh_token']) ? hash('sha256', $payload['refresh_token']) : null,
    'error' => $payload['error'] ?? null,
], JSON_THROW_ON_ERROR)."\n");
$kernel->terminate($request, $response);
