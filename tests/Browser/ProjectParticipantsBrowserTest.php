<?php

use App\Models\ExternalIdentity;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function captureProjectParticipantsReview($page, string $name): void
{
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (! is_string($destination) || $destination === '') {
        return;
    }

    $page->screenshot(false, $name);
    @mkdir($destination, 0777, true);
    copy(__DIR__.'/Screenshots/'.$name, rtrim($destination, '/').'/'.$name);
}

it('shows application accounts and explains participant linking at desktop and mobile widths', function () {
    $manager = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $manager->id]);
    $project = Project::factory()->create([
        'author_id' => $manager->id,
        'organisation_id' => $organisation->id,
        'name' => 'Voidcare',
    ]);
    $participant = ProjectParticipant::create(['project_id' => $project->id]);
    $production = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'project_participant_id' => $participant->id,
        'name' => 'David Production',
        'email' => '1@example.test',
        'environment' => 'Production',
        'url' => 'https://voidcare.example.test',
    ]);
    $sandbox = ExternalIdentity::factory()->create([
        'project_id' => $project->id,
        'project_participant_id' => $participant->id,
        'name' => 'David Sandbox',
        'email' => '2@example.test',
        'environment' => 'Sandbox',
        'url' => 'https://sandbox.voidcare.example.test',
    ]);
    $this->actingAs($manager);

    $page = visit("/organisation/{$organisation->id}/project-participants")
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertSee('Project participants')
        ->assertSee('Application account')
        ->assertSee('David Production')
        ->hover("[data-testid=external-identity-row-{$production->id}]")
        ->click("[data-testid=external-identity-edit-{$production->id}]")
        ->assertSee('Edit application account')
        ->assertSee('Linking joins both accounts’ existing groups')
        ->assertSee('original ticket ownership stays with its account')
        ->assertVisible("[data-testid=external-identity-linked-account-{$sandbox->id}]")
        ->assertNoSmoke();
    captureProjectParticipantsReview($page, 'project-participants-desktop.png');

    $page->hover("[data-testid=external-identity-linked-account-{$sandbox->id}]")
        ->click("[data-testid=external-identity-linked-account-unlink-{$sandbox->id}]")
        ->waitForText('No linked accounts yet.')
        ->assertNoSmoke();
    expect($sandbox->fresh()->project_participant_id)->not->toBe($production->fresh()->project_participant_id);

    $page->click('[data-testid=external-identity-link-account-select]')
        ->click("[data-testid=external-identity-link-account-select-option-{$sandbox->id}]")
        ->click('[data-testid=external-identity-link-account-submit]')
        ->assertVisible("[data-testid=external-identity-linked-account-{$sandbox->id}]")
        ->assertNoSmoke();
    expect($sandbox->fresh()->project_participant_id)->toBe($production->fresh()->project_participant_id);

    $page->resize(390, 844)
        ->assertSee('Edit application account')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
    captureProjectParticipantsReview($page, 'project-participants-mobile.png');
});
