<?php

use App\Models\User;

test('unverified users cannot manage api tokens', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('api.edit', absolute: false))
        ->assertRedirect(route('verification.notice', absolute: false));

    $this->actingAs($user)
        ->put(route('api.update', absolute: false), [
            'name' => 'Unverified token',
        ])
        ->assertRedirect(route('verification.notice', absolute: false));
});

test('verified users can create api tokens', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('api.update', absolute: false), [
            'name' => 'Verified token',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($user->tokens()->where('name', 'Verified token')->exists())->toBeTrue();
});
