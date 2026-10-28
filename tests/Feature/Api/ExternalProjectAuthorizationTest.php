<?php

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    Queue::fake();

    $owner = User::factory()->create(['email' => '1@example.com']);
    $this->integrationUser = User::factory()->create(['email' => '2@example.com']);
    $this->integrationToken = $this->integrationUser->createToken('integration')->plainTextToken;
    $this->protectedProject = Project::factory()->create([
        'author_id' => $owner->id,
        'client_id' => null,
        'organisation_id' => null,
        'token' => 'protected-project-token',
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);
    $environment = $this->protectedProject->environments()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
        'callback_trusted_at' => now(),
        'external_widget_enabled' => true,
        'external_widget_guest_submissions_enabled' => false,
    ]);
    $this->protectedIdentity = ExternalIdentity::query()->create([
        'project_id' => $this->protectedProject->id,
        'project_environment_id' => $environment->id,
        'external_id' => 'client-owner',
        'name' => 'Original client owner',
        'email' => '3@example.com',
        'environment' => 'testing',
        'url' => 'https://example.com',
        'role' => 'owner',
    ]);
    $this->protectedRequirement = Task::factory()->create([
        'project_id' => $this->protectedProject->id,
        'title' => 'Confidential requirement',
    ]);
    $this->protectedRequirement->submitter()->associate($this->protectedIdentity)->save();
    $this->protectedRequirement->metadata()->create([
        'environment' => 'testing',
        'url' => 'https://example.com',
        'phase' => 'requirement',
        'source' => 'embedded_requirement_pack',
        'intake_type' => 'requirement',
        'requirement_status' => 'submitted',
        'submitted_title' => 'Confidential requirement',
        'submitted_description' => 'Private project details.',
    ]);
    $this->integrationPayload = [
        'project' => $this->protectedProject->token,
        'environment' => 'testing',
        'user' => [
            'id' => 'client-owner',
            'name' => 'Claimed client owner',
            'email' => '3@example.com',
            'environment' => 'testing',
            'url' => 'https://example.com',
            'authenticated' => true,
        ],
        'metadata' => ['environment' => 'testing', 'url' => 'https://example.com'],
        'title' => 'Injected project content',
        'description' => 'A synthetic security check.',
        'kind' => 'issue',
        'anonymous' => false,
        'items' => [['title' => 'Injected requirement', 'description' => 'A synthetic security check.']],
    ];
    $this->grantIntegrationAccess = fn () => ProjectUser::query()->create([
        'project_id' => $this->protectedProject->id,
        'user_id' => $this->integrationUser->id,
        'user_email' => $this->integrationUser->email,
        'user_name' => $this->integrationUser->name,
        'registration_status' => 'registered',
    ]);
});

dataset('protected external project endpoints', [
    'requirements list' => ['GET', '/api/requirements'],
    'requirement batch submission' => ['POST', '/api/requirements/batches'],
    'widget configuration' => ['GET', '/api/widget/config'],
    'widget submission' => ['POST', '/api/widget/tasks'],
]);

test('external endpoints hide a known project from an outsider or revoked member', function (string $method, string $path, bool $revoked) {
    if ($revoked) {
        $membership = ($this->grantIntegrationAccess)();
        expect($this->protectedProject->isVisibleToUser($this->integrationUser->id))->toBeTrue();
        $membership->delete();
    }

    expect($this->protectedProject->isVisibleToUser($this->integrationUser->id))->toBeFalse();

    $this->withToken($this->integrationToken)
        ->json($method, $path, $this->integrationPayload)
        ->assertNotFound();

    expect(Task::query()->count())->toBe(1)
        ->and($this->protectedIdentity->fresh()->name)->toBe('Original client owner');
    $this->assertDatabaseCount('requirement_batches', 0);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
})->with('protected external project endpoints')->with(['outsider' => false, 'revoked member' => true]);

test('external endpoints accept a current project integration member', function (string $method, string $path) {
    ($this->grantIntegrationAccess)();

    $response = $this->withToken($this->integrationToken)
        ->json($method, $path, $this->integrationPayload)
        ->assertSuccessful();

    if ($path === '/api/requirements') {
        $response->assertJsonPath('data.0.id', $this->protectedRequirement->id);
    }
})->with('protected external project endpoints');
