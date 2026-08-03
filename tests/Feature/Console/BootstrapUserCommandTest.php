<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('an initial verified user can be bootstrapped interactively', function () {
    $this->artisan('shift:bootstrap-user')
        ->expectsQuestion('Name', 'Initial User')
        ->expectsQuestion('Email address', 'INITIAL@example.com')
        ->expectsQuestion('Password', 'secure-password')
        ->expectsQuestion('Confirm password', 'secure-password')
        ->expectsOutput('The initial SHIFT user was created and marked as verified.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'initial@example.com')->firstOrFail();

    expect($user->name)->toBe('Initial User')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secure-password', $user->password))->toBeTrue();
});

test('the bootstrap command refuses to create a second user', function () {
    User::factory()->create();

    $this->artisan('shift:bootstrap-user')
        ->expectsOutput('A user already exists. This command only bootstraps an empty installation.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});
