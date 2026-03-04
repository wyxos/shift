<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('redirects legacy create route to the tasks list', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/tasks/create')
        ->assertPathIs('/tasks')
        ->assertSee('Tasks');
});
