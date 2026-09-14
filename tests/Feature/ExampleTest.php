<?php

use App\Models\User;

test('home redirects guests to login', function () {
    $this->get(route('home'))->assertRedirectToRoute('login');
});

test('home redirects signed-in users to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirectToRoute('dashboard');
});
