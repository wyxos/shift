<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders real dashboard chart geometry, colors and axes through theme and data transitions', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create(['name' => 'Chart Regression Project']);

    foreach (['pending' => 'high', 'in-progress' => 'medium', 'completed' => 'low'] as $status => $priority) {
        $task = Task::factory()->for($project)->create(['status' => $status, 'priority' => $priority]);
        $task->metadata()->create(['environment' => $status === 'completed' ? 'staging' : 'production', 'url' => 'https://example.test']);
    }

    $this->actingAs($user);

    $page = visit('/dashboard')->resize(1440, 1000)
        ->assertSee('Weekly Throughput')
        ->assertSee('Priority Mix')
        ->assertSee('Chart Regression Project')
        ->assertScript('document.querySelectorAll("[data-slot=chart] svg").length === 5')
        ->assertScript(<<<'JS'
            (() => {
                const bars = [...document.querySelectorAll('[data-slot=chart] path[class$="-bar"]')];
                const segments = [...document.querySelectorAll('[data-slot=chart] path[class$="-segment"]')];
                const marks = [...bars, ...segments];
                return bars.some(mark => mark.getBBox().height > 0)
                    && segments.length === 5
                    && marks.every(mark => mark.getAttribute('d') && !/NaN|Infinity/.test(mark.getAttribute('d')))
                    && marks.every(mark => Number(getComputedStyle(mark).opacity) > 0.99)
                    && new Set(marks.map(mark => getComputedStyle(mark).fill)).size >= 3
                    && marks.every(mark => !['none', 'rgba(0, 0, 0, 0)'].includes(getComputedStyle(mark).fill));
            })()
        JS)
        ->assertScript('Array.from(document.querySelectorAll("[data-slot=chart] svg text")).some(label => label.textContent === "High")')
        ->assertNoSmoke()
        ->screenshot(filename: 'dashboard-charts-desktop');

    $lightColor = $page->script('getComputedStyle(document.querySelector("[data-slot=chart] path[class$=\"-segment\"]")).fill');
    $page->script('document.documentElement.classList.add("dark")');
    $page->assertScript('getComputedStyle(document.querySelector("[data-slot=chart] path[class$=-segment]")).fill !== '.json_encode($lightColor, JSON_THROW_ON_ERROR));

    $page->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript(<<<'JS'
            Array.from(document.querySelectorAll('[data-slot=chart] svg')).every(svg => {
                const bounds = svg.getBoundingClientRect();
                return bounds.width > 0 && bounds.height > 0;
            })
        JS)
        ->assertNoSmoke()
        ->screenshot(filename: 'dashboard-charts-mobile-dark');

    $page->resize(1440, 1000)
        ->assertScript('document.querySelector("[data-slot=chart] svg").getBoundingClientRect().width > 390')
        ->assertScript('Array.from(document.querySelectorAll("[data-slot=chart] path")).every(mark => !/NaN|Infinity/.test(mark.getAttribute("d") || ""))')
        ->assertNoSmoke();

    $project->tasks()->where('status', 'pending')->first()->update(['status' => 'completed']);
    $page->click('a[data-sidebar="menu-button"][href="/dashboard"]')->assertScript('document.querySelectorAll("[data-slot=chart] path[class$=\"-segment\"]").length === 4')
        ->assertScript('Array.from(document.querySelectorAll("[data-slot=chart] path")).every(mark => !/NaN|Infinity/.test(mark.getAttribute("d") || ""))')
        ->assertNoSmoke();
});

it('renders the empty dashboard without invalid chart geometry', function () {
    $user = User::factory()->create();
    Project::factory()->withAuthor($user->id)->create();
    $this->actingAs($user);

    visit('/dashboard')->resize(390, 844)
        ->assertSee('No status data yet.')
        ->assertSee('No project data yet.')
        ->assertSee('No environment data yet.')
        ->assertScript('document.querySelectorAll("path[class$=\"-segment\"]").length === 0')
        ->assertScript('Array.from(document.querySelectorAll("[data-slot=chart] path")).every(mark => !/NaN|Infinity/.test(mark.getAttribute("d") || ""))')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
});
