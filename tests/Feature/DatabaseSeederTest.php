<?php

use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\PersonalAccessToken;

test('development seeding preserves the local fixtures', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    $this->assertDatabaseHas('projects', [
        'token' => 'zgc5QC5M1hGNmH7qbRSzEn29CBWfOtIPQT6pfM9FdUzfj0Ai6DmeGLcmGQ7s',
    ]);
    $this->assertDatabaseHas('personal_access_tokens', [
        'id' => 1,
        'name' => 'shift-sdk',
    ]);
});

test('production seeding creates no development users projects or tokens', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    try {
        app(DatabaseSeeder::class)->run();
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }

    expect(User::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});
