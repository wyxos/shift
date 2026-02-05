<?php

;

test('registration screen is disabled', function () {
    $response = $this->get('/register');

    $response->assertStatus(404);
});

test('new users cannot register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertStatus(404);
    $this->assertGuest();
});
