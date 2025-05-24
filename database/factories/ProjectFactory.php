<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->sentence(3),
            'client_id' => \App\Models\Client::factory(),
            'author_id' => null, // Nullable, can be set explicitly when needed
        ];
    }

    /**
     * Set the author ID for the project.
     *
     * @param int $userId
     * @return $this
     */
    public function withAuthor(int $userId): self
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'author_id' => $userId,
            ];
        });
    }
}
