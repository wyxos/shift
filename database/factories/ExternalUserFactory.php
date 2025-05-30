<?php

namespace Database\Factories;

use App\Models\ExternalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExternalUser>
 */
class ExternalUserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ExternalUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => $this->faker->uuid,
            'environment' => $this->faker->randomElement(['development', 'testing', 'production']),
            'url' => $this->faker->url,
            'name' => $this->faker->name,
            'email' => $this->faker->safeEmail,
        ];
    }
}
