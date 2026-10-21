<?php

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('only offers reset for applied or draft changes across list filters', function (string $list, int $width, string $motion) {
    $user = User::factory()->create(['name' => 'Alex Taylor', 'email' => '1@example.test']);
    $organisation = Organisation::factory()->create(['author_id' => $user->id, 'name' => 'Demo organisation']);
    $this->actingAs($user);
    $path = match ($list) {
        'projects', 'clients', 'project-participants' => "/organisation/{$organisation->id}/{$list}",
        default => '/'.$list,
    };

    $page = visit($path, ['reducedMotion' => $motion])->resize($width, 900)
        ->click('[data-testid="filters-trigger"]')
        ->assertVisible('[data-testid="filter-search"]')
        ->assertNotPresent('[data-testid="filters-reset"]')
        ->assertVisible('[data-testid="filters-apply"]')
        ->assertScript('document.querySelector("[data-slot=sheet-content]").getAnimations().every(animation => animation.playState === "finished")')
        ->assertScript('(window.applyLeft = document.querySelector("[data-testid=filters-apply]").getBoundingClientRect().left) > 0')
        ->fill('[data-testid="filter-search"]', 'Draft search')
        ->assertVisible('[data-testid="filters-reset"]')
        ->assertScript('document.querySelector("[data-testid=filters-apply]").getBoundingClientRect().left === window.applyLeft')
        ->fill('[data-testid="filter-search"]', '')
        ->assertNotPresent('[data-testid="filters-reset"]')
        ->fill('[data-testid="filter-search"]', 'Applied search')
        ->click('[data-testid="filters-apply"]')
        ->assertNotPresent('[data-testid="filter-search"]')
        ->click('[data-testid="filters-trigger"]')
        ->assertVisible('[data-testid="filters-reset"]')
        ->fill('[data-testid="filter-search"]', '')
        ->assertVisible('[data-testid="filters-reset"]')
        ->click('[data-testid="filters-reset"]')
        ->assertNotPresent('[data-testid="filter-search"]')
        ->click('[data-testid="filters-trigger"]')
        ->assertValue('[data-testid="filter-search"]', '')
        ->assertNotPresent('[data-testid="filters-reset"]')
        ->assertScript('document.documentElement.scrollWidth <= innerWidth')
        ->assertNoSmoke();

    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (is_string($destination) && $destination !== '') {
        $name = "list-filters-{$list}-{$width}.png";
        $page->screenshot(false, $name);
        @mkdir($destination, 0777, true);
        copy(__DIR__.'/Screenshots/'.$name, rtrim($destination, '/').'/'.$name);
    }
})->with(['projects', 'clients', 'organisations', 'project-participants', 'tasks', 'error-reports', 'requirements'])
    ->with([[1440, 'no-preference'], [390, 'reduce']]);
