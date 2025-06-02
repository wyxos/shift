<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\User;
use App\Notifications\OrganisationAccessNotification;
use App\Notifications\OrganisationInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrganisationUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_organisation_access_notification_is_sent_to_existing_user()
    {
        Notification::fake();

        // Create an organisation owned by the authenticated user
        $organisation = Organisation::factory()->create([
            'author_id' => $this->user->id
        ]);

        // Create another user who will be added to the organisation
        $existingUser = User::factory()->create();

        // Add the existing user to the organisation
        $response = $this->actingAs($this->user)
            ->post(route('organisation-users.store', $organisation), [
                'email' => $existingUser->email,
                'name' => $existingUser->name,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User invited to organisation successfully.');

        // Assert that the OrganisationAccessNotification was sent to the existing user
        Notification::assertSentTo(
            $existingUser,
            OrganisationAccessNotification::class
        );
    }

    public function test_organisation_invitation_notification_is_sent_to_new_user()
    {
        Notification::fake();

        // Create an organisation owned by the authenticated user
        $organisation = Organisation::factory()->create([
            'author_id' => $this->user->id
        ]);

        // New user email that doesn't exist in the system
        $newUserEmail = 'newuser@example.com';
        $newUserName = 'New User';

        // Invite a new user to the organisation
        $response = $this->actingAs($this->user)
            ->post(route('organisation-users.store', $organisation), [
                'email' => $newUserEmail,
                'name' => $newUserName,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'User invited to organisation successfully.');

        // For route-based notifications, we need to use a different approach
        Notification::assertSentOnDemand(
            OrganisationInvitationNotification::class
        );
    }
}
