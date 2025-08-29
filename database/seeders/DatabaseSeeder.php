<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1) Ensure a user exists
        $user = User::first();
        if (! $user) {
            $user = User::factory()->create([
                'name' => 'Shift Dev User',
                'email' => 'test@example.com',
            ]);
        }

        // 2) Create or update the SHIFT development project with static project token
        $SHIFT_PROJECT = 'zgc5QC5M1hGNmH7qbRSzEn29CBWfOtIPQT6pfM9FdUzfj0Ai6DmeGLcmGQ7s';
        $project = Project::updateOrCreate(
            ['token' => $SHIFT_PROJECT],
            [
                'name' => 'SHIFT Development Project',
                'author_id' => $user->id,
            ]
        );

        // 3) Ensure the user is associated with the project (ProjectUser)
        ProjectUser::firstOrCreate(
            [
                'project_id' => $project->id,
                'user_id' => $user->id,
            ],
            [
                'user_email' => $user->email,
                'user_name' => $user->name,
            ]
        );

        // 4) Seed Sanctum personal access token for the user using static SHIFT_TOKEN
        //    Format: "{id}|{plain-text-token}" from Sanctum
        $SHIFT_TOKEN = '1|SIw4KgAVyHHMlOuE0AE4AsxO7VTzl8AoKooklTYK641f594f';
        [$tokenId, $plain] = explode('|', $SHIFT_TOKEN, 2);
        $tokenId = (int) $tokenId;
        $hashed = hash('sha256', $plain);

        // Use DB here intentionally to enforce the specific token id + hash
        DB::table('personal_access_tokens')->updateOrInsert(
            ['id' => $tokenId],
            [
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
                'name' => 'shift-sdk',
                'token' => $hashed,
                'abilities' => json_encode(['*']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
