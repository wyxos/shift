<?php

use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectUser;

test('project users are backfilled into organisation users without duplicates', function () {
    $directOrganisation = Organisation::factory()->create();
    $directProject = Project::factory()->create([
        'client_id' => null,
        'organisation_id' => $directOrganisation->id,
    ]);

    ProjectUser::create([
        'project_id' => $directProject->id,
        'user_id' => null,
        'user_email' => 'pending@example.com',
        'user_name' => 'Pending User',
        'registration_status' => 'pending',
    ]);

    $clientOrganisation = Organisation::factory()->create();
    $client = Client::factory()->create([
        'organisation_id' => $clientOrganisation->id,
    ]);
    $clientProject = Project::factory()->create([
        'client_id' => $client->id,
        'organisation_id' => null,
    ]);

    ProjectUser::create([
        'project_id' => $clientProject->id,
        'user_id' => 1234,
        'user_email' => 'registered@example.com',
        'user_name' => 'Registered User',
        'registration_status' => 'registered',
    ]);

    $migration = require database_path('migrations/2026_06_02_120000_backfill_organisation_users_from_project_users.php');

    $migration->up();
    $migration->up();

    $this->assertDatabaseCount('organisation_users', 2);
    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $directOrganisation->id,
        'user_id' => null,
        'user_email' => 'pending@example.com',
        'user_name' => 'Pending User',
    ]);
    $this->assertDatabaseHas('organisation_users', [
        'organisation_id' => $clientOrganisation->id,
        'user_id' => 1234,
        'user_email' => 'registered@example.com',
        'user_name' => 'Registered User',
    ]);
});
