<?php

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function captureStandaloneProjectReview($page, string $name): void
{
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (! is_string($destination) || $destination === '') {
        return;
    }
    $page->screenshot(false, $name);
    @mkdir($destination, 0777, true);
    copy(__DIR__.'/Screenshots/'.$name, rtrim($destination, '/').'/'.$name);
}

it('creates renames and shares a project without selecting an organisation', function (int $width, string $motion) {
    Notification::fake();
    $owner = User::factory()->create(['name' => 'Project owner', 'email' => '1@example.com']);
    $member = User::factory()->create(['name' => 'Invited member', 'email' => '2@example.com']);
    Organisation::factory()->create(['author_id' => $owner->id, 'name' => 'Optional organisation']);
    $this->actingAs($owner);

    $pending = visit('/projects', ['reducedMotion' => $motion]);
    if ($width < 768) {
        $pending = $pending->on()->mobile();
    }
    $page = $pending->resize($width, 900)
        ->assertSee('No projects found.')
        ->click('[data-testid=open-create-project]')
        ->assertSee('Create a standalone project')
        ->assertSee('No organisation')
        ->assertSee('No client')
        ->fill('[data-testid=create-project-name]', 'Independent workspace')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
    captureStandaloneProjectReview($page, "standalone-create-{$width}.png");
    $page->click('[data-testid=create-project-submit]')
        ->assertSee('Independent workspace')->assertSee('Standalone')->assertNoSmoke();
    captureStandaloneProjectReview($page, "standalone-created-{$width}.png");
    $project = Project::query()->where('name', 'Independent workspace')->sole();
    expect($project->organisation_id)->toBeNull()->and($project->client_id)->toBeNull();
    $prefix = $width < 1024 ? 'project-compact' : 'project';
    if ($width >= 768) {
        $page->hover("[data-testid={$prefix}-row-{$project->id}]");
    }
    $page->click("[data-testid={$prefix}-edit-{$project->id}]")
        ->fill('[data-testid=edit-project-name]', 'Independent project')
        ->click('[data-testid=edit-project-submit]')->assertSee('Independent project');
    captureStandaloneProjectReview($page, "standalone-renamed-{$width}.png");
    $page->assertScript("(() => { const button = document.querySelector('[data-testid={$prefix}-manage-{$project->id}]'); const rect = button.getBoundingClientRect(); return document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2)?.closest('button') === button; })()");
    if ($width >= 768) {
        $page->hover("[data-testid={$prefix}-row-{$project->id}]");
    }
    $page->click("[data-testid={$prefix}-manage-{$project->id}]");
    captureStandaloneProjectReview($page, "standalone-access-form-{$width}.png");
    $page->fill('[data-testid=project-access-email]', $member->email)
        ->click('[data-testid=project-access-submit]')->assertSee($member->email)
        ->assertNoSmoke();
    captureStandaloneProjectReview($page, "standalone-access-{$width}.png");
    $membership = ProjectUser::query()->where('project_id', $project->id)->sole();
    expect($membership->user_id)->toBe($member->id)->and(OrganisationUser::query()->count())->toBe(0);
    $page->click('Close')->assertSee('Independent project')->assertNoSmoke();
    captureStandaloneProjectReview($page, "standalone-projects-{$width}.png");
    if ($width >= 768) {
        $page->hover("[data-testid={$prefix}-row-{$project->id}]");
    }
    $page->click("[data-testid={$prefix}-project-participants-{$project->id}]")
        ->assertSee('Project participants')
        ->click('[data-testid=filters-trigger]')
        ->fill('[data-testid=filter-search]', 'Nobody')
        ->click('[data-testid=filters-apply]')
        ->assertSee('Project participants')->assertNoSmoke();
})->with(['desktop' => [1440, 'no-preference'], 'tablet' => [768, 'no-preference'], 'phone' => [390, 'reduce']]);

it('shows a shared standalone project to its invited member without management controls', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Invited member', 'email' => '2@example.com']);
    $project = Project::factory()->create([
        'author_id' => $owner->id, 'client_id' => null, 'organisation_id' => null, 'name' => 'Shared independent project',
    ]);
    ProjectUser::factory()->create([
        'project_id' => $project->id, 'user_id' => $member->id,
        'user_email' => $member->email, 'user_name' => $member->name, 'registration_status' => 'registered',
    ]);
    $this->actingAs($member);
    $page = visit('/projects')->resize(1440, 900)->assertSee('Shared independent project')
        ->assertSee('Shared')->assertNotPresent("[data-testid=project-manage-{$project->id}]")
        ->assertNotPresent("[data-testid=project-edit-{$project->id}]")
        ->assertNotPresent("[data-testid=project-token-{$project->id}]")->assertNoSmoke();
    captureStandaloneProjectReview($page, 'standalone-member-desktop.png');
    $page->resize(390, 844)->assertSee('Shared independent project')->assertSee('Shared')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')->assertNoSmoke();
    captureStandaloneProjectReview($page, 'standalone-member-mobile.png');
});
