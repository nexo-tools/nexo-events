<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'organizer_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'description' => fake()->paragraph(),
            'starts_at' => fake()->dateTimeBetween('+1 day', '+30 days'),
            'venue' => fake()->streetAddress(),
            'capacity' => null,
            'status' => EventStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => EventStatus::Draft]);
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(['capacity' => $capacity]);
    }
}
