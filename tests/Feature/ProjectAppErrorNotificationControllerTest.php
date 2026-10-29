<?php

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;

it('shows eligible visible internal users and selected app error notification recipients', function () {
    $owner = User::factory()->create(['name' => 'Avery Owner']);
    $technicalManager = User::factory()->create(['name' => 'Drew Developer']);
    $recipient = User::factory()->create(['name' => 'Mira Recipient']);
    $outsider = User::factory()->create(['name' => 'Zoe Outsider']);
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);

    OrganisationUser::query()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $technicalManager->id,
        'user_email' => $technicalManager->email,
        'user_name' => $technicalManager->name,
        'role' => OrganisationRole::Developer->value,
    ]);
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $technicalManager->id,
        'user_email' => $technicalManager->email,
        'user_name' => $technicalManager->name,
        'registration_status' => 'registered',
    ]);
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $recipient->id,
        'user_email' => $recipient->email,
        'user_name' => $recipient->name,
        'registration_status' => 'registered',
    ]);

    $project->appErrorNotificationUsers()->attach($recipient->id);

    $response = $this->actingAs($technicalManager)
        ->getJson(route('projects.app-error-notifications.show', $project));

    $response
        ->assertOk()
        ->assertJsonPath('project_id', $project->id)
        ->assertJsonCount(3, 'users')
        ->assertJsonPath('selected_user_ids', [$recipient->id])
        ->assertJsonFragment([
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
        ])
        ->assertJsonFragment([
            'id' => $technicalManager->id,
            'name' => $technicalManager->name,
            'email' => $technicalManager->email,
        ])
        ->assertJsonFragment([
            'id' => $recipient->id,
            'name' => $recipient->name,
            'email' => $recipient->email,
        ])
        ->assertJsonMissing([
            'id' => $outsider->id,
            'name' => $outsider->name,
            'email' => $outsider->email,
        ]);
});

it('syncs selected recipients and rejects users without project visibility', function () {
    $owner = User::factory()->create();
    $technicalManager = User::factory()->create();
    $recipient = User::factory()->create();
    $removedRecipient = User::factory()->create();
    $outsider = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);

    OrganisationUser::query()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $technicalManager->id,
        'user_email' => $technicalManager->email,
        'user_name' => $technicalManager->name,
        'role' => OrganisationRole::Developer->value,
    ]);
    foreach ([$technicalManager, $recipient, $removedRecipient] as $user) {
        ProjectUser::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
            'registration_status' => 'registered',
        ]);
    }

    $project->appErrorNotificationUsers()->attach($removedRecipient->id);

    $this->actingAs($technicalManager)
        ->putJson(route('projects.app-error-notifications.update', $project), [
            'user_ids' => [$recipient->id, $owner->id],
        ])
        ->assertOk()
        ->assertJsonPath('project_id', $project->id)
        ->assertJsonPath('selected_user_ids', [$owner->id, $recipient->id]);

    $this->assertDatabaseHas('project_app_error_notification_users', [
        'project_id' => $project->id,
        'user_id' => $owner->id,
    ]);
    $this->assertDatabaseHas('project_app_error_notification_users', [
        'project_id' => $project->id,
        'user_id' => $recipient->id,
    ]);
    $this->assertDatabaseMissing('project_app_error_notification_users', [
        'project_id' => $project->id,
        'user_id' => $removedRecipient->id,
    ]);

    $this->actingAs($technicalManager)
        ->putJson(route('projects.app-error-notifications.update', $project), [
            'user_ids' => [$outsider->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_ids');

    $this->actingAs($technicalManager)
        ->putJson(route('projects.app-error-notifications.update', $project), [
            'user_ids' => [0],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_ids.0');

    $this->actingAs($technicalManager)
        ->putJson(route('projects.app-error-notifications.update', $project), [
            'user_ids' => [],
        ])
        ->assertOk()
        ->assertJsonPath('selected_user_ids', []);

    $this->assertDatabaseMissing('project_app_error_notification_users', [
        'project_id' => $project->id,
        'user_id' => $owner->id,
    ]);
    $this->assertDatabaseMissing('project_app_error_notification_users', [
        'project_id' => $project->id,
        'user_id' => $recipient->id,
    ]);
});

it('forbids project members who cannot manage technical settings', function () {
    $owner = User::factory()->create();
    $projectManager = User::factory()->create();
    $recipient = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $owner->id]);
    $project = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);

    OrganisationUser::query()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $projectManager->id,
        'user_email' => $projectManager->email,
        'user_name' => $projectManager->name,
        'role' => OrganisationRole::ClientProjectManager->value,
    ]);
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $projectManager->id,
        'user_email' => $projectManager->email,
        'user_name' => $projectManager->name,
        'registration_status' => 'registered',
    ]);
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $recipient->id,
        'user_email' => $recipient->email,
        'user_name' => $recipient->name,
        'registration_status' => 'registered',
    ]);

    $this->actingAs($projectManager)
        ->getJson(route('projects.app-error-notifications.show', $project))
        ->assertForbidden();

    $this->actingAs($projectManager)
        ->putJson(route('projects.app-error-notifications.update', $project), [
            'user_ids' => [$recipient->id],
        ])
        ->assertForbidden();
});

