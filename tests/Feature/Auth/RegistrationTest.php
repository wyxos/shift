<?php

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register and must verify their email', function () {
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'TEST@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->firstOrFail();

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->get('/dashboard')
        ->assertRedirect(route('verification.notice', absolute: false));
});

test('invited organisation users can register and must verify their email', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organisation = Organisation::factory()->create([
        'author_id' => $owner->id,
    ]);
    $organisationUser = OrganisationUser::create([
        'organisation_id' => $organisation->id,
        'user_id' => null,
        'user_email' => 'org-invited@example.com',
        'user_name' => 'Org Invited',
    ]);

    $response = $this->post('/register', [
        'name' => 'Org Invited',
        'email' => 'ORG-INVITED@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'organisation_id' => $organisation->id,
    ]);

    $registeredUser = User::where('email', 'org-invited@example.com')->firstOrFail();

    $response->assertRedirect(route('organisations.index', ['highlight' => $organisation->id], absolute: false));
    $this->assertAuthenticatedAs($registeredUser);
    expect($registeredUser->email_verified_at)->toBeNull();

    $this->assertDatabaseHas('organisation_users', [
        'id' => $organisationUser->id,
        'user_id' => $registeredUser->id,
    ]);

    Notification::assertSentTo($registeredUser, VerifyEmail::class);

    $this->get('/organisations')
        ->assertRedirect(route('verification.notice', absolute: false));
});
