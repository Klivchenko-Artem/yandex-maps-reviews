<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $externalId = (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999);

        return [
            'user_id' => User::factory(),
            'source' => 'yandex',
            'external_id' => $externalId,
            'url' => "https://yandex.ru/maps/org/test/{$externalId}/",
            'name' => fake()->company(),
            'address' => fake()->address(),
            'rating' => 4.5,
            'ratings_count' => 100,
            'reviews_count' => 60,
            'last_synced_at' => now(),
        ];
    }
}