it('returns the default one hour quiet period and daily summary settings', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();

    $this->actingAs($owner)
        ->getJson(route('projects.app-error-notifications.show', $project))
        ->assertOk()
        ->assertJsonPath('settings', [
            'recurrence_enabled' => true,
            'quiet_hours' => 1,
            'daily_summary_enabled' => true,
            'daily_summary_time' => '09:00',
            'daily_summary_timezone' => 'UTC',
        ]);
});

it('saves alert rules with recipients and preserves omitted rules for old clients', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();
    $settings = [
        'recurrence_enabled' => false,
        'quiet_hours' => 3,
        'daily_summary_enabled' => false,
        'daily_summary_time' => '15:45',
        'daily_summary_timezone' => 'Asia/Dubai',
    ];

    $this->actingAs($owner)->putJson(route('projects.app-error-notifications.update', $project), [
        'user_ids' => [$owner->id],
        'settings' => $settings,
    ])->assertOk()->assertJsonPath('settings', $settings);

    $this->putJson(route('projects.app-error-notifications.update', $project), [
        'user_ids' => [],
    ])->assertOk()->assertJsonPath('settings', $settings)->assertJsonPath('selected_user_ids', []);

    expect($project->refresh()->app_error_quiet_hours)->toBe(3)
        ->and($project->app_error_daily_summary_timezone)->toBe('Asia/Dubai');
});

it('rejects invalid alert settings without changing recipients or saved rules', function (array $settings, string $field) {
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();
    $project->appErrorNotificationUsers()->attach($owner->id);

    $this->actingAs($owner)->putJson(route('projects.app-error-notifications.update', $project), [
        'user_ids' => [],
        'settings' => $settings,
    ])->assertUnprocessable()->assertJsonValidationErrors('settings.'.$field);

    expect($project->refresh()->app_error_quiet_hours)->toBe(1)
        ->and($project->appErrorNotificationUsers()->pluck('users.id')->all())->toBe([$owner->id]);
})->with([
    'zero hours' => [['quiet_hours' => 0], 'quiet_hours'],
    'too many hours' => [['quiet_hours' => 169], 'quiet_hours'],
    'fractional hours' => [['quiet_hours' => 1.5], 'quiet_hours'],
    'invalid time' => [['daily_summary_time' => '24:00'], 'daily_summary_time'],
    'invalid time zone' => [['daily_summary_timezone' => 'Mars/Olympus'], 'daily_summary_timezone'],
    'invalid recurrence flag' => [['recurrence_enabled' => 'yes'], 'recurrence_enabled'],
]);

it('retains saved settings and returns a warning when summary scheduling fails', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();
    $this->mock(\App\Services\AppErrors\AppErrorSummaryScheduler::class)
        ->shouldReceive('schedule')->once()->andThrow(new RuntimeException('Queue unavailable'));

    $this->actingAs($owner)->putJson(route('projects.app-error-notifications.update', $project), [
        'user_ids' => [$owner->id],
        'settings' => ['quiet_hours' => 4],
    ])->assertOk()
        ->assertJsonPath('settings.quiet_hours', 4)
        ->assertJsonPath('scheduling_warning', 'Settings saved, but the daily summary could not be scheduled. Try saving again.');

    expect($project->refresh()->app_error_quiet_hours)->toBe(4)
        ->and($project->appErrorNotificationUsers()->pluck('users.id')->all())->toBe([$owner->id]);
});
