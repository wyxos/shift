<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganisationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_accessible_organisations(): void
    {
        $user = User::factory()->create();

        Organisation::factory()->count(2)->create(['author_id' => $user->id]);

        $otherUser = User::factory()->create();
        $accessible = Organisation::factory()->create(['author_id' => $otherUser->id]);
        OrganisationUser::create([
            'organisation_id' => $accessible->id,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
        ]);

        Organisation::factory()->create();

        $response = $this->actingAs($user)->get(route('organisations.index'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Organisations/Index')
                ->has('organisations.data', 3)
            );
    }

    public function test_store_creates_new_organisation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('organisations.store'), [
            'name' => 'Test Organisation',
        ]);

        $response->assertRedirect(route('organisations.index'));
        $response->assertSessionHas('success', 'Organisation created successfully.');

        $this->assertDatabaseHas('organisations', [
            'name' => 'Test Organisation',
            'author_id' => $user->id,
        ]);
    }

    public function test_update_modifies_organisation(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create([
            'author_id' => $user->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($user)->put(route('organisations.update', $organisation), [
            'name' => 'Updated Name',
        ]);

        $response->assertRedirect(route('organisations.index'));
        $response->assertSessionHas('success', 'Organisation updated successfully.');

        $this->assertDatabaseHas('organisations', [
            'id' => $organisation->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_destroy_deletes_organisation(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::factory()->create([
            'author_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete(route('organisations.destroy', $organisation));

        $response->assertRedirect(route('organisations.index'));
        $response->assertSessionHas('success', 'Organisation deleted successfully.');

        $this->assertDatabaseMissing('organisations', [
            'id' => $organisation->id,
        ]);
    }
}
