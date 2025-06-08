<?php

namespace Tests\Feature;

use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_only_see_external_users_for_owned_projects()
    {
        // Create a project owned by the authenticated user
        $ownedProject = Project::factory()->create([
            'author_id' => $this->user->id
        ]);

        // Create an external user for the owned project
        $ownedExternalUser = ExternalUser::factory()->create([
            'project_id' => $ownedProject->id,
            'name' => 'Owned External User',
            'email' => 'owned@example.com'
        ]);

        // Create another project not owned by the authenticated user
        $otherUser = User::factory()->create();
        $otherProject = Project::factory()->create([
            'author_id' => $otherUser->id
        ]);

        // Create an external user for the other project
        $otherExternalUser = ExternalUser::factory()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other External User',
            'email' => 'other@example.com'
        ]);

        // Access the external users index page as the authenticated user
        $response = $this->actingAs($this->user)
            ->get(route('external-users.index'));

        $response->assertStatus(200);

        // Assert that the owned external user is visible
        $response->assertSee('Owned External User');
        $response->assertSee('owned@example.com');

        // Assert that the other external user is not visible
        $response->assertDontSee('Other External User');
        $response->assertDontSee('other@example.com');
    }

    public function test_user_can_only_see_external_users_for_projects_with_access()
    {
        // Create a project not owned by the authenticated user
        $otherUser = User::factory()->create();
        $accessibleProject = Project::factory()->create([
            'author_id' => $otherUser->id
        ]);

        // Give the authenticated user access to the project
        ProjectUser::factory()->create([
            'project_id' => $accessibleProject->id,
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'user_name' => $this->user->name,
            'registration_status' => 'registered'
        ]);

        // Create an external user for the accessible project
        $accessibleExternalUser = ExternalUser::factory()->create([
            'project_id' => $accessibleProject->id,
            'name' => 'Accessible External User',
            'email' => 'accessible@example.com'
        ]);

        // Create another project not owned by the authenticated user and without access
        $inaccessibleProject = Project::factory()->create([
            'author_id' => $otherUser->id
        ]);

        // Create an external user for the inaccessible project
        $inaccessibleExternalUser = ExternalUser::factory()->create([
            'project_id' => $inaccessibleProject->id,
            'name' => 'Inaccessible External User',
            'email' => 'inaccessible@example.com'
        ]);

        // Access the external users index page as the authenticated user
        $response = $this->actingAs($this->user)
            ->get(route('external-users.index'));

        $response->assertStatus(200);

        // Assert that the accessible external user is visible
        $response->assertSee('Accessible External User');
        $response->assertSee('accessible@example.com');

        // Assert that the inaccessible external user is not visible
        $response->assertDontSee('Inaccessible External User');
        $response->assertDontSee('inaccessible@example.com');
    }

    public function test_user_cannot_edit_external_user_for_inaccessible_project()
    {
        // Create a project not owned by the authenticated user
        $otherUser = User::factory()->create();
        $inaccessibleProject = Project::factory()->create([
            'author_id' => $otherUser->id
        ]);

        // Create an external user for the inaccessible project
        $inaccessibleExternalUser = ExternalUser::factory()->create([
            'project_id' => $inaccessibleProject->id,
            'name' => 'Inaccessible External User',
            'email' => 'inaccessible@example.com'
        ]);

        // Try to access the edit page for the inaccessible external user
        $response = $this->actingAs($this->user)
            ->get(route('external-users.edit', $inaccessibleExternalUser->id));

        // Should get a 404 since the user doesn't have access to this external user
        $response->assertStatus(404);
    }

    public function test_user_cannot_update_external_user_for_inaccessible_project()
    {
        // Create a project not owned by the authenticated user
        $otherUser = User::factory()->create();
        $inaccessibleProject = Project::factory()->create([
            'author_id' => $otherUser->id
        ]);

        // Create an external user for the inaccessible project
        $inaccessibleExternalUser = ExternalUser::factory()->create([
            'project_id' => $inaccessibleProject->id,
            'name' => 'Inaccessible External User',
            'email' => 'inaccessible@example.com'
        ]);

        // Try to update the inaccessible external user
        $response = $this->actingAs($this->user)
            ->put(route('external-users.update', $inaccessibleExternalUser->id), [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
                'project_id' => $inaccessibleProject->id
            ]);

        // Should get a 404 or a redirect with validation error
        $response->assertStatus(302); // Redirects back with validation error
        $response->assertSessionHasErrors('project_id'); // Validation error for project_id

        // Verify the external user was not updated
        $this->assertDatabaseHas('external_users', [
            'id' => $inaccessibleExternalUser->id,
            'name' => 'Inaccessible External User',
            'email' => 'inaccessible@example.com'
        ]);
    }
}
