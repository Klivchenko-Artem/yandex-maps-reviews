<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'external_id' => fake()->unique()->bothify('??????????####'),
            'author_name' => fake()->name(),
            'author_avatar_url' => null,
            'rating' => fake()->numberBetween(1, 5),
            'text' => fake()->sentence(12),
            'business_reply' => null,
            'published_at' => fake()->dateTimeBetween('-1 year'),
            'first_seen_at' => now(),
        ];
    }
}
