<?php

use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

final class ErrorAlertSettingsBrowserFailureMiddleware
{
    public static ?int $projectId = null;

    public static bool $failNextSave = true;

    public function handle(Request $request, Closure $next): mixed
    {
        if (self::$failNextSave && self::$projectId !== null
            && $request->isMethod('PUT')
            && $request->path() === 'projects/'.self::$projectId.'/app-error-notifications') {
            self::$failNextSave = false;

            return new JsonResponse(['message' => 'Controlled settings save failure'], 503);
        }

        return $next($request);
    }
}

it('edits error alert rules and retains the draft after a failed save', function (int $width, string $motion) {
    $user = User::factory()->create(['name' => 'Alert Reviewer', 'email' => '1@example.com']);
    $organisation = Organisation::factory()->create(['name' => 'Alert review organisation', 'author_id' => $user->id]);
    $project = Project::factory()->withAuthor($user->id)->create(['name' => 'Alert review project', 'organisation_id' => $organisation->id, 'client_id' => null]);
    ErrorAlertSettingsBrowserFailureMiddleware::$projectId = $project->id;
    ErrorAlertSettingsBrowserFailureMiddleware::$failNextSave = true;
    Route::prependMiddlewareToGroup('web', ErrorAlertSettingsBrowserFailureMiddleware::class);
    $this->actingAs($user);

    $action = $width < 1024 ? "project-compact-app-error-notifications-{$project->id}" : "project-app-error-notifications-{$project->id}";
    $page = visit("/organisation/{$organisation->id}/projects", ['reducedMotion' => $motion])->resize($width, 900)
        ->assertSee('Alert review project')
        ->click("[data-testid={$action}]")
        ->assertSee('Repeat alerts for open errors')
        ->assertSee('Alert cooldown')
        ->assertSee('Repeated events do not restart the cooldown.')
        ->assertSee('Measured from the last alert for each error.')
        ->assertSee('Default: 1 hour.')
        ->assertValue('[data-testid=project-error-quiet-hours]', '1')
        ->fill('[data-testid=project-error-quiet-hours]', '0')
        ->assertSee('Choose a whole number between 1 and 168 hours.')
        ->assertDisabled('[data-testid=save-app-error-notifications]')
        ->fill('[data-testid=project-error-quiet-hours]', '3')
        ->fill('[data-testid=project-error-summary-time]', '10:30')
        ->keys('[data-testid=project-error-quiet-hours]', 'Tab')
        ->assertScript('document.activeElement.id === "error-summary-enabled"')
        ->click('[data-testid=project-error-summary-timezone]')
        ->fill('[data-testid=project-error-summary-timezone-search]', 'Dubai')
        ->keys('[data-testid=project-error-summary-timezone-search]', 'Tab')
        ->assertScript('document.activeElement.dataset.testid === "project-error-summary-timezone-option-Asia/Dubai"')
        ->keys('[data-testid="project-error-summary-timezone-option-Asia/Dubai"]', 'Enter')
        ->click("[data-testid=project-app-error-notification-user-{$user->id}-checkbox]")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript('document.querySelector("[data-testid=save-app-error-notifications]").getBoundingClientRect().bottom <= window.innerHeight')
        ->assertNoJavascriptErrors();

    $page->script('document.querySelector("[data-testid=project-error-alert-settings] [aria-busy]").scrollTop = 0');
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (is_string($destination) && $destination !== '') {
        $filename = "shift-error-alert-settings-{$width}.png";
        $page->screenshot(false, $filename);
        @mkdir($destination, 0777, true);
        copy(__DIR__.'/Screenshots/'.$filename, rtrim($destination, '/').'/'.$filename);
    }

    $page->click('[data-testid=save-app-error-notifications]')
        ->assertSee('Unable to save notification settings right now. Your changes are kept here.')
        ->assertValue('[data-testid=project-error-quiet-hours]', '3')
        ->assertSee('1 selected')
        ->click('[data-testid=save-app-error-notifications]')
        ->assertNotPresent('[data-testid=project-error-alert-settings]')
        ->click("[data-testid={$action}]")
        ->assertValue('[data-testid=project-error-quiet-hours]', '3')
        ->assertValue('[data-testid=project-error-summary-time]', '10:30')
        ->assertSee('Asia/Dubai')
        ->assertSee('1 selected')
        ->fill('[data-testid=project-error-quiet-hours]', '5')
        ->click('Cancel')
        ->assertNotPresent('[data-testid=project-error-alert-settings]')
        ->click("[data-testid={$action}]")
        ->assertValue('[data-testid=project-error-quiet-hours]', '3')
        ->assertNoJavascriptErrors();

    expect($project->refresh()->app_error_quiet_hours)->toBe(3)
        ->and($project->app_error_daily_summary_timezone)->toBe('Asia/Dubai');
})->with([
    'desktop' => [1440, 'no-preference'],
    'tablet' => [768, 'no-preference'],
    'phone and reduced motion' => [390, 'reduce'],
]);
